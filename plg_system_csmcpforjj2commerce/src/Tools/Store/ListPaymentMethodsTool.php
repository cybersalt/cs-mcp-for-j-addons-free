<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Payment methods are Joomla plugins in the `j2commerce` group whose element
 * starts with `payment_` (`src/Model/PaymentmethodsModel.php:115-127`), and
 * their configuration — gateway credentials included — is plain-text plugin
 * params (`plg_j2commerce_payment_paypal/payment_paypal.xml:110-225`).
 *
 * Params are shown with a DENY pattern applied by key name, not an allow list,
 * so a third-party gateway's credentials are withheld even though we have never
 * seen its manifest. A withheld value is reported only as set or empty.
 */
final class ListPaymentMethodsTool extends AbstractTool
{
	use J2commerceBootTrait;

	private const SECRET_PATTERN = '/secret|pass|signature|key|token|client_id|user(name)?$|login|merchant|account|private|cert|hash|salt|webhook|api_/i';

	public function getName(): string { return 'list_j2commerce_payment_methods'; }

	public function getDescription(): string
	{
		return 'List J2Commerce payment methods. Each is a Joomla plugin in the j2commerce group with an element '
			. 'starting payment_ (bank transfer, cash, money order and PayPal ship with the core; gateways such as '
			. 'Stripe or Square are paid apps). Per method: extension id, element, enabled, ordering, display '
			. 'name, whether it is in SANDBOX/test mode, the order status it sets on payment, any surcharge, '
			. 'geozone and subtotal restrictions, and its remaining settings. '
			. 'GATEWAY CREDENTIALS ARE WITHHELD: any setting whose name looks like a secret, password, '
			. 'signature, key, token, client id, username, merchant or account id, certificate or webhook id is '
			. 'reported only as set or empty — including on gateways this add-on has never seen. J2Commerce '
			. 'stores them as plain text in the plugin params. '
			. 'Flags an enabled method left in sandbox mode, and the store\'s default_payment_method when it is '
			. 'not an enabled method. Enable or disable a method with core set_extension_enabled. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$db   = $this->db;
		$rows = $db->setQuery(
			'SELECT extension_id, element, enabled, ordering, params, manifest_cache FROM ' . $db->quoteName('#__extensions')
			. ' WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('j2commerce')
			. ' AND element LIKE ' . $db->quote('payment\_%') . ' ORDER BY ordering, element'
		)->loadAssocList() ?: [];

		$statuses = $this->j2StatusMap();
		$warnings = [];
		$out      = [];

		foreach ($rows as $r) {
			$params   = json_decode((string) $r['params'], true) ?: [];
			$manifest = json_decode((string) $r['manifest_cache'], true) ?: [];
			$shown    = [];
			$withheld = [];

			foreach ($params as $key => $value) {
				if (preg_match(self::SECRET_PATTERN, (string) $key) === 1) {
					$withheld[$key] = (\is_scalar($value) && (string) $value !== '') || (\is_array($value) && $value !== []) ? 'set' : 'empty';
				} else {
					$shown[$key] = $value;
				}
			}

			$sandbox = (string) ($params['sandbox'] ?? '0') === '1';
			$status  = (int) ($params['payment_status'] ?? 0);

			$entry = [
				'extension_id'   => (int) $r['extension_id'],
				'element'        => (string) $r['element'],
				'version'        => (string) ($manifest['version'] ?? ''),
				'enabled'        => (int) $r['enabled'] === 1,
				'ordering'       => (int) $r['ordering'],
				'display_name'   => (string) ($params['display_name'] ?? ''),
				'sandbox'        => $sandbox,
				'sets_status'    => $status > 0 ? ['id' => $status, 'name' => $statuses[$status]['label'] ?? null] : null,
				'settings'       => $shown,
				'credentials'    => $withheld,
			];

			if ((int) $r['enabled'] === 1 && $sandbox) {
				$warnings[] = $r['element'] . ' is enabled in SANDBOX mode: real customers cannot pay through it.';
			}

			if ($status > 0 && !isset($statuses[$status])) {
				$warnings[] = $r['element'] . ' sets order status ' . $status . ' on payment, which does not exist.';
			}

			$out[] = $entry;
		}

		$default = (string) ($this->j2RawParams()['default_payment_method'] ?? '');
		$enabled = array_column(array_filter($out, static fn (array $m): bool => $m['enabled']), 'element');

		if ($default !== '' && !\in_array($default, $enabled, true)) {
			$warnings[] = 'The store\'s default_payment_method is "' . $default . '", which is not an enabled payment method.';
		}

		if ($enabled === []) {
			$warnings[] = 'No payment method is enabled, so checkout cannot complete.';
		}

		$response = ['ok' => true, 'count' => \count($out), 'payment_methods' => $out];

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}
}
