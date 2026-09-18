<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * The com_ats component parameters, annotated, with the secrets withheld.
 *
 * WHY ANNOTATION IS THE WHOLE POINT.
 * ----------------------------------
 * ATS' parameters are ordinary Joomla component params — one JSON blob in
 * #__extensions.params, read through ComponentHelper::getParams('com_ats') —
 * so dumping them is trivial and almost useless. Half of them are cosmetic
 * category-listing switches; a handful decide whether whole features exist.
 * The ones that matter are annotated here with what they actually change, and
 * the annotations are taken from config.xml and from the code that reads each
 * key, not from the label text.
 *
 * The single most common confusion this answers: `ticketPriorities` defaults to
 * 0, and when it is 0 the priority field is hidden throughout the interface
 * even though #__ats_tickets.priority is NOT NULL with no default and every
 * ticket therefore carries a value. So "every ticket is priority 5" on a normal
 * site means "nobody has ever been shown the field", not "everything is normal
 * priority".
 *
 * WHAT IS NOT RETURNED.
 * ---------------------
 * Two keys are reported as a boolean "is it set" and never as a value:
 *
 *   - `secret`, in the Automation fieldset (config.xml:805-818). It is the
 *     shared secret that authenticates ATS' CRON URL. Returning it is handing
 *     over the ability to trigger the component's scheduled work from outside.
 *   - `vapidKey`, a hidden field in the Common fieldset (config.xml:186-188).
 *     Web-push VAPID material.
 *
 * `reveal_secrets` is not a parameter of this tool. There is no argument that
 * returns them, deliberately — a tool that has a flag for it is a tool that
 * will eventually be called with the flag set.
 */
final class GetConfigTool extends AbstractTool
{
	use ATSBootTrait;

	/** Keys whose value is never returned. Reported as set / not set only. */
	private const SECRET_KEYS = [
		'secret'   => 'The shared secret that protects ATS\' CRON URL (Automation fieldset). Anyone holding '
			. 'it can trigger the component\'s scheduled work from outside the site. Never returned by this '
			. 'tool, with or without any flag.',
		'vapidKey' => 'Web-push VAPID key material (hidden field, Common fieldset). Never returned.',
	];

