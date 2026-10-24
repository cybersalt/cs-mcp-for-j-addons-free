<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;

final class ResetPassesTool extends AbstractTool
{
	use PageProtectorBootTrait;

	public function getName(): string { return 'reset_page_protector_passes'; }

	public function getDescription(): string
	{
		return 'Invalidate every visitor\'s pass at once, the dashboard\'s "Reset all passes" button: rotates the salt '
			. 'that signs pass cookies, so everyone without an exemption is checked again on their next protected page. '
			. 'Nothing else changes. Needs core.admin on Page Protector, like the button. Requires confirm: true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'confirm' => ['type' => 'boolean', 'description' => 'Must be true.'],
			],
			'required'             => ['confirm'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'admin');

		if (($arguments['confirm'] ?? false) !== true) {
			throw new \InvalidArgumentException('confirm must be true: this makes every visitor with a pass take the check again. Nothing was changed.');
		}

		// DashboardController::resetpasses() line for line, except that the params come from
		// #__extensions rather than ComponentHelper's possibly cached copy, so a stale cache can
		// never write an older version of the other settings back.
		$params  = $this->ppStoredParams();
		$before  = (string) $params->get('pass_salt', '');
		$params->set('pass_salt', bin2hex(random_bytes(16)));
		ProtectionHelper::saveParams($params);

		$after   = $this->ppStoredParams();
		$rotated = (string) $after->get('pass_salt', '') !== $before && (string) $after->get('pass_salt', '') !== '';

		// Every other key must be exactly as it was.
		$expected = $params->toArray();
		$lost     = array_keys(array_diff_key($expected, $after->toArray()));

		return ToolResult::json([
			'ok'      => $rotated && $lost === [],
			'rotated' => $rotated,
			'lost_keys' => $lost,
			'note'    => 'Every pass cookie issued before now is rejected. Visitors with an exemption (logged in, allow-listed IP, '
				. 'verified search engine) are not affected. Saved with Page Protector\'s own ProtectionHelper::saveParams(), '
				. 'which also cleans the _system cache in every cache folder, as the dashboard button does.',
		]);
	}
}
