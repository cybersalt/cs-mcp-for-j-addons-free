<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

/**
 * Correctness rather than security — the counterpart to audit_jce_profiles.
 *
 * Every check here is for a state that JCE tolerates without complaint and that
 * produces a wrong-looking editor with no error anywhere. They are the failure
 * modes that cost an afternoon:
 *
 *   - no published profile at all → no editor for anyone, silently
 *   - a profile with no audience → published, listed, matches nobody
 *   - an unknown token in `rows` → missing button, dropped at render time
 *   - a button whose plugin is not enabled → renders nothing
 *   - the `Default` profile renamed or missing → JCE plugin install hooks
 *     silently stop registering new buttons
 *   - duplicate `ordering` values → non-deterministic precedence
 *   - encrypted params → invisible configuration
 *   - `plg_editors_jce` disabled → JCE installed but unusable
 */
final class CheckHealthTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'check_jce_health'; }

	public function getDescription(): string
	{
		return 'Check a JCE installation for configuration states that produce a broken or wrong-looking '
			. 'editor WITHOUT producing any error, warning or log entry. This is the correctness '
			. 'counterpart to audit_jce_profiles, which covers security. '
			. 'Checks: whether any profile is published at all (with none, JCE renders no editor for '
			. 'anyone in either the site or the administrator, silently); profiles with both `types` and '
			. '`users` empty, which JCE skips during matching (application.php:394) while they still '
			. 'appear published; unknown tokens in `rows`, which getRows() drops at render time '
			. '(models/profile.php:294-301) leaving a missing button and no diagnostic; buttons in '
			. '`rows` whose plugin is not in `plugins`, which render nothing; the presence of a profile '
			. 'named exactly "Default", which JCE\'s plugin install and uninstall hooks target by name '
			. '(helpers/plugins.php:408) and which silently stop working if it is renamed or removed; '
			. 'duplicate `ordering` values, which make precedence depend on undefined database row '
			. 'order; profiles whose params are encrypted and therefore uninspectable; profiles '
			. 'referencing Pro-only plugin names on a site without Pro, which are inert; the enabled '
			. 'state of plg_editors_jce, without which JCE is installed but cannot be selected as the '
			. 'editor; and the version against the CVE-2026-48907 fix line. '
			. 'Each finding says what the user-visible symptom is, not just what the data looks like. '
			. 'Read-only. Nothing is repaired — JCE\'s own control panel has a Repair action, and it '
			. 'force-unpublishes every profile as a side effect (helpers/profiles.php:447-449), which is '
			. 'not something this tool is going to do on your behalf.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		if (!$this->jceTableExists()) {
			return $this->jceMissingTableError();
		}

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($this->jceTable()))
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows    = $this->db->setQuery($query)->loadAssocList() ?: [];
		$catalog = $this->jcePluginCatalog();
		$isPro   = $this->jceIsPro();

		$problems = [];
		$notices  = [];

		// ---- site-wide --------------------------------------------------
		if (!PluginHelper::isEnabled('editors', 'jce')) {
			$problems[] = [
				'severity' => 'error',
				'code'     => 'editor_plugin_disabled',
				'detail'   => 'plg_editors_jce is not enabled. JCE is installed but cannot be selected '
					. 'as the site or per-user editor, so none of the profile configuration on this '
					. 'site has any effect. Enable it in the Joomla plugin manager.',
			];
		}

		$published = array_values(array_filter($rows, static fn ($r) => (int) $r['published'] === 1));

		if ($rows === []) {
			$problems[] = [
				'severity' => 'error',
				'code'     => 'no_profiles',
				'detail'   => '#__wf_profiles is empty. JCE seeds five profiles from '
					. 'administrator/components/com_jce/models/profiles.xml at install time, but ONLY '
					. 'when the table has zero rows (helpers/profiles.php:83-113). With no profiles, no '
					. 'editor is rendered for anybody. The JCE control panel has a Repair action that '
					. 'reseeds — note it force-unpublishes everything and then re-publishes only '
					. '"Default".',
			];
		} elseif ($published === []) {
			$problems[] = [
				'severity' => 'error',
				'code'     => 'no_published_profile',
				'detail'   => sprintf(
					'%d profile(s) exist but NONE is published. JCE selects only from published rows '
						. '(classes/application.php:367) and renders no editor at all when nothing '
						. 'matches — for every user, in both the site and the administrator, with no '
						. 'error message anywhere. Both JCE\'s import and its Repair action force '
						. 'published = 0 on every row (helpers/profiles.php:447-449) and then '
						. 're-publish "Default" with a separate raw UPDATE; an interrupted repair '
						. 'leaves exactly this state.',
					\count($rows)
				),
			];
		}

		$defaultProfile = null;

		foreach ($rows as $row) {
			if (strcasecmp((string) $row['name'], 'Default') === 0) {
				$defaultProfile = $row;

				break;
			}
		}

		if ($rows !== [] && $defaultProfile === null) {
			$hasIdOne = false;

			foreach ($rows as $row) {
				if ((int) $row['id'] === 1) {
					$hasIdOne = true;

					break;
				}
			}

			$problems[] = [
				'severity' => $hasIdOne ? 'notice' : 'warning',
				'code'     => 'no_default_profile',
				'detail'   => 'No profile is named exactly "Default". JcePluginsHelper::postInstall() '
					. 'registers every newly installed JCE editor plugin into the profile matched by '
					. '`WHERE name = \'Default\' OR id = 1` (helpers/plugins.php:408), and '
					. 'removeFromProfile() unregisters from the same row. '
					. ($hasIdOne
						? 'A profile with id 1 exists, so the fallback half of that query still matches '
							. 'and plugin registration will keep working — but it will target whatever '
							. 'that profile happens to be.'
						: 'There is no profile with id 1 either, so BOTH halves of that query fail: '
							. 'every future JCE plugin install will complete successfully while adding '
							. 'no toolbar button, with nothing logged anywhere.'),
			];
		}

		// ---- ordering ---------------------------------------------------
		$orderings = [];

		foreach ($rows as $row) {
			$orderings[(int) $row['ordering']][] = (string) $row['name'] . ' (#' . (int) $row['id'] . ')';
		}

		foreach ($orderings as $value => $names) {
			if (\count($names) > 1) {
				$problems[] = [
					'severity' => 'warning',
					'code'     => 'duplicate_ordering',
					'detail'   => sprintf(
						'Profiles %s all have ordering %d. JCE orders by this column and takes the '
							. 'first match, so which of them wins depends on the order the database '
							. 'happens to return rows — which is not defined and can change. Use '
							. 'reorder_jce_profiles to renumber cleanly.',
						implode(', ', $names),
						$value
					),
				];
			}
		}

		// ---- per profile ------------------------------------------------
		foreach ($rows as $row) {
			$label = (string) $row['name'] . ' (#' . (int) $row['id'] . ')';

			$types = $this->jceSplitIntList($row['types'] ?? '');
			$users = $this->jceSplitIntList($row['users'] ?? '');

			if ($types === [] && $users === []) {
				$problems[] = [
					'severity' => (int) $row['published'] === 1 ? 'warning' : 'notice',
					'code'     => 'dead_profile',
					'profile'  => $label,
					'detail'   => 'Both `types` and `users` are empty, so JCE skips this profile '
						. 'entirely during matching (application.php:394). It matches nobody, and '
						. 'nothing in the JCE admin list indicates that.',
				];
			}

			$staleGroups = [];

			foreach ($this->jceDescribeGroups($types) as $group) {
				if (!$group['exists']) {
					$staleGroups[] = $group['id'];
				}
			}

			if ($staleGroups !== []) {
				$notices[] = [
					'severity' => 'notice',
					'code'     => 'stale_group_ids',
					'profile'  => $label,
					'detail'   => '`types` references user group id(s) ' . implode(', ', $staleGroups)
						. ' that no longer exist in #__usergroups. They never match, so on a site that '
						. 'has been through a group reorganisation this profile may now cover nobody it '
						. 'was meant to.',
				];
			}

			$grid    = $this->jceParseRows($row['rows'] ?? '');
			$plugins = $this->jceSplitList($row['plugins'] ?? '');

			$unknown    = [];
			$notEnabled = [];
			$proInert   = [];

			foreach ($grid as $buttons) {
				foreach ($buttons as $token) {
					$entry = $catalog[$token] ?? null;

					if ($entry === null) {
						$unknown[] = $token;

						continue;
					}

					if ((string) $entry['kind'] === 'plugin' && !\in_array($token, $plugins, true)) {
						$notEnabled[] = $token;
					}

					if ((string) ($entry['source'] ?? '') === 'pro' && !$isPro) {
						$proInert[] = $token;
					}
				}
			}

			if ($unknown !== []) {
				$problems[] = [
					'severity' => 'warning',
					'code'     => 'unknown_toolbar_tokens',
					'profile'  => $label,
					'detail'   => 'Unknown token(s) in `rows`: ' . implode(', ', array_unique($unknown))
						. '. JceModelProfile::getRows() drops any token it cannot resolve, without an '
						. 'error (models/profile.php:294-301). SYMPTOM: the button simply is not there, '
						. 'and nothing anywhere says why. Usually a typo, or a plugin uninstalled '
						. 'without removeFromProfile() having run.',
				];
			}

			if ($notEnabled !== []) {
				$problems[] = [
					'severity' => 'warning',
					'code'     => 'button_without_enabled_plugin',
					'profile'  => $label,
					'detail'   => 'Button(s) ' . implode(', ', array_unique($notEnabled)) . ' are in '
						. '`rows` but their plugins are not in `plugins`. `rows` and `plugins` are '
						. 'independent columns and a button needs to be in both. SYMPTOM: the button '
						. 'does not render.',
				];
			}

			if ($proInert !== [] && (int) $row['published'] === 1) {
				$notices[] = [
					'severity' => 'notice',
					'code'     => 'pro_tokens_inert',
					'profile'  => $label,
					'detail'   => 'Pro-only token(s) ' . implode(', ', array_unique($proInert))
						. ' on a site where the Pro system plugin is not enabled. They produce no '
						. 'button and no error — Pro\'s catalogue is injected by an '
						. 'onWfPluginsHelperGetPlugins listener that never fires. NOTE: JCE\'s own '
						. 'seeded Default profile ships referencing several Pro-only names, so this is '
						. 'normal on a free install and not a fault.',
				];
			}

			$decoded = $this->jceDecodeParams($row['params'] ?? '');

			if (!$decoded['ok']) {
				$problems[] = [
					'severity' => $decoded['encrypted'] ? 'notice' : 'warning',
					'code'     => $decoded['encrypted'] ? 'params_encrypted' : 'params_corrupt',
					'profile'  => $label,
					'detail'   => $decoded['encrypted']
						? 'params are encrypted with the legacy ' . $decoded['algorithm'] . ' scheme. '
							. 'They cannot be read or written by these tools, and they are invisible to '
							. 'audit_jce_profiles. Open and save the profile once in the JCE admin UI: '
							. 'JceTableProfiles::load() decrypts on read and store() writes plaintext '
							. 'back, so a single save migrates it permanently.'
						: 'params is neither valid JSON nor a recognised JCE ciphertext, so the whole '
							. 'configuration of this profile is unreadable. Something other than JCE '
							. 'wrote this column.',
				];
			} elseif ($decoded['params'] !== []) {
				$orphans = array_values(array_diff(
					array_keys($decoded['params']),
					array_merge($plugins, ['editor', 'setup'])
				));

				if ($orphans !== []) {
					$notices[] = [
						'severity' => 'notice',
						'code'     => 'orphaned_params',
						'profile'  => $label,
						'detail'   => 'params contains section(s) ' . implode(', ', $orphans) . ' for '
							. 'plugins not in the `plugins` column. Inert now, but they take effect '
							. 'immediately if the plugin is ever re-enabled on this profile — '
							. 'JceModelProfile::save() preserves them rather than clearing them '
							. '(models/profile.php:874-880).',
					];
				}
			}
		}

		$versionFinding = $this->jceVersionFinding();

		if ($versionFinding !== null) {
			array_unshift($problems, [
				'severity' => $versionFinding['severity'] === 'critical' ? 'error' : 'warning',
				'code'     => 'version',
				'detail'   => $versionFinding['message'],
			]);
		}

		$errors   = \count(array_filter($problems, static fn ($p) => $p['severity'] === 'error'));
		$warnings = \count(array_filter($problems, static fn ($p) => $p['severity'] === 'warning'));

		return ToolResult::json([
			'ok'       => true,
			'status'   => $errors > 0 ? 'broken' : ($warnings > 0 ? 'degraded' : 'healthy'),
			'summary'  => $errors > 0
				? sprintf('%d error(s) and %d warning(s). At least one of these means the editor is '
					. 'not working correctly for someone right now.', $errors, $warnings)
				: ($warnings > 0
					? sprintf('No errors, %d warning(s). The editor works, but something is configured '
						. 'in a way that produces a wrong-looking toolbar with no diagnostic.', $warnings)
					: 'No correctness problems found. Run audit_jce_profiles separately — that covers '
						. 'the security side, which this tool does not.'),
			'profiles_total'     => \count($rows),
			'profiles_published' => \count($published),
			'problems' => $problems,
			'notices'  => $notices,
			'not_repaired_note' => 'Nothing here is fixed automatically. JCE\'s own control-panel Repair '
				. 'action reseeds profiles from profiles.xml and force-unpublishes every existing row '
				. 'as a side effect (helpers/profiles.php:447-449), re-publishing only "Default" — a '
				. 'destructive operation that this add-on is not going to perform on your behalf.',
			'see_also' => 'audit_jce_profiles for the security posture, including the CVE-2026-48907 '
				. 'rogue-profile indicators.',
			'component' => $this->jceEditionNotice(),
		]);
	}
}
