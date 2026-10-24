<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;

final class AddExemptIpsTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'add_page_protector_exempt_ips'; }

	public function getDescription(): string
	{
		return 'Add IP addresses or CIDR ranges (IPv4 or IPv6) to Page Protector\'s allow-list: visitors from them skip '
			. 'the check. CIDR-aware: an entry an existing range already covers is skipped and named, and a new range '
			. 'that swallows narrower entries says so. Appended as new lines; the existing text and its # comments are '
			. 'kept as written. Refuses anything Page Protector could not parse (it would ignore it silently) and a '
			. 'comment containing a comma (Page Protector would read the text after the comma as another entry). '
			. 'The address Page Protector compares is set by ip_source: behind Cloudflare or a proxy, check that first.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'entries' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. ["203.0.113.7", "198.51.100.0/24", "2001:db8::/32"]'],
				'comment' => ['type' => 'string', 'description' => 'Optional note written after each new entry as "# note". One line, no commas.'],
			],
			'required'             => ['entries'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'options');

		$entries = $arguments['entries'] ?? null;

		if (!\is_array($entries) || $entries === []) {
			throw new \InvalidArgumentException('entries is required: an array of IP addresses or CIDR ranges.');
		}

		$params  = $this->ppStoredParams();
		$current = (string) $params->get('exempt_ips', '');
		$edit    = $this->ppIpAdd($current, array_map('strval', $entries), (string) ($arguments['comment'] ?? ''));

		$out = [
			'added'           => $edit['added'],
			'skipped'         => $edit['skipped'],
			'makes_redundant' => $edit['makes_redundant'],
			'ip_source'       => (string) $params->get('ip_source', 'remote_addr'),
		];

		if ($edit['added'] === []) {
			return ToolResult::json(['ok' => true, 'saved' => false, 'reason' => 'every entry is already covered'] + $out);
		}

		// The whole list is validated on save, so a problem already in the stored text would block the
		// add anyway. Say plainly that it is the existing text, not the new entries.
		$existingProblems = $this->ppIpListProblems($current);

		if ($existingProblems !== []) {
			throw new \InvalidArgumentException(
				'The stored allow-list already has entries Page Protector cannot use or that sit inside a comment: '
				. implode('; ', $existingProblems) . '. Fix the list with set_page_protector_settings (exempt_ips) first. Nothing was written.'
			);
		}

		$checked = $this->ppValidatePatch(['exempt_ips' => $edit['text']], $params);
		$result  = $this->ppSaveSettings($checked['values']);

		return ToolResult::json(['ok' => $result['saved'] && $result['verified']] + $out + $result);
	}
}
