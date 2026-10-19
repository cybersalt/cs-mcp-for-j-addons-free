<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckCssTrait;
use Joomla\CMS\User\User;

final class ListStylesTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckCssTrait;

	public function getName(): string { return 'list_maximenuck_styles'; }

	public function getDescription(): string
	{
		return 'Lists the Maximenu CK styles (#__maximenuck_styles) — the rows modules load their CSS from via '
			. 'their `styles` param. For each: id, name, state (only 0 and 1 emit CSS; a trashed style '
			. 'silently emits nothing), layoutcss and customcss sizes, whether the custom CSS is in step with '
			. 'the copy Maximenu bakes into the end of layoutcss, and which modules use it. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		if (!$this->mxTableExists('styles')) {
			return $this->mxMissingTableError('styles');
		}

		$usage = [];

		foreach ($this->mxModules() as $m) {
			$sid = (int) $this->mxModParam($m, 'styles', '0');

			if ($sid > 0) {
				$usage[$sid][] = ['module_id' => $m['id'], 'title' => $m['title'], 'published' => $m['published']];
			}
		}

		$rows = $this->db->setQuery(
			'SELECT id, name, state, layoutcss, customcss, checked_out FROM '
			. $this->db->quoteName('#__maximenuck_styles') . ' ORDER BY id'
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $r) {
			$custom = (string) $r['customcss'];
			$plan   = $this->mxPlanCustomCss((string) $r['layoutcss'], $custom, $custom, false);

			$out[] = [
				'id'              => (int) $r['id'],
				'name'            => (string) $r['name'],
				'state'           => (int) $r['state'],
				'emits_css'       => \in_array((int) $r['state'], [0, 1], true),
				'layoutcss_bytes' => \strlen((string) $r['layoutcss']),
				'customcss_bytes' => \strlen($custom),
				'custom_css_sync' => $plan['sync_state'],
				'checked_out'     => trim((string) $r['checked_out']) !== '' && trim((string) $r['checked_out']) !== '0'
					? (int) $r['checked_out'] : null,
				'used_by_modules' => $usage[(int) $r['id']] ?? [],
			];
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'styles' => $out]);
	}
}
