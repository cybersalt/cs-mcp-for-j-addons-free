<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListTablesTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'list_cb_tables'; }

	public function getDescription(): string
	{
		return 'List the Community Builder tables on this site with row counts, and say which ones query_cb_table '
			. 'will read. CB core creates 11 tables (#__comprofiler and #__comprofiler_*: fields, field_values, tabs, '
			. 'lists, members = connections, plugin, userreports, views, sessions, ratings); the core CB Blogs and '
			. 'private-message plugins add three more. Refused by the reader: sessions (session data and IPs) and '
			. 'the private-message tables. Tables created by CB add-ons (GroupJive, CB Paid Subscriptions, CB '
			. 'Gallery...) are listed separately and are out of scope. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.tools');

		$prefix  = $this->db->getPrefix();
		$known   = array_merge(self::CB_CORE_TABLES, self::CB_CORE_PLUGIN_TABLES);
		$tables  = [];
		$addons  = [];
		$present = [];

		foreach ($this->cbAllTables() as $name) {
			if (!str_starts_with($name, $prefix . 'comprofiler')) {
				continue;
			}

			$short = substr($name, \strlen($prefix));

			if (!\in_array($short, $known, true)) {
				$addons[] = '#__' . $short;
				continue;
			}

			$rows = null;

			try {
				$rows = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName($name))->loadResult();
			} catch (\Throwable $e) {
				// leave null
			}

			$entry = [
				'table'    => '#__' . $short,
				'rows'     => $rows,
				'origin'   => \in_array($short, self::CB_CORE_TABLES, true) ? 'cb_core' : 'cb_core_plugin',
				'readable' => !isset(self::CB_READ_DENYLIST[$short]),
			];

			if (isset(self::CB_READ_DENYLIST[$short])) {
				$entry['why_not'] = self::CB_READ_DENYLIST[$short];
			}

			if (isset(self::CB_REDACTED_COLUMNS[$short])) {
				$entry['redacted_columns'] = self::CB_REDACTED_COLUMNS[$short];
			}

			$tables[]  = $entry;
			$present[] = $short;
		}

		$response = [
			'ok'     => true,
			'count'  => \count($tables),
			'tables' => $tables,
		];

		$missing = array_values(array_diff(self::CB_CORE_TABLES, $present));

		if ($missing !== []) {
			$response['missing_core_tables'] = array_map(static fn (string $t): string => '#__' . $t, $missing);
		}

		if ($addons !== []) {
			$response['other_comprofiler_tables'] = $addons;
			$response['other_note'] = 'Created by CB add-ons, not by CB core. Not readable through these tools.';
		}

		return ToolResult::json($response);
	}
}
