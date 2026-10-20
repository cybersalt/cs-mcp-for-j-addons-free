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

/**
 * Read-only health audit. The member/profile consistency checks are the same
 * queries CB's own Tools -> "Check Users Database" runs
 * (`administrator/components/com_comprofiler/controller/controller.default.php:992-1072`);
 * CB's fix for them is Tools -> "Synchronize Users", which this tool reports
 * but never runs.
 */
final class CheckHealthTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'check_cb_health'; }

	public function getDescription(): string
	{
		return 'Read-only health audit of a Community Builder install. Member consistency (the same checks as CB\'s '
			. 'own "Check Users Database" tool): Joomla users with no #__comprofiler row, CB rows with no Joomla '
			. 'user, id 0 rows, rows whose user_id differs from id. Field consistency: fields whose database '
			. 'columns are missing from #__comprofiler, columns no field owns, '
			. 'option rows pointing at deleted fields, fields on tabs that no longer exist, select/radio fields '
			. 'with no options, and members holding a value that is no longer one of the field\'s options. '
			. 'Install consistency: CB core plugins unpacked, the CB system plugin enabled, CB plugins whose '
			. 'folder is missing. Moderation backlog: members awaiting approval or confirmation for more than '
			. '7 days. Each finding has a severity and says how to fix it. Nothing is changed.';
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

		$db       = $this->db;
		$findings = [];
		$checks   = 0;

		$add = static function (string $severity, string $code, string $message, array $sample = [], ?int $count = null) use (&$findings): void {
			$f = ['severity' => $severity, 'code' => $code, 'message' => $message];

			if ($count !== null) {
				$f['count'] = $count;
			}

			if ($sample !== []) {
				$f['sample'] = \array_slice($sample, 0, 20);
			}

			$findings[] = $f;
		};

		$col = static fn (string $sql): array => $db->setQuery($sql)->loadColumn() ?: [];

		$u = $db->quoteName('#__users');
		$c = $db->quoteName('#__comprofiler');

		// --- Member consistency (CB's own checks).
		$checks++;
		$missing = $col('SELECT u.id FROM ' . $u . ' u LEFT JOIN ' . $c . ' c ON c.id = u.id WHERE c.id IS NULL');
		if ($missing !== []) {
			$add('warning', 'users_without_cb_profile', 'Joomla users with no #__comprofiler row (typically created outside CB). CB treats them as having empty profiles until their first CB save. Fix: CB -> Tools -> Synchronize Users.', $missing, \count($missing));
		}

		$checks++;
		$orphans = $col('SELECT c.id FROM ' . $c . ' c LEFT JOIN ' . $u . ' u ON u.id = c.id WHERE u.id IS NULL');
		if ($orphans !== []) {
			$add('warning', 'cb_profiles_without_user', '#__comprofiler rows whose Joomla user no longer exists. Fix: CB -> Tools -> Synchronize Users.', $orphans, \count($orphans));
		}

		$checks++;
		$zero = (int) $db->setQuery('SELECT (SELECT COUNT(*) FROM ' . $u . ' WHERE id = 0) + (SELECT COUNT(*) FROM ' . $c . ' WHERE id = 0)')->loadResult();
		if ($zero > 0) {
			$add('critical', 'id_zero_rows', 'Rows with id 0 in #__users or #__comprofiler, which CB does not allow. Fix: CB -> Tools -> Synchronize Users (it deletes them).', [], $zero);
		}

		$checks++;
		$mismatch = $col('SELECT c.id FROM ' . $c . ' c WHERE c.id <> c.user_id');
		if ($mismatch !== []) {
			$add('warning', 'user_id_differs_from_id', '#__comprofiler rows whose user_id differs from id. Fix: CB -> Tools -> Synchronize Users.', $mismatch, \count($mismatch));
		}

		// --- Field consistency.
		$fields  = $this->cbFieldRows();
		$columns = $this->cbProfileColumns();
		$tabs    = array_map('intval', $col('SELECT tabid FROM ' . $db->quoteName('#__comprofiler_tabs')));
		$owned   = [];

		$checks++;
		$missingCols = [];
		foreach ($fields as $f) {
			if ((string) $f['table'] !== '#__comprofiler') {
				continue;
			}

			foreach ($this->cbFieldColumns($f) as $colName) {
				$owned[] = $colName;

				if (!\in_array($colName, $columns, true)) {
					$missingCols[] = $f['name'] . ' (' . $colName . ')';
				}
			}
		}
		if ($missingCols !== []) {
			$add('critical', 'field_columns_missing', 'Fields whose database column is missing from #__comprofiler. Saving or displaying these fields fails. Fix: CB -> Tools -> "Check Community Builder User Fields Database", which offers the repair.', $missingCols, \count($missingCols));
		}

		$checks++;
		$coreColumns = ['id', 'user_id', 'alias', 'firstname', 'middlename', 'lastname', 'hits', 'message_last_sent', 'message_number_sent', 'avatar', 'avatarapproved', 'canvas', 'canvasapproved', 'canvasposition', 'approved', 'confirmed', 'lastupdatedate', 'registeripaddr', 'cbactivation', 'banned', 'banneddate', 'unbanneddate', 'bannedby', 'unbannedby', 'bannedreason', 'acceptedterms', 'acceptedtermsconsent'];
		$unowned = array_values(array_diff($columns, $owned, $coreColumns));
		if ($unowned !== []) {
			$add('info', 'columns_without_field', 'Columns on #__comprofiler that no CB field owns: typically added by a CB add-on, or left by a field row removed outside CB. (CB\'s own field delete DROPS the column with its data, so these are not normal leftovers of a deleted field.) A new field with the same name would adopt the column and its old data.', $unowned, \count($unowned));
		}

		$checks++;
		$orphanValues = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_field_values') . ' v LEFT JOIN '
			. $db->quoteName('#__comprofiler_fields') . ' f ON f.fieldid = v.fieldid WHERE f.fieldid IS NULL'
		)->loadResult();
		if ($orphanValues > 0) {
			$add('info', 'orphan_field_values', 'Option rows in #__comprofiler_field_values whose field no longer exists.', [], $orphanValues);
		}

		$checks++;
		$lostTab = [];
		foreach ($fields as $f) {
			if ((int) $f['tabid'] > 0 && !\in_array((int) $f['tabid'], $tabs, true)) {
				$lostTab[] = $f['name'] . ' (tab ' . $f['tabid'] . ')';
			}
		}
		if ($lostTab !== []) {
			$add('warning', 'fields_on_missing_tab', 'Fields assigned to a tab that does not exist: they appear nowhere and cannot be managed from the Fields screen. Fix: update_cb_field with a valid tab_id.', $lostTab, \count($lostTab));
		}

		$checks++;
		$noOptions = [];
		$staleValues = [];
		foreach ($fields as $f) {
			if (!\in_array((string) $f['type'], ['select', 'radio', 'multiselect', 'multicheckbox'], true)) {
				continue;
			}

			$options = array_map(static fn (array $r): string => (string) $r['fieldtitle'], $this->cbFieldValueRows((int) $f['fieldid']));

			if ($options === []) {
				$noOptions[] = $f['name'];
				continue;
			}

			$fieldCols = $this->cbFieldColumns($f);

			if ((string) $f['table'] !== '#__comprofiler' || $fieldCols === [] || !\in_array($fieldCols[0], $columns, true)) {
				continue;
			}

			$colQ = $db->quoteName($fieldCols[0]);
			$vals = $db->setQuery('SELECT ' . $colQ . ' AS v, COUNT(*) AS n FROM ' . $c . ' WHERE ' . $colQ . ' IS NOT NULL AND ' . $colQ . ' <> \'\' GROUP BY ' . $colQ, 0, 500)->loadAssocList() ?: [];

			foreach ($vals as $v) {
				foreach (explode(self::CB_MULTI_SEPARATOR, (string) $v['v']) as $part) {
					if ($part !== '' && !\in_array($part, $options, true)) {
						$staleValues[] = $f['name'] . ' = "' . mb_substr($part, 0, 60) . '" (' . (int) $v['n'] . ' member(s))';
					}
				}
			}
		}
		if ($noOptions !== []) {
			$add('warning', 'option_fields_without_options', 'Select / radio / checkbox fields with no options: members cannot answer them, and a required one blocks registration. Fix: set_cb_field_values.', $noOptions, \count($noOptions));
		}
		if ($staleValues !== []) {
			$add('info', 'values_not_in_options', 'Members holding a value that is no longer one of the field\'s options (an option was renamed or removed). CB shows the stored text but the member cannot re-select it.', array_values(array_unique($staleValues)), \count(array_unique($staleValues)));
		}

		// --- Install consistency.
		$checks++;
		if (!is_dir(JPATH_SITE . '/components/com_comprofiler/plugin/user/plug_cbcore')) {
			$add('critical', 'core_plugins_not_unpacked', 'CB core plugins are not unpacked (components/com_comprofiler/plugin/user/plug_cbcore is missing). Open Components -> Community Builder in the administrator to finish the second install step.');
		}

		$checks++;
		$sys = $db->setQuery(
			'SELECT enabled FROM ' . $db->quoteName('#__extensions') . ' WHERE type = \'plugin\' AND folder = \'system\' AND element = \'communitybuilder\''
		)->loadResult();
		if ($sys === null) {
			$add('critical', 'system_plugin_missing', 'plg_system_communitybuilder is not installed. Reinstall the CB package.');
		} elseif ((int) $sys !== 1) {
			$add('warning', 'system_plugin_disabled', 'plg_system_communitybuilder is disabled: Joomla\'s com_users pages are no longer rerouted to CB and CB rows are not removed when a Joomla user is deleted.');
		}

		$checks++;
		$missingPlugins = [];
		foreach ($db->setQuery('SELECT id, element, type, folder, published FROM ' . $db->quoteName('#__comprofiler_plugin'))->loadAssocList() ?: [] as $p) {
			$dir = JPATH_SITE . '/components/com_comprofiler/plugin/' . $p['type'] . '/' . $p['folder'];

			if ((string) $p['folder'] !== '' && !is_dir($dir)) {
				$missingPlugins[] = $p['element'] . ' (id ' . $p['id'] . ', ' . ((int) $p['published'] === 1 ? 'published' : 'unpublished') . ')';
			}
		}
		if ($missingPlugins !== []) {
			$add('warning', 'cb_plugin_files_missing', 'CB plugins registered in #__comprofiler_plugin whose folder is missing on disk.', $missingPlugins, \count($missingPlugins));
		}

		// --- Moderation backlog.
		$checks++;
		$cutoff = $db->quote(gmdate('Y-m-d H:i:s', time() - 7 * 86400));
		$oldApproval = $col('SELECT u.id FROM ' . $u . ' u JOIN ' . $c . ' c ON c.id = u.id WHERE c.approved = 0 AND u.registerDate < ' . $cutoff);
		if ($oldApproval !== []) {
			$add('warning', 'approval_backlog', 'Members awaiting admin approval for more than 7 days. See list_cb_pending_registrations.', $oldApproval, \count($oldApproval));
		}

		$checks++;
		$oldConfirm = $col('SELECT u.id FROM ' . $u . ' u JOIN ' . $c . ' c ON c.id = u.id WHERE c.confirmed = 0 AND u.registerDate < ' . $cutoff);
		if ($oldConfirm !== []) {
			$add('info', 'confirmation_backlog', 'Members who have not confirmed their email after more than 7 days (often a confirmation email that never arrived or had no link — run get_cb_registration_config).', $oldConfirm, \count($oldConfirm));
		}

		$order = ['critical' => 0, 'warning' => 1, 'info' => 2];
		usort($findings, static fn (array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);

		$summary = ['critical' => 0, 'warning' => 0, 'info' => 0];
		foreach ($findings as $f) {
			$summary[$f['severity']]++;
		}

		return ToolResult::json([
			'ok'             => true,
			'checks_run'     => $checks,
			'summary'        => $summary,
			'healthy'        => $summary['critical'] === 0 && $summary['warning'] === 0,
			'findings'       => $findings,
			'related'        => 'get_cb_registration_config audits the sign-up emails.',
		]);
	}
}
