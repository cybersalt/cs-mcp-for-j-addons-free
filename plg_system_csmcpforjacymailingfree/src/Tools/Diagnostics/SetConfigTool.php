<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Write named AcyMailing settings through ConfigurationClass::saveConfig().
 *
 * TRUNCATE CHECK (done before building this): saveConfig() issues a single
 * `REPLACE INTO #__acym_configuration (name, value) VALUES ...` for exactly the
 * keys it was handed, and `name` is the PRIMARY KEY. There is no truncate and
 * no merge-and-rewrite, so driving the vendor method with a partial payload is
 * safe — unlike Events Booking and Membership Pro. We still snapshot every
 * other row and prove afterwards that nothing outside the named keys moved.
 *
 * WHAT IS REFUSED, AND WHY
 *  - Edition / licence state (`level`, `isTrial`, `expirationdate`, licence
 *    key, ...). acym_level() reads `level` from this table; writing it would
 *    unlock paid features without a licence. The gate is weak; that is not
 *    permission to walk through it.
 *  - Credentials (passwords, API/security/cron keys): never via MCP.
 *  - active_cron / cron_security / cron_key: saveConfig() reacts to these by
 *    calling acymailing.com (ConfigurationController::modifyCron).
 *  - Values the vendor itself only allows on a higher edition: a non-manual
 *    queue_type, multilingual, captcha and hiding "built with AcyMailing" need
 *    Essential; more than one batch per cron needs Enterprise. On Starter the
 *    vendor's own Configuration save even resets queue_type to manual
 *    (Controllers/Configuration/Listing.php::resetQueueProcess).
 *  - Keys that do not exist yet, unless create: true.
 */
final class SetConfigTool extends AbstractTool
{
	use AcymailingBootTrait;

	/** key => [minimum edition, predicate that makes the value a paid one] */
	private function editionGated(string $key, string $value): ?string
	{
		return match ($key) {
			'queue_type'       => $value !== 'manual' ? 'essential' : null,
			'multilingual'     => $value === '1' ? 'essential' : null,
			'captcha'          => $value !== '' && $value !== 'none' ? 'essential' : null,
			'display_built_by' => $value === '0' ? 'essential' : null,
			'queue_batch_auto' => (int) $value > 1 ? 'enterprise' : null,
			default            => null,
		};
	}

	public function getName(): string { return 'set_acym_config'; }

	public function getDescription(): string
	{
		return 'Change named AcyMailing settings via the vendor\'s ConfigurationClass::saveConfig() (a per-key '
			. 'REPLACE INTO - it does not truncate the table). Pass `values` as {name: value}. Refused: edition '
			. 'and licence state (writing `level` would unlock paid features without a licence), every '
			. 'credential, cron activation keys (saving them makes AcyMailing call acymailing.com), and values the '
			. 'vendor only allows on a higher edition (non-manual queue_type, multilingual, captcha, hiding the '
			. 'built-by footer need Essential; queue_batch_auto > 1 needs Enterprise). Unknown keys are refused '
			. 'unless create: true. The vendor strips HTML tags from every value. After writing, the tool re-reads '
			. 'the table and proves that no other setting changed. dry_run: true shows what would be written.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['values'],
			'properties' => [
				'values'  => ['type' => 'object', 'description' => 'Settings to write, {name: value}. Scalars only; arrays are joined with commas like the vendor does.', 'additionalProperties' => true],
				'create'  => ['type' => 'boolean', 'description' => 'Allow keys that do not exist yet. Default false.'],
				'dry_run' => ['type' => 'boolean', 'description' => 'Validate and show the diff without writing. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$values = $arguments['values'] ?? null;
		if (!\is_array($values) || $values === []) {
			return ToolResult::error('values must be a non-empty object of {name: value}.');
		}

		$create  = (bool) ($arguments['create'] ?? false);
		$before  = $this->acyConfigAll();
		$level   = $this->acyLevel();
		$refused = [];
		$write   = [];
		$diff    = [];

		foreach ($values as $key => $value) {
			$key = (string) $key;
			if (\is_array($value)) {
				$value = implode(',', array_map('strval', $value));
			} elseif (\is_bool($value)) {
				$value = $value ? '1' : '0';
			} elseif ($value === null) {
				$value = '';
			} elseif (!is_scalar($value)) {
				$refused[$key] = 'value must be a scalar';
				continue;
			}
			$value = (string) $value;

			if (\in_array($key, self::ACYM_CONFIG_WRITE_BLOCKED, true)) {
				$refused[$key] = 'edition, licence, cron or bookkeeping state - never written through MCP';
				continue;
			}
			if ($this->acyIsSecretName($key)) {
				$refused[$key] = 'credential-shaped key - never written through MCP';
				continue;
			}
			if (!array_key_exists($key, $before) && !$create) {
				$refused[$key] = 'no such setting; pass create: true if you are sure the component reads it';
				continue;
			}
			$need = $this->editionGated($key, $value);
			if ($need !== null && $this->acyLevelRank($level) < $this->acyLevelRank($need)) {
				$refused[$key] = 'this value is an AcyMailing ' . ucfirst($need) . ' capability and this site runs ' . ucfirst($level) . ' - refused, as the vendor\'s own Configuration screen would';
				continue;
			}

			$write[$key] = $value;
			$diff[$key]  = ['from' => $before[$key] ?? null, 'to' => $value];
		}

		if ($refused !== [] && $write === []) {
			return ToolResult::json(['ok' => false, 'written' => [], 'refused' => $refused]);
		}

		if (!empty($arguments['dry_run'])) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'would_write' => $diff, 'refused' => $refused]);
		}

		$ok = false;
		try {
			$ok = (bool) acym_config(true)->saveConfig($write);
		} catch (\Throwable $e) {
			return ToolResult::error('saveConfig() threw: ' . $e->getMessage());
		}
		$messages = $this->acyDrainMessages();

		$after     = $this->acyConfigAll();
		$collateral = [];
		foreach ($before as $k => $v) {
			if (!array_key_exists($k, $write) && ($after[$k] ?? null) !== $v && $k !== 'remindme') {
				$collateral[$k] = ['from' => $v, 'to' => $after[$k] ?? null];
			}
		}
		$stored = [];
		foreach ($write as $k => $v) {
			$stored[$k] = ['requested' => $v, 'stored' => $after[$k] ?? null, 'matches' => ($after[$k] ?? null) === $v];
		}

		return ToolResult::json([
			'ok'                   => $ok,
			'written'              => $stored,
			'refused'              => $refused,
			'other_rows_unchanged' => $collateral === [],
			'collateral_changes'   => $collateral,
			'vendor_messages'      => $messages,
			'notes'                => [
				'Written through ConfigurationClass::saveConfig() (REPLACE INTO, one row per key).',
				'A stored value that does not match the request was altered by the vendor\'s strip_tags.',
			],
		]);
	}
}
