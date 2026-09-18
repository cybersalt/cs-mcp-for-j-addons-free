<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;
use Joomla\CMS\Version;

/**
 * Orientation: what ATS is this, which edition, and what is therefore locked.
 *
 * THE VERSION IS READ FROM version.php, NOT FROM #__extensions.
 * -------------------------------------------------------------
 * This is the opposite of the habit every other add-on in this family follows,
 * and it is deliberate. ATS defines its own version in
 * `administrator/components/com_ats/version.php` as three constants, and
 * Dispatcher::loadVersion() (Dispatcher.php:236-259) includes that file on
 * every request; ATSBootTrait replicates it so ATS_VERSION and ATS_PRO are
 * defined for us too. Every edition check inside the component reads the
 * CONSTANT, never the manifest — roughly thirty-five `defined('ATS_PRO') &&
 * ATS_PRO` sites across the tables, models and Permissions. So the constant is
 * what the running code believes, and that is what this tool reports as
 * authoritative.
 *
 * It matters because the two disagree on a shipped build. On the reference
 * install (mcpfree.basicjoomla.com) `version.php` says 5.6.0 while the
 * component's own manifest, `administrator/components/com_ats/ats.xml:14`,
 * still says 5.5.2 — the manifest was not bumped in that build, and
 * #__extensions.manifest_cache for com_ats is a copy of that manifest, so
 * Joomla's Manage screen shows 5.5.2 for a 5.6.0 component. The package
 * manifest, administrator/manifests/packages/pkg_ats.xml:13, correctly says
 * 5.6.0. Both numbers are reported here with the discrepancy spelled out,
 * because the cheap conclusion ("the site is out of date, update it") is wrong
 * and leads to a pointless reinstall.
 *
 * THE EDITION LIST IS ABOUT CAPABILITY, NOT ABOUT TABLES.
 * -------------------------------------------------------
 * ATS' installer creates every table unconditionally, with no edition
 * branching, so #__ats_attachments, #__ats_cannedreplies, #__ats_autoreplies
 * and #__ats_managernotes all EXIST on a Core install. They are simply never
 * written. "The table is there" is therefore not evidence of anything, and a
 * tool that wrote into them on Core would produce rows the interface can never
 * show. The Pro-only list below is the vendor's gate restated, not our own.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use ATSBootTrait;

	/** Tables worth an exact count, with what an unexpected number means. */
	private const COUNTED = [
		'ats_tickets'       => 'Tickets.',
		'ats_posts'         => 'Replies, including each ticket\'s opening message.',
		'ats_tickets_users' => 'Invited collaborators. A CORE feature, so a non-zero count here is normal.',
		'ats_attachments'   => 'Pro only. Non-zero on a Core install means this site used to run '
			. 'Professional; the files and rows survived the downgrade but ATS will not show them.',
		'ats_cannedreplies' => 'Pro only. Non-zero on Core means the same thing.',
		'ats_autoreplies'   => 'Pro only. Non-zero on Core means the same thing.',
	];

	/**
	 * What ATS Professional adds. Each entry is the capability, then what
	 * actually happens on Core.
	 *
	 * @var array<string, string>
	 */
	private const PRO_FEATURES = [
		'Attachments' => 'Uploading and downloading files on a post. Permissions::getTicketPrivileges() '
			. 'hard-sets the `attachment` privilege to false when ATS_PRO is falsy (Permissions.php:683-686, '
			. 'and again at 822-825 in getPostPrivileges()), so nobody has it on Core regardless of ACL. '
			. '#__ats_attachments exists and stays '
			. 'empty, and #__ats_posts.attachment_id stays at its default "0".',
		'Canned replies' => 'Reusable reply templates offered when answering a ticket. #__ats_cannedreplies '
			. 'exists and is never written on Core. (Note its `access` column defaults to 0, which is not a '
			. 'valid Joomla view level — check_ats_health reports rows in that state.)',
		'Auto-replies' => 'Keyword-triggered automatic responses. #__ats_autoreplies exists and is never '
			. 'written on Core.',
		'Manager notes' => 'Private staff-only commentary on a ticket. TicketTable::managerNotes() '
			. '(TicketTable.php:458-463) returns an empty array on the edition check BEFORE it builds a '
			. 'query, so notes are invisible on '
			. 'Core — but a site downgraded from Professional KEEPS every note it ever wrote. This add-on '
			. 'never reads or lists #__ats_managernotes for that reason; check_ats_health reports only '
			. 'whether it is non-empty, as a count, never content.',
		'User tags' => 'Per-user labels for segmenting customers. Entirely Pro, and NOT the same thing as '
			. 'ticket tags — those are Joomla-native (#__contentitem_tag_map with type_alias '
			. '"com_ats.ticket") and work on Core.',
		'Timecard' => 'The billable-time report built on the timespent columns. The columns themselves and '
			. 'the per-post time logging are Core; only the reporting screen is Pro.',
		'Log' => 'The ticket audit log screen.',
		'Scheduling / CRON' => 'The CLI commands and scheduled tasks. Pro ships src/CliCommand — its '
			. 'absence is exactly what ATS\' own dev-build fallback tests for when version.php is missing. '
			. 'The `secret`, `time_limit` and `accurate_php_cli` parameters in the component\'s Automation '
			. 'tab exist on Core but have nothing to drive.',
		'Email template manager UI' => 'The screen for editing ATS\' notification templates. The templates '
			. 'themselves are Joomla mail templates and still send on Core; you just cannot edit them from '
			. 'ATS\' own interface.',
		'Mail gateway' => 'Creating tickets and replies from inbound email. #__ats_posts.email_uid is the '
			. 'gateway\'s dedupe key and is therefore always NULL on Core, and #__ats_tickets.origin never '
			. 'holds "email" on a site that has only ever run Core.',
		'JSON:API webservices plugin' => 'The plg_webservices_ats plugin that exposes tickets over Joomla\'s '
			. 'JSON:API. Not shipped with Core.',
		'onAts* plugin events' => 'onAtsTicketSave, onAtsTicketDelete, onAtsPostSave, onAtsPostDelete, '
			. 'onAtsAttachmentSave and onAtsAttachmentDelete — the webhook/integration hooks. Every trigger '
			. 'site is wrapped in `if (defined(\'ATS_PRO\') && ATS_PRO)` (TicketTable.php:692-696, '
			. 'PostTable.php:357-361), so on Core they never fire at all and an ats-group plugin is inert.',
	];

	public function getName(): string { return 'get_ats_component_info'; }

	public function getDescription(): string
	{
		return 'Report what Akeeba Ticket System install this is: version, edition (Core vs Professional), '
			. 'which Pro-only features are therefore unavailable, exact row counts for every ATS table, the '
			. 'ticket-category inventory, and the Joomla/PHP versions. The first call to make on an '
			. 'unfamiliar site. '
			. 'THE VERSION IS READ FROM THE ATS_VERSION CONSTANT (administrator/components/com_ats/'
			. 'version.php), not from #__extensions.manifest_cache — because that is what the running code '
			. 'reads, and the two can disagree. On a shipped 5.6.0 build the component\'s own manifest '
			. '(ats.xml) was not bumped and still says 5.5.2, so Joomla\'s Manage screen and '
			. 'manifest_cache both show 5.5.2 for a 5.6.0 component while the package manifest (pkg_ats.xml) '
			. 'correctly says 5.6.0. All three numbers are returned and version_discrepancy explains which '
			. 'to trust: the CONSTANT. Do not conclude the site needs updating from the manifest number '
			. 'alone. '
			. 'EDITION is the ATS_PRO constant, also from version.php, where it is the STRING "0" or "1" — '
			. 'PHP treats "0" as falsy, which is what makes the vendor\'s own `defined(\'ATS_PRO\') && '
			. 'ATS_PRO` idiom work. When version.php is absent (dev builds) ATS falls back to testing for '
			. 'the src/CliCommand directory, and this tool reproduces both paths rather than picking one. '
			. 'CORE LOCKS OUT: attachments, canned replies, auto-replies, manager notes, user tags, the '
			. 'Timecard, the Log, scheduling/CRON, the email-template manager UI, the mail gateway, the '
			. 'JSON:API webservices plugin, and every onAts* plugin event. Each is listed with what actually '
			. 'happens on Core. NOTE THAT THE PRO TABLES STILL EXIST on a Core install — ATS creates them '
			. 'unconditionally — so a non-zero count in one of them means the site was downgraded from '
			. 'Professional and is still holding data the interface will never display. '
			. 'Read-only. Use check_ats_health for the audit, get_ats_config for the settings.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$isPro     = $this->atsIsPro();
		$version   = $this->atsVersion();
		$adminBase = $this->atsAdminBase();

		// ---- the three version numbers ---------------------------------------
		$componentManifest = $this->manifestVersion('component', 'com_ats');
		$packageManifest   = $this->manifestVersion('package', 'pkg_ats');

		$versions = [
			'authoritative' => $version,
			'source'        => 'The ATS_VERSION constant from administrator/components/com_ats/version.php, '
				. 'defined the way Dispatcher::loadVersion() defines it. This is what the running component '
				. 'believes it is.',
			'ats_date'      => \defined('ATS_DATE') ? (string) ATS_DATE : null,
			'component_manifest' => [
				'version' => $componentManifest['version'],
				'element' => 'com_ats',
				'source'  => '#__extensions.manifest_cache for the com_ats component, which Joomla copies '
					. 'from administrator/components/com_ats/ats.xml at install time.',
				'enabled' => $componentManifest['enabled'],
				'extension_id' => $componentManifest['extension_id'],
			],
			'package_manifest' => [
				'version' => $packageManifest['version'],
				'element' => 'pkg_ats',
				'source'  => '#__extensions.manifest_cache for the pkg_ats package, from '
					. 'administrator/manifests/packages/pkg_ats.xml.',
				'enabled' => $packageManifest['enabled'],
				'extension_id' => $packageManifest['extension_id'],
			],
		];

		if ($version !== null
			&& $componentManifest['version'] !== null
			&& $componentManifest['version'] !== $version) {
			$versions['version_discrepancy'] = [
				'detected' => true,
				'constant' => $version,
				'component_manifest' => $componentManifest['version'],
				'package_manifest'   => $packageManifest['version'],
				'trust'    => 'the constant (' . $version . ')',
				'explanation' => 'version.php says ' . $version . ' but the com_ats component manifest says '
					. $componentManifest['version'] . '. This is a VENDOR PACKAGING OVERSIGHT, not a broken '
					. 'or half-applied update: the component manifest was not bumped in that build, and '
					. 'Joomla\'s manifest_cache is a verbatim copy of it. Every edition and feature check '
					. 'inside ATS reads the CONSTANT, so the constant is the version that is actually '
					. 'running. Joomla\'s Manage screen, and any tool reading manifest_cache, will show the '
					. 'stale number'
					. ($packageManifest['version'] !== null
						? ' — note the package manifest says ' . $packageManifest['version'] . ', which '
							. 'agrees with the constant.'
						: '.')
					. ' Reinstalling to "fix" the number changes nothing but the number.',
			];
		} else {
			$versions['version_discrepancy'] = [
				'detected' => false,
				'note'     => 'The constant and the component manifest agree. Worth re-checking after any '
					. 'ATS update: a build where they disagree is a vendor packaging slip, not a site fault.',
			];
		}

		// ---- edition ----------------------------------------------------------
		$hasCliCommand = $adminBase !== null && @is_dir($adminBase . '/src/CliCommand');
		$hasVersionFile = $adminBase !== null && @is_file($adminBase . '/version.php');

		$edition = [
			'is_pro'  => $isPro,
			'name'    => $isPro ? 'Akeeba Ticket System Professional' : 'Akeeba Ticket System Core (free)',
			'ats_pro_constant' => \defined('ATS_PRO') ? (string) ATS_PRO : null,
			'how_determined' => $hasVersionFile
				? 'version.php defines ATS_PRO directly, as the STRING "0" or "1". PHP treats the string '
					. '"0" as falsy, which is what makes ATS\' own `defined(\'ATS_PRO\') && ATS_PRO` checks '
					. 'work everywhere in the component.'
				: 'version.php is absent (a development build), so ATS\' fallback applies: ATS_PRO is 1 if '
					. 'administrator/components/com_ats/src/CliCommand exists and 0 if it does not. This '
					. 'add-on reproduces the vendor\'s fallback exactly rather than guessing.',
			'cli_command_directory_present' => $hasCliCommand,
			'version_php_present' => $hasVersionFile,
			'note' => 'The edition constant is normally defined by Dispatcher::loadVersion(), which only '
				. 'runs when com_ats dispatches an HTTP request through its own entry point. An MCP call '
				. 'never does that, so this add-on defines the constants itself the same way — otherwise '
				. 'ATS_PRO would simply be absent and every check would read a Pro site as Core.',
		];

		// ---- what Core locks out ---------------------------------------------
		$proFeatures = [];

		foreach (self::PRO_FEATURES as $name => $meaning) {
			$proFeatures[] = [
				'feature'   => $name,
				'available' => $isPro,
				'on_core'   => $meaning,
			];
		}

		// ---- tables -----------------------------------------------------------
		$prefix    = $this->db->getPrefix();
		$tableList = $this->tableList();
		$tables    = [];
		$leftovers = [];

		foreach (self::COUNTED as $suffix => $purpose) {
			$full   = $prefix . $suffix;
			$exists = \in_array($full, $tableList, true);
			$rows   = $exists ? $this->countRows($full) : null;

			$entry = [
				'table'   => $suffix,
				'full_name' => $full,
				'exists'  => $exists,
				'rows'    => $rows,
				'purpose' => $purpose,
			];

			if (!$isPro
				&& $rows !== null
				&& $rows > 0
				&& \in_array($suffix, ['ats_attachments', 'ats_cannedreplies', 'ats_autoreplies'], true)) {
				$entry['downgrade_evidence'] = true;
				$leftovers[] = $suffix . ' (' . $rows . ' rows)';
			}

			$tables[] = $entry;
		}

		// ---- categories -------------------------------------------------------
		$categories = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('c.id'),
					$this->db->quoteName('c.title'),
					$this->db->quoteName('c.published'),
					$this->db->quoteName('c.access'),
					$this->db->quoteName('c.language'),
					'(SELECT COUNT(*) FROM ' . $this->db->quoteName($prefix . 'ats_tickets', 't')
						. ' WHERE ' . $this->db->quoteName('t.catid') . ' = ' . $this->db->quoteName('c.id')
						. ') AS ' . $this->db->quoteName('ticket_count'),
				])
				->from($this->db->quoteName('#__categories', 'c'))
				->where($this->db->quoteName('c.extension') . ' = ' . $this->db->quote('com_ats'))
				->order($this->db->quoteName('c.lft') . ' ASC')
		)->loadAssocList() ?: [];

		foreach ($categories as &$cat) {
			foreach (['id', 'published', 'access', 'ticket_count'] as $k) {
				$cat[$k] = (int) $cat[$k];
			}
		}
		unset($cat);

		$result = [
			'ok'        => true,
			'installed' => true,
			'component' => [
				'element' => 'com_ats',
				'name'    => 'Akeeba Ticket System',
				'vendor'  => 'Nicholas K. Dionysopoulos / Akeeba Ltd',
				'licence' => 'GNU GPL v3 or later',
				'admin_path' => $adminBase,
				'architecture' => 'Modern namespaced Joomla MVC (Akeeba\\Component\\ATS) with a real service '
					. 'provider, MVCFactory and AdminModels. It also ships its OWN Composer vendor tree, '
					. 'required from services/provider.php — which is why anything touching ATS must boot '
					. 'the component first or HTMLPurifier will be missing from the save path.',
			],
			'versions' => $versions,
			'edition'  => $edition,
			'site' => [
				'joomla_version' => (new Version())->getShortVersion(),
				'php_version'    => PHP_VERSION,
				'db_prefix'      => $prefix,
			],
			'pro_only_features' => $proFeatures,
			'tables'     => $tables,
			'categories' => [
				'count' => \count($categories),
				'rows'  => $categories,
				'note'  => 'Ticket categories are Joomla categories with extension = "com_ats". A ticket '
					. 'inherits its access level, language and published state FROM ITS CATEGORY — '
					. '#__ats_tickets has no access and no language column of its own, so neither can be set '
					. 'on a ticket. #__ats_tickets.catid is a bare bigint with no foreign key, so every join '
					. 'to #__categories must also require extension = "com_ats".',
			],
		];

		if ($leftovers !== []) {
			$result['downgraded_from_pro'] = [
				'detected' => true,
				'evidence' => $leftovers,
				'meaning'  => 'This site is running Core but its Pro-only tables are not empty, which means '
					. 'it ran Akeeba Ticket System Professional at some point. A Pro-to-Core downgrade '
					. 'removes code, not data. The rows are still there and the interface will never show '
					. 'them. #__ats_managernotes is the one that matters — it holds private staff-only '
					. 'commentary — and this add-on deliberately will not read or list it; check_ats_health '
					. 'reports only whether it is non-empty, as a count.',
			];
		}

		$result['start_here'] = [
			'health'   => 'check_ats_health — orphaned categories, orphaned invitations, duplicate '
				. 'invitations, postless tickets, an unset siteurl and the Pro-downgrade leftovers.',
			'config'   => 'get_ats_config — the component parameters, annotated, with the secret ones '
				. 'reported as set/unset rather than returned.',
			'triage'   => 'list_ats_stale_tickets — what has gone quiet and on whose side.',
			'reports'  => 'get_ats_ticket_summary and get_ats_agent_workload.',
			'escape_hatch' => 'list_ats_tables then query_ats_table for anything the purpose-built tools do '
				. 'not cover.',
		];

		return ToolResult::json($result);
	}

	/**
	 * Version, enabled state and id for one row of #__extensions.
	 *
	 * @return array{version: string|null, enabled: bool|null, extension_id: int|null}
	 */
	private function manifestVersion(string $type, string $element): array
	{
		try {
			$row = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName(['extension_id', 'enabled', 'manifest_cache']))
					->from($this->db->quoteName('#__extensions'))
					->where($this->db->quoteName('type') . ' = ' . $this->db->quote($type))
					->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
			)->loadAssoc();
		} catch (\Throwable) {
			$row = null;
		}

		if ($row === null) {
			return ['version' => null, 'enabled' => null, 'extension_id' => null];
		}

		$manifest = json_decode((string) ($row['manifest_cache'] ?? ''), true);

		return [
			'version' => \is_array($manifest) && isset($manifest['version'])
				? (string) $manifest['version']
				: null,
			'enabled' => (int) $row['enabled'] === 1,
			'extension_id' => (int) $row['extension_id'],
		];
	}

	/** @return array<int, string> */
	private function tableList(): array
	{
		try {
			return $this->db->getTableList() ?: [];
		} catch (\Throwable) {
			return [];
		}
	}

	private function countRows(string $table): ?int
	{
		try {
			return (int) $this->db->setQuery(
				$this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($table))
			)->loadResult();
		} catch (\Throwable) {
			return null;
		}
	}
}