	/**
	 * The parameters that change behaviour materially, and what they change.
	 * Everything not listed here is returned too, just without commentary.
	 *
	 * @var array<string, array{fieldset: string, default: string, meaning: string}>
	 */
	private const ANNOTATED = [
		'ticketPriorities' => [
			'fieldset' => 'common',
			'default'  => '0',
			'meaning'  => 'Master switch for ticket priorities. DEFAULTS TO 0, WHICH IS WHY THE PRIORITY '
				. 'FIELD IS HIDDEN ON MOST SITES. #__ats_tickets.priority is TINYINT NOT NULL with NO '
				. 'DEFAULT, so every ticket carries a number regardless — ATS\' form supplies 5 (Normal) '
				. 'when the field is not shown. A site-wide sea of priority 5 therefore means "the field was '
				. 'never displayed", not "everything is normal". Turning this on reveals the field with '
				. 'three options: 0 = High, 5 = Normal, 10 = Low. Beware the badge layout '
				. '(layouts/akeeba/ats/common/priority_badge.php:41-55): it renders High only for '
				. '0 < priority < 5, so priority 0 — the form\'s own High — displays as Normal.',
		],
		'invite_users' => [
			'fieldset' => 'common',
			'default'  => '0',
			'meaning'  => 'Allow users to invite other users into a ticket conversation. DEFAULTS TO 0, so '
				. 'invitations are OFF unless somebody turned them on. This is a CORE feature, not Pro — '
				. '#__ats_tickets_users is written on Core. Rows can exist in that table while this is 0 '
				. '(the setting was turned off later); the invitations remain effective because '
				. 'getTicketPrivileges() grants an invited user view and post regardless of this switch.',
		],
		'invite_limit' => [
			'fieldset' => 'common',
			'default'  => '10',
			'meaning'  => 'Maximum collaborators per ticket. Enforced in PHP only, in '
				. 'TicketModel::inviteUser() (TicketModel.php:306-325), by counting existing rows before '
				. 'inserting. #__ats_tickets_users has NO unique index and no index at all, so a direct '
				. 'INSERT bypasses both this limit and the duplicate check. config.xml caps the form field '
				. 'at 20.',
		],
		'timespent_hide' => [
			'fieldset' => 'frontend',
			'default'  => '0',
			'meaning'  => 'Set to 1 to remove the Time Spent feature from the interface entirely. IT DOES '
				. 'NOT DELETE THE DATA — the vendor\'s own description says so. #__ats_posts.timespent and '
				. 'the derived #__ats_tickets.timespent keep every value already recorded, so historical '
				. 'totals stay readable through these tools while nobody can add to them.',
		],
		'timespent_mandatory' => [
			'fieldset' => 'frontend',
			'default'  => '0',
			'meaning'  => 'Require support staff to fill in Time Spent before a reply can be submitted. '
				. 'Only meaningful when timespent_hide is 0. When this is 0, a timespent of 0 on a post '
				. 'means "not recorded", not "no time taken" — which matters before drawing any conclusion '
				. 'from get_ats_agent_workload.',
		],
		'hide_public' => [
			'fieldset' => 'frontend',
			'default'  => '0',
			'meaning'  => 'NARROWER THAN THE NAME SUGGESTS. It controls only what happens when a ticket '
				. 'CATEGORY forces new tickets to Private (the category param forcetype = "PRIV"). At 0 the '
				. 'Public/Private field is shown read-only reading "Private"; at 1 it is hidden entirely. '
				. 'It does not hide public tickets, does not make tickets private, and has no effect on '
				. 'categories that do not force Private.',
		],
		'siteurl' => [
			'fieldset' => 'common (hidden field)',
			'default'  => '',
			'meaning'  => 'The base URL ATS uses to build ticket links in notification mail — but ONLY from '
				. 'a non-site context. A HIDDEN field, not on the Configuration form, normally written by '
				. 'the component itself on a front-end request. READ THE RESOLUTION CAREFULLY: '
				. 'EmailSending.php:703 evaluates the {siteurl} token as `$app->isClient(\'site\') ? '
				. 'Uri::base() : $params->get(\'siteurl\')`. So an ordinary front-end send, and every '
				. 'mail-sending tool in this add-on (which all run inside a site-application context), '
				. 'BYPASS this parameter entirely and build links from Uri::base(). An empty value does not '
				. 'break those. It affects ATS\' non-site contexts — the Professional CLI tasks, the '
				. 'scheduled auto-close and auto-reply runs, and the mail gateway — where an empty value '
				. 'yields links that do not resolve, silently. None of those contexts ship with Core, so on '
				. 'a Core install an empty siteurl is a latent misconfiguration rather than a live fault.',
		],
		'customStatuses' => [
			'fieldset' => 'common',
			'default'  => '(none)',
			'meaning'  => 'Site-defined ticket statuses. #__ats_tickets.status is an ENUM of "O", "P", "C" '
				. 'plus the STRINGS "1" through "99", and this parameter is the only place the numeric ones '
				. 'get labels. Stored as a subform (a list of {id, label} objects), but '
				. 'Permissions::getStatuses() ALSO accepts a legacy newline-separated "id=label" string and '
				. 'parses both shapes (Permissions.php:529-603). Entries with a missing id or label, or an '
				. 'id outside 1-99, are silently ignored; a repeated id means the LAST one wins. These '
				. 'statuses are neither open nor closed as far as any ATS logic is concerned.',
		],
		'stats_enabled' => [
			'fieldset' => 'common',
			'default'  => '1',
			'meaning'  => 'Send anonymous usage statistics to Akeeba. Defaults to ON. No effect on ticket '
				. 'behaviour; listed because sites with an outbound-traffic policy usually want it off.',
		],
		'sendEmails' => [
			'fieldset' => 'common',
			'default'  => '1',
			'meaning'  => 'Master switch for ATS notification email. At 0 nothing is sent at all — the usual '
				. 'explanation for "ATS is not emailing anyone" when siteurl is fine.',
		],
		'assigned_noemail' => [
			'fieldset' => 'common',
			'default'  => '1',
			'meaning'  => 'Suppress the notification to the assignee when a ticket is assigned to them. '
				. 'Defaults to ON, i.e. assignment is silent by default.',
		],
		'nonewtickets' => [
			'fieldset' => 'frontend',
			'default'  => '0',
			'meaning'  => 'Temporarily stop accepting new tickets from the front end.',
		],
		'noreplies' => [
			'fieldset' => 'frontend',
			'default'  => '0',
			'meaning'  => 'Temporarily stop accepting replies from the front end.',
		],
		'maxBodySize' => [
			'fieldset' => 'frontend',
			'default'  => '128',
			'meaning'  => 'Maximum post body size in KB. #__ats_posts.content_html is a LONGTEXT, so this is '
				. 'a policy limit rather than a storage one.',
		],
		'editeableforxminutes' => [
			'fieldset' => 'frontend',
			'default'  => '15',
			'meaning'  => 'Grace period, in minutes, during which an author may still edit their own post '
				. '(Permissions::editGraceTime()). Spelled with the vendor\'s typo — "editeable" — and that '
				. 'is the real key name.',
		],
		'forceGuestActivation' => [
			'fieldset' => 'frontend',
			'default'  => '0',
			'meaning'  => 'Require guests who file a ticket to activate the account ATS creates for them.',
		],
		'userGroupsDisplay' => [
			'fieldset' => 'common',
			'default'  => '0',
			'meaning'  => 'Show each poster\'s Joomla user groups beside their posts. Discloses group '
				. 'membership to anyone who can read the ticket.',
		],
		'filtermethod' => [
			'fieldset' => 'security',
			'default'  => 'htmlpurifier',
			'meaning'  => 'How post HTML is sanitised: "htmlpurifier" (the default, using ATS\' own bundled '
				. 'HTMLPurifier), "joomla" (JFilterInput), or "hackme" — which is exactly what it sounds '
				. 'like and disables filtering. Anything other than htmlpurifier on a public helpdesk is '
				. 'worth questioning.',
		],
		'htmlpurifier_configstring' => [
			'fieldset' => 'security',
			'default'  => '(a fixed tag/attribute allowlist)',
			'meaning'  => 'The tag and attribute allowlist handed to HTMLPurifier. Only read when '
				. 'filtermethod is htmlpurifier.',
		],
		'attachments_private' => [
			'fieldset' => 'attachments',
			'default'  => '0',
			'meaning'  => 'Pro-only in effect. Serve attachments through a permission-checked PHP handler '
				. 'rather than as directly-reachable files. On Core there are no attachments, so this does '
				. 'nothing.',
		],
		'unsafe_uploads' => [
			'fieldset' => 'attachments',
			'default'  => '0',
			'meaning'  => 'Pro-only in effect, and the most dangerous switch in the component: at 1 it '
				. 'disables the upload restrictions wholesale (extension allowlist, size cap, MIME check). '
				. 'Inert on Core because attachments are Pro, but flag it loudly on any Pro site.',
		],
		'time_limit' => [
			'fieldset' => 'automation',
			'default'  => '10',
			'meaning'  => 'Seconds of wall clock ATS\' scheduled work is allowed per run. Pro-only in '
				. 'effect — the CLI commands are not shipped with Core.',
		],
		'accurate_php_cli' => [
			'fieldset' => 'automation',
			'default'  => '1',
			'meaning'  => 'Use the detected PHP CLI binary path rather than a guess. Pro-only in effect.',
		],
		'workaround_mailtemplate' => [
			'fieldset' => 'common',
			'default'  => '1',
			'meaning'  => 'Enable ATS\' own workaround for a Joomla mail-template bug (Helper\\'
				. 'MailTemplateHotFix). Defaults to ON; leave it on unless a specific problem says otherwise.',
		],
		'captcha' => [
			'fieldset' => 'frontend',
			'default'  => '(use global)',
			'meaning'  => 'Which captcha plugin guards guest ticket submission. "0" means none, which on a '
				. 'site accepting guest tickets is a spam invitation.',
		],
		'initial_sort' => [
			'fieldset' => 'category',
			'default'  => 'modified DESC',
			'meaning'  => 'Default ordering of the front-end ticket list. NOTE THE COLUMN: `modified` means '
				. '"last reply", not "last edited" — it is written only by PostTable::onAfterStore() for a '
				. 'new post on a non-closed ticket. So the default list order is by last reply, and a ticket '
				. 'replied to after it was closed does not move.',
		],
	];

