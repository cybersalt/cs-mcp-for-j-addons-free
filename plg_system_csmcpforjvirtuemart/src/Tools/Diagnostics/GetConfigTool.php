<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Read the shop configuration. READ ONLY, and permanently so.
 *
 * `VirtueMartModelConfig::store()` re-reads `virtuemart.cfg` from disk and
 * calls `setParams()` on the result (`models/config.php:413-420`), and
 * `setParams()` REPLACES the parameter set wholesale
 * (`helpers/config.php:610-627`). The effective outcome of any partial write is
 * therefore "shipped file defaults plus whatever you passed" — every setting
 * the caller did not name is reset. That is a wipe-the-whole-shop primitive and
 * there is no version of exposing it that is worth the risk.
 *
 * The safer path VirtueMart offers, `VmConfig::updateDbEntry()`
 * (`helpers/config.php:710-733`), skips the file merge but also skips
 * validation, cache clearing and language-table creation. It is a better tool
 * for a human at a console than for an agent over a wire.
 *
 * There is one more reason. `VmConfig::parseJsonUnSerialize()`
 * (`helpers/config.php:630-661`) hands any config value that fails
 * `json_decode` to `unserialize()`. A setter that accepted a string would turn
 * "can call an MCP tool" into "can execute PHP" on the next page load. Our
 * parser deliberately declines that fallback too.
 */
final class GetConfigTool extends AbstractTool
{
	use VirtuemartBootTrait;

	/** Keys whose values are shop secrets or paths worth flagging. */
	private const SENSITIVE_PREFIXES = ['forSale_path', 'media_', 'vmCrypt', 'secret'];

	public function getName(): string { return 'get_virtuemart_config'; }

	public function getDescription(): string
	{
		return 'Read and parse the VirtueMart shop configuration from #__virtuemart_configs. '
			. 'THIS IS READ-ONLY AND THERE IS DELIBERATELY NO SETTER. The reason is specific, not '
			. 'cautious: VirtueMartModelConfig::store() re-reads virtuemart.cfg from disk and calls '
			. 'setParams() on the result (models/config.php:413-420), and setParams() REPLACES the '
			. 'parameter set wholesale (helpers/config.php:610-627). The effective outcome of a partial '
			. 'write is therefore "shipped file defaults, plus whatever you passed" — every setting you '
			. 'did not name is reset to the shipped default. That is a reset-the-entire-shop primitive '
			. 'and it reports success. Change configuration in the VirtueMart admin. '
			. 'Two further reasons the setter stays absent. VirtueMart\'s pipe character is an UNESCAPED '
			. 'record separator (helpers/config.php:540-551), so a value containing a literal | corrupts '
			. 'every key after it, with no validation anywhere. And VmConfig::parseJsonUnSerialize() '
			. '(helpers/config.php:630-661) hands any value that fails json_decode to unserialize(), so a '
			. 'tool that accepted arbitrary config strings would be a PHP object-injection primitive. '
			. 'THIS TOOL\'S OWN PARSER DECLINES THAT FALLBACK: values that are not valid JSON come back '
			. 'as their raw string, flagged, and are never unserialised. '
			. 'The whole configuration is a single pipe-delimited blob of key=<json> pairs in row 1 of a '
			. 'text column (65,535 bytes, unchecked). Its size and remaining headroom are reported, '
			. 'because a shop that grows past the limit gets a silently truncated blob and a '
			. 'configuration that stops parsing at the truncation point. '
			. 'Two synthetic keys are injected at load time and are not real settings: sctime and vmlang '
			. '(helpers/config.php:491-492). They are filtered out. '
			. 'Pass keys to fetch specific settings, or search to filter by key name substring.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'keys'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Return only these configuration keys.'],
				'search' => ['type' => 'string', 'description' => 'Return only keys whose name contains this substring.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('configs')) {
			return $this->vmMissingTableError('configs');
		}

		$raw = $this->vmConfigRaw();

