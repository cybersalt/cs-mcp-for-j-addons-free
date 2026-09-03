<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * JCE (Joomla Content Editor) MCP add-on. Mapped against Pro 2.9.99.10.
 *
 * ---------------------------------------------------------------------------
 * ONE ADD-ON, BOTH EDITIONS, AND DELIBERATELY NO PRO ADD-ON
 * ---------------------------------------------------------------------------
 *
 * There is no separate Pro add-on for JCE and there should not be one.
 *
 * JCE has exactly ONE table, `#__wf_profiles`, and `plg_system_jcepro` ships no
 * SQL install file at all — its manifest has no `<install><sql>` block. Pro
 * adds no second table, no new stored entity, and no new persisted record type.
 * What it adds is (a) additional keys inside the SAME `params` JSON blob on the
 * same single table — the `editor.upload_*`, `editor.watermark_*`,
 * `editor.crop_*` / `resize_*` families and the one genuinely new top-level key,
 * `setup.custom` — and (b) browser-side editor capability (image editor, text
 * editor, media manager) whose PHP handlers operate on files on disk in real
 * time and persist nothing.
 *
 * So these tools already cover 100% of Pro's persisted state. A Pro add-on
 * would have nothing to store, read or write that this one does not. The
 * edition affects only which params keys are meaningful and which plugin names
 * are valid in `rows`/`plugins`, both of which this add-on detects and reports.
 *
 * The Pro gate itself is `PluginHelper::isEnabled('system','jcepro')` — one
 * call, in one place, `models/cpanel.php:127-131`. There is no `isPro()`
 * helper, no `JCE_PRO` constant and no runtime licence check. The `updates_key`
 * component param gates downloads from the vendor's update server, never
 * features (`plg_installer_jce/jce.php:45`), so a Pro site with a lapsed
 * subscription is still fully Pro and we do not look at it.
 *
 * ---------------------------------------------------------------------------
 * THE SECURITY POSTURE, WHICH SHAPED EVERY WRITE TOOL
 * ---------------------------------------------------------------------------
 *
 * JCE 1.0.0 – 2.9.99.4 carried CVE-2026-48907: unauthenticated remote code
 * execution, CVSS v4 10.0, CISA Known Exploited Vulnerabilities catalogue,
 * mass-exploited from June 2026 with public exploit code, fixed in 2.9.99.5.
 *
 * The headline defect was an unauthenticated file write through
 * `task=profiles.import`. But the variant that matters to an add-on that writes
 * profiles is the second one: attackers used the import endpoint simply to
 * install a malicious EDITOR PROFILE that re-enabled `php`/`phtml` uploads with
 * MIME validation off, then uploaded a webshell through JCE's own file browser.
 * The `#__wf_profiles` row WAS the payload. That is the table these tools write.
 *
 * Four consequences, all non-negotiable and none of them configurable:
 *
 *   1. NO PROFILE IMPORT TOOL. Not now, not later. A tool that accepts a whole
 *      profile payload and writes it is a reimplementation of the exploit
 *      primitive however carefully it validates. Every write here is a named,
 *      field-level setter, which costs a round trip and removes the primitive.
 *
 *   2. EXECUTABLE EXTENSIONS ARE HARD-REFUSED in any write touching an
 *      `*.extensions` param — the vendor's own ~70-entry blocklist from
 *      `utility.php:1353-1368`, in any position, including double extensions.
 *      The whole call is refused; nothing is silently stripped.
 *
 *   3. WRITES REFUSE ENTIRELY BELOW 2.9.99.5. On such a site a profile write is
 *      indistinguishable from the live attack in any log. Reads still work,
 *      because reading is how you find out what happened.
 *
 *   4. SECURITY FLAGS ARE ONE-WAY. `editor.allow_php`, `allow_javascript`,
 *      `allow_event_attributes`, `allow_custom_xml`, `validate_mimetype`,
 *      `sanitize_html` and `verify_html` may be tightened and never loosened.
 *
 * `audit_jce_profiles` is the most valuable tool here and is a pure read. A
 * site popped in June 2026 may still be carrying the attacker's profile —
 * updating JCE does not remove the row.
 *
 * ---------------------------------------------------------------------------
 * THE TWO LANDMINES
 * ---------------------------------------------------------------------------
 *
 *   L1. `params` MAY BE ENCRYPTED. Prefixes `###AES128###`, `###CTR128###`,
 *       `###DEFUSE###`, keyed from an unshipped per-site `serverkey.php`
 *       (`helpers/encrypt.php:26-104`). A naive `json_decode()` returns null
 *       with no error and looks exactly like "no configuration". There is a
 *       decrypt path in 2.9.99.10 and NO encrypt path, so any write plaintexts
 *       the row permanently. We detect the prefix and refuse, both ways.
 *
 *   L2. `save()` MERGES, it does not replace (`models/profile.php:908`, via
 *       `WFUtility::array_merge_recursive_distinct`), and only for keys in the
 *       `plugins` column plus `editor` and `setup` (`:874-880`). Nothing can be
 *       deleted through the vendor path. These tools write the `params` column
 *       directly and therefore own the merge semantics explicitly — including
 *       preserving the orphaned keys the UI would have kept.
 *
 * v1.0.0 ships 21 tools across 5 categories. Two tools that an equivalent
 * add-on for another extension would have are deliberately absent: there is no
 * profile import (see point 1 above), and there is no `set_jce_config` — the
 * only two meaningful settings in com_jce's component configuration are
 * `allow_profile_guests` and `profile_groups_whitelist`, both of which are
 * security controls that override every profile, and both of which should be
 * changed as a deliberate human act in JCE's Preferences screen rather than as
 * a side effect of an automated call.
 */
final class Csmcpforjjce extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Profiles — rows in #__wf_profiles, JCE's only table.
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\ListProfilesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\GetProfileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\CreateProfileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\UpdateProfileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\DeleteProfileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\SetProfileStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\ReorderProfilesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles\DuplicateProfileTool::class,

		// Assignment — who a profile applies to, and the resolver that answers
		// "which profile does this user actually get" without guessing.
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Assignment\GetProfileAssignmentTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Assignment\SetProfileAssignmentTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Assignment\ResolveProfileForTool::class,

		// Editor configuration — the toolbar grid and the params blob.
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor\GetProfileToolbarTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor\SetProfileToolbarTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor\GetProfileParamsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor\SetProfileParamsTool::class,

		// Plugins — the merged button catalogue and per-plugin param schemas.
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Plugins\ListPluginsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Plugins\GetPluginTool::class,

		// Diagnostics — orientation, configuration, correctness, and the
		// CVE-2026-48907 rogue-profile hunt.
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics\AuditProfilesTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
