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

final class RemoveExemptIpsTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'remove_page_protector_exempt_ips'; }

	public function getDescription(): string
	{
		return 'Take IP addresses or CIDR ranges out of Page Protector\'s allow-list. Matches an entry however it was '
			. 'written (203.0.113.5/24 is 203.0.113.0/24). Only exact entries are removed; an address that is still '
			. 'inside a wider allow-listed range is reported as still exempt, and a range that has narrower entries '
			. 'inside it lists them, because those stay exempt. A line that held only removed entries is dropped with its '
			. 'comment; other lines are kept as written.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'entries' => ['type' => 'array', 'items' => ['type' => 'string']],
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
		$edit    = $this->ppIpRemove($current, array_map('strval', $entries));

		$out = [
			'removed'         => $edit['removed'],
			'not_found'       => $edit['not_found'],
			'still_exempt'    => $edit['still_covered'],
			'covers_narrower' => $edit['covers_narrower'],
		];

		if ($edit['removed'] === []) {
			return ToolResult::json(['ok' => true, 'saved' => false, 'reason' => 'no exact entry found'] + $out);
		}

		// Removing never adds a problem, so the remaining text is saved even if it already had one
		// (refusing would leave an entry exempt that the caller asked to remove). The response names
		// any such problem; the full Options validation still runs inside ppSaveSettings().
		$problems = $this->ppIpListProblems($edit['text']);
		$values   = $problems === []
			? $this->ppValidatePatch(['exempt_ips' => $edit['text']], $params)['values']
			: ['exempt_ips' => $edit['text']];

		$result = $this->ppSaveSettings($values);

		return ToolResult::json([
			'ok'                     => $result['saved'] && $result['verified'],
			'problems_left_in_list'  => $problems,
		] + $out + $result);
	}
}