		if ($raw === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No row with virtuemart_config_id = 1 in ' . $this->vmTable('configs')
					. '. VirtueMart uses exactly that one row (models/config.php:530, :746) and falls '
					. 'back to parsing administrator/components/com_virtuemart/virtuemart.cfg when it is '
					. 'empty, then writes the result back. Its absence means the shop has never been '
					. 'configured.',
			], true);
		}

		$params = $this->vmConfigParams();

		// Synthetic, injected at load, not settings. Persisting them is junk.
		$synthetic = [];

		foreach (['sctime', 'vmlang'] as $key) {
			if (\array_key_exists($key, $params)) {
				$synthetic[$key] = $params[$key];
				unset($params[$key]);
			}
		}

		$requested = array_map('strval', (array) ($arguments['keys'] ?? []));
		$search    = strtolower(trim((string) ($arguments['search'] ?? '')));

		$filtered = [];

		foreach ($params as $key => $value) {
			if ($requested !== [] && !\in_array((string) $key, $requested, true)) {
				continue;
			}

			if ($search !== '' && !str_contains(strtolower((string) $key), $search)) {
				continue;
			}

			$filtered[$key] = $value;
		}

		$notFound = array_values(array_diff($requested, array_keys($params)));

		$bytes     = \strlen($raw);
		$unparsed  = [];
		$pipeRisks = [];

		foreach ($params as $key => $value) {
			if (\is_string($value) && $value !== '' && json_decode($value) === null && json_last_error() !== \JSON_ERROR_NONE) {
				$unparsed[] = (string) $key;
			}

			if (\is_string($value) && str_contains($value, '|')) {
				$pipeRisks[] = (string) $key;
			}
		}

		$response = [
			'ok'     => true,
			'config' => $filtered,
			'meta'   => [
				'key_count'          => \count($params),
				'blob_bytes'         => $bytes,
				'column_limit_bytes' => 65535,
				'headroom_bytes'     => 65535 - $bytes,
			],
			'read_only' => 'There is no setter for VirtueMart configuration in this add-on and there will '
				. 'not be one. VirtueMartModelConfig::store() merges the shipped virtuemart.cfg defaults '
				. 'over the live values before applying your changes (models/config.php:413-420 against '
				. 'helpers/config.php:610-627), so a partial write resets every setting it was not given. '
				. 'Change configuration in the VirtueMart admin.',
			'format_note' => 'The blob is pipe-delimited key=<json_encode(value)> pairs with NO trailing '
				. 'pipe (helpers/config.php:540-551). The pipe is an unescaped record separator, so a '
				. 'value containing a literal | corrupts every key after it. json_encode does not escape '
				. 'pipes and nothing validates this.',
		];

		if ($synthetic !== []) {
			$response['synthetic_keys_excluded'] = array_keys($synthetic);
			$response['synthetic_note'] = 'sctime and vmlang are injected at load time '
				. '(helpers/config.php:491-492) and are not settings. They are excluded here, and any '
				. 'round-trip that wrote them back would persist junk.';
		}

		if ($notFound !== []) {
			$response['keys_not_found'] = $notFound;
			$response['keys_not_found_note'] = 'These keys are absent from the stored blob. VirtueMart '
				. 'falls back to the default baked into each VmConfig::get() call site, so an absent key '
				. 'does not mean the setting is unset — it means the shop has never changed it.';
		}

		if ($unparsed !== []) {
			$response['unparseable_values'] = $unparsed;
			$response['unparseable_note'] = 'These values are not valid JSON. VirtueMart would hand them '
				. 'to unserialize() as a legacy fallback (helpers/config.php:630-661); this tool '
				. 'deliberately does NOT, and returns the raw string instead. That fallback is a PHP '
				. 'object-injection path for anyone who can write this row, which is why no setter is '
				. 'offered here.';
		}

		if ($pipeRisks !== []) {
			$response['pipe_risk_keys'] = $pipeRisks;
			$response['pipe_risk_note'] = 'These values contain a literal pipe character, which is '
				. 'VirtueMart\'s record separator. Depending on where they sit in the blob they may '
				. 'already be corrupting the keys that follow them.';
		}

		if ($bytes > 60000) {
			$response['size_warning'] = 'The configuration blob is ' . $bytes . ' bytes, close to the '
				. '65,535-byte limit of its `text` column. VirtueMart does not check this. Past the '
				. 'limit MySQL truncates silently and the configuration stops parsing from that point on.';
		}

		$sensitive = [];

		foreach (array_keys($filtered) as $key) {
			foreach (self::SENSITIVE_PREFIXES as $prefix) {
				if (str_starts_with((string) $key, $prefix)) {
					$sensitive[] = (string) $key;

					break;
				}
			}
		}

		if ($sensitive !== []) {
			$response['sensitive_keys'] = $sensitive;
			$response['sensitive_note'] = 'These keys hold filesystem paths or cryptographic settings. '
				. 'They are returned because they are diagnostic (a wrong forSale_path means invoice PDF '
				. 'generation fails silently from the mail path — models/invoice.php:381-407), but they '
				. 'describe the server, not the shop.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