	/** Keys config.xml declares as `type="hidden"`, i.e. not on the Configuration form. */
	private const HIDDEN_KEYS = [
		'siteurl',
		'vapidKey',
		'migrate_media_options',
		'migrate_canned_replies',
		'migrate_custom_status',
		'migrate_show_guest_captcha',
		'migrate_user_tags',
	];

	public function getName(): string { return 'get_ats_config'; }

	public function getDescription(): string
	{
		return 'Return the com_ats component parameters — read through ComponentHelper::getParams(\'com_ats\'), '
			. 'i.e. the JSON blob in #__extensions.params — with the ones that materially change behaviour '
			. 'annotated with what they actually do, their config.xml fieldset, and their shipped default. '
			. 'SECRETS ARE NEVER RETURNED. `secret` (the Automation fieldset\'s CRON-URL shared secret) and '
			. '`vapidKey` (web-push key material) are reported as a boolean "is it set" and nothing more. '
			. 'There is no reveal flag and there will not be one. '
			. 'THE ANNOTATIONS WORTH READING BEFORE ANYTHING ELSE: '
			. 'ticketPriorities defaults to 0, which is why the priority field is hidden on most sites — '
			. '#__ats_tickets.priority is NOT NULL with no default, so every ticket still carries a number '
			. '(ATS\' form supplies 5 = Normal), and a site full of priority 5 means "the field was never '
			. 'shown", not "everything is normal". '
			. 'invite_users defaults to 0, so collaborator invitations are off unless enabled — but they are '
			. 'a CORE feature, and invite_limit (default 10) is enforced in PHP only because '
			. '#__ats_tickets_users has no unique index at all. '
			. 'siteurl is a HIDDEN field with no default, and its effect is narrower than it looks: '
			. 'EmailSending.php:703 resolves the {siteurl} token as `$app->isClient(\'site\') ? Uri::base() '
			. ': $params->get(\'siteurl\')`, so front-end sends and every send from this add-on bypass the '
			. 'parameter entirely. An empty value only affects ATS\' non-site contexts — the Professional '
			. 'CLI tasks, scheduled auto-close and auto-reply runs, and the mail gateway — none of which '
			. 'ship with Core. Do not report an empty siteurl as broken notification mail. '
			. 'timespent_hide removes the Time Spent feature from the UI WITHOUT deleting the recorded data. '
			. 'timespent_mandatory decides whether 0 means "no time taken" or "nobody filled it in". '
			. 'hide_public is narrower than it sounds: it only affects the Public/Private field when a '
			. 'category FORCES Private. '
			. 'customStatuses is the only source of labels for the numeric statuses "1".."99" that '
			. '#__ats_tickets.status also accepts; ATS parses it as either a subform or a legacy '
			. 'newline-separated id=label string. Those statuses must be compared as STRINGS in SQL — '
			. '`status = \'7\'`, never `status = 7`, because the column is an ENUM and MySQL reads an '
			. 'unquoted integer as an ENUM ORDINAL, silently matching a different member. '
			. 'stats_enabled (anonymous usage statistics to Akeeba) defaults to 1. '
			. 'Filters: keys (return only these), fieldset, search (substring match on key names), '
			. 'annotated_only (default false). Every parameter present on the site is returned whether or '
			. 'not it is annotated; keys with no stored value are reported as unset so the shipped default '
			. 'applies.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'keys' => [
					'type'  => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Return only these parameter keys. Omit for all.',
				],
				'fieldset' => [
					'type' => 'string',
					'description' => 'Restrict to one config.xml fieldset: common, frontend, categories, '
						. 'category, instantsearch, attachments, security, automation, permissions. Only '
						. 'applies to annotated keys, since the fieldset is a property of the form and not '
						. 'of the stored value.',
				],
				'search' => [
					'type' => 'string',
					'description' => 'Case-insensitive substring match on the parameter key name.',
				],
				'annotated_only' => [
					'type' => 'boolean',
					'description' => 'Return only the parameters this tool has commentary for. Default '
						. 'false — the unannotated ones are mostly cosmetic category-listing switches.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$params = $this->atsParams();
		$stored = $params->toArray();

		$wanted = [];

		foreach ((array) ($arguments['keys'] ?? []) as $k) {
			$k = trim((string) $k);

			if ($k !== '') {
				$wanted[] = $k;
			}
		}

		$fieldset      = trim((string) ($arguments['fieldset'] ?? ''));
		$search        = strtolower(trim((string) ($arguments['search'] ?? '')));
		$annotatedOnly = (bool) ($arguments['annotated_only'] ?? false);

		// Every key we know about: stored plus annotated-but-unset, so a missing
		// value is visible rather than simply absent.
		$allKeys = array_values(array_unique(array_merge(
			array_keys($stored),
			array_keys(self::ANNOTATED),
			array_keys(self::SECRET_KEYS)
		)));

		sort($allKeys);

		$entries = [];
		$skipped = 0;

		foreach ($allKeys as $key) {
			$annotation = self::ANNOTATED[$key] ?? null;

			if ($annotatedOnly && $annotation === null && !isset(self::SECRET_KEYS[$key])) {
				$skipped++;

				continue;
			}

			if ($wanted !== [] && !\in_array($key, $wanted, true)) {
				continue;
			}

			if ($fieldset !== '' && ($annotation['fieldset'] ?? '') !== $fieldset
				&& !str_starts_with((string) ($annotation['fieldset'] ?? ''), $fieldset)) {
				continue;
			}

			if ($search !== '' && !str_contains(strtolower($key), $search)) {
				continue;
			}

			$isSet = \array_key_exists($key, $stored);
			$value = $isSet ? $stored[$key] : null;

			$entry = [
				'key' => $key,
				'set' => $isSet,
			];

			if (isset(self::SECRET_KEYS[$key])) {
				$entry['secret']    = true;
				$entry['has_value'] = $isSet && trim((string) (\is_scalar($value) ? $value : '')) !== '';
				$entry['value']     = null;
				$entry['redaction'] = self::SECRET_KEYS[$key];
			} else {
				$entry['value'] = $this->normalise($value);
			}

			if (\in_array($key, self::HIDDEN_KEYS, true)) {
				$entry['hidden_field'] = 'Declared type="hidden" in config.xml, so it is not on the '
					. 'Configuration form. It is written by the component itself or by an upgrade step, and '
					. 'it will not appear in the admin UI.';
			}

			if ($annotation !== null) {
				$entry['fieldset']        = $annotation['fieldset'];
				$entry['shipped_default'] = $annotation['default'];
				$entry['meaning']         = $annotation['meaning'];

				if (!$isSet) {
					$entry['unset_note'] = 'No stored value, so the shipped default ('
						. $annotation['default'] . ') applies.';
				}
			}

			$entries[] = $entry;
		}

		$statuses = $this->customStatuses($params);

		$response = [
			'ok'     => true,
			'source' => 'ComponentHelper::getParams(\'com_ats\') — the params JSON on the com_ats row of '
				. '#__extensions. ATS keeps no configuration table of its own.',
			'count'  => \count($entries),
			'total_stored_keys' => \count($stored),
			'parameters' => $entries,
			'redacted' => [
				'keys' => array_keys(self::SECRET_KEYS),
				'note' => 'These are reported as set / not set and never as a value. This tool has no flag '
					. 'that would return them.',
			],
			'custom_statuses' => $statuses,
			'derived' => [
				'siteurl_configured' => $this->atsSiteUrlConfigured(),
				'siteurl_note' => $this->atsSiteUrlConfigured()
					? 'siteurl is populated, so ticket links built from a non-site context (Professional '
						. 'CLI tasks, scheduled runs, the mail gateway) will resolve.'
					: 'siteurl is empty. This does NOT break mail sent from the front end or by this '
						. 'add-on: EmailSending.php:703 uses Uri::base() whenever the application is a site '
						. 'application, which is the case for both. It only affects ATS\' non-site contexts '
						. '— the Professional CLI tasks, scheduled auto-close and auto-reply runs, and the '
						. 'mail gateway — none of which exist on Core. Treat it as site configuration worth '
						. 'tidying, not as a live mail fault.',
				'priorities_visible' => (int) $params->get('ticketPriorities', 0) === 1,
				'timespent_active'   => (int) $params->get('timespent_hide', 0) !== 1,
				'invitations_enabled' => (int) $params->get('invite_users', 0) === 1,
				'emails_enabled'     => (int) $params->get('sendEmails', 1) === 1,
			],
		];

		if ($skipped > 0) {
			$response['skipped_unannotated'] = $skipped;
		}

		$response['writing_these'] = 'This add-on does not write com_ats component parameters. They are '
			. 'ordinary Joomla component params; change them from ATS\' own Options screen, or with '
			. 'cs-mcp-for-j\'s generic component tools if one applies. Note that `secret` and `vapidKey` '
			. 'must never be round-tripped through a tool that redacts them — reading a redacted value and '
			. 'writing it back would erase the real one.';

		return ToolResult::json($response);
	}

	/**
	 * Decode customStatuses into the id => label map ATS itself would build, so
	 * the numeric status codes in every other tool have names.
	 *
	 * Permissions::getStatuses() accepts two shapes for this parameter — a
	 * subform (list of {id, label} objects, what config.xml declares today) and
	 * a legacy newline-separated "id=label" string. Both are handled, because a
	 * site upgraded from an older ATS can still be carrying the string form.
	 *
	 * @return array<string, mixed>
	 */
	private function customStatuses(\Joomla\Registry\Registry $params): array
	{
		$raw = $params->get('customStatuses', '');

		$parsed  = [];
		$shape   = 'none';
		$ignored = [];

		if (\is_string($raw) && trim($raw) !== '') {
			$shape = 'legacy id=label string';
			$lines = preg_split('/\R/', str_replace('\\n', "\n", $raw)) ?: [];

			foreach ($lines as $line) {
				$parts = explode('=', $line);

				if (\count($parts) !== 2) {
					continue;
				}

				$id    = trim($parts[0]);
				$label = trim($parts[1]);

				if (!is_numeric($id) || (int) $id < 1 || (int) $id > 99 || $label === '') {
					$ignored[] = $line;

					continue;
				}

				$parsed[(string) (int) $id] = $label;
			}
		} elseif (\is_object($raw) || \is_array($raw)) {
			$shape = 'subform';

			foreach ((array) $raw as $item) {
				$item  = (object) $item;
				$id    = $item->id ?? null;
				$label = $item->label ?? null;

				if (empty($id) || (int) $id < 1 || (int) $id > 99 || empty($label)) {
					$ignored[] = json_encode($item);

					continue;
				}

				$parsed[(string) (int) $id] = (string) $label;
			}
		}

		return [
			'configured' => $parsed !== [],
			'shape'      => $shape,
			'statuses'   => $parsed,
			'ignored_entries' => $ignored,
			'note' => '#__ats_tickets.status is an ENUM of "O" (Open), "P" (Pending) and "C" (Closed) plus '
				. 'the STRINGS "1" through "99". Those numeric members mean nothing to ATS beyond the label '
				. 'this parameter gives them — they are neither open nor closed for any purpose, and no '
				. 'automatic transition ever sets one. Entries with a missing id or label, or an id outside '
				. '1-99, are silently dropped by Permissions::getStatuses(); a repeated id means the LAST '
				. 'one wins.',
		];
	}

	/** Keep scalars as they are; turn Registry sub-objects into plain arrays. */
	private function normalise(mixed $value): mixed
	{
		if ($value instanceof \Joomla\Registry\Registry) {
			return $value->toArray();
		}

		if (\is_object($value)) {
			return json_decode((string) json_encode($value), true);
		}

		return $value;
	}
}
