<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Compare Events Booking registrants with an AcyMailing list.
 *
 * WHY THIS EXISTS: Events Booking's Mass Mail is scoped to one event_id per
 * call and its dedupe array is a local, so a person registered for ten events
 * gets ten copies. The standing workaround is to sync registrants into an
 * AcyMailing list (Joomdonation's plg_eventbooking_acym, or an import) and mail
 * the list, because AcyMailing dedupes by subscriber. This tool shows whether
 * that list is actually in sync — read-only, it never adds anyone.
 *
 * "Registrant" uses Events Booking's own contactable predicate, repeated in
 * three places in its source:
 *     published = 1 OR (published = 0 AND payment_method LIKE 'os_offline%')
 * Group-member rows normally carry email = '' and are skipped.
 *
 * THE BRIDGE PLUGIN: the registrant sync ships inside Events Booking as
 * plug_eventbooking_acym → #__extensions folder 'eventbooking', element 'acym'
 * ("Events Booking - AcyMailing"). It is NOT plg_acymailing_eventbooking —
 * that one is a legacy AcyMailing 5 *tag* plugin that inserts event listings
 * into newsletters and syncs nobody (1.0.0 checked it by mistake).
 *
 * Which list a registrant lands on (plg_eventbooking_acym 5.3.1,
 * addRegistrantToAcyMailing()): the event's own params.acymailing_list_ids;
 * if that is empty(), the plugin's default_list_ids; if that is empty too, no
 * list at all — silently. The plugin also skips anyone who did not opt in
 * when EB's show_subscribe_newsletter_checkbox or the plugin's
 * subscribe_newsletter_field is set.
 */
final class CheckEventbookingSyncTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'check_acym_eventbooking_sync'; }

	public function getDescription(): string
	{
		return 'Read-only: compare Events Booking registrants (Events Booking\'s own "contactable" definition - '
			. 'paid, or pending on an offline payment method) with the subscribers of one AcyMailing list. Scope '
			. 'the registrants with event_ids, category_id and/or event_date_from / event_date_to. Reports the '
			. 'distinct registrant emails, how many Events Booking Mass Mail would send (one per registration, '
			. 'per event), how many are on the list and eligible, how many are MISSING from the list, and how '
			. 'many UNSUBSCRIBED from it (those must not be re-added). Also reports the state of Events Booking\'s '
			. 'AcyMailing sync plugin (plg_eventbooking_acym), its default lists, and which published events in '
			. 'scope feed this list, feed only other lists, or feed NO list (no list on the event and no plugin '
			. 'default - their registrants reach AcyMailing nowhere). This is the check behind the standard '
			. 'workaround for Events Booking\'s inability to dedupe Mass Mail across events.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['list_id'],
			'properties' => [
				'list_id'         => ['type' => 'integer', 'minimum' => 1],
				'event_ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
				'category_id'     => ['type' => 'integer', 'minimum' => 1],
				'event_date_from' => ['type' => 'string', 'description' => 'Y-m-d'],
				'event_date_to'   => ['type' => 'string', 'description' => 'Y-m-d'],
				'sample'          => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200, 'description' => 'How many example emails per bucket (default 20).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$prefix = $this->db->getPrefix();
		$tables = $this->db->getTableList();
		if (!\in_array($prefix . 'eb_registrants', $tables, true) || !\in_array($prefix . 'eb_events', $tables, true)) {
			return ToolResult::error('Events Booking (com_eventbooking) is not installed on this site - #__eb_registrants does not exist.');
		}

		$listId = $this->requirePositiveInt($arguments, 'list_id');
		$list   = $this->db->setQuery('SELECT id, name FROM ' . $this->acyQn($this->acyTable('list')) . ' WHERE id = ' . $listId)->loadAssoc();
		if (!$list) {
			return ToolResult::error('No AcyMailing list with id ' . $listId . '.');
		}

		// Event-scope filters, on alias e — shared by the registrant query and the per-event list scan.
		$ew = [];
		if (!empty($arguments['event_ids'])) {
			$ew[] = 'e.id IN (' . implode(',', array_map('intval', (array) $arguments['event_ids'])) . ')';
		}
		if (!empty($arguments['category_id'])) {
			$ew[] = 'e.id IN (SELECT event_id FROM ' . $this->acyQn($prefix . 'eb_event_categories') . ' WHERE category_id = ' . (int) $arguments['category_id'] . ')';
		}
		if (!empty($arguments['event_date_from'])) {
			$ew[] = 'e.event_date >= ' . $this->db->quote((string) $arguments['event_date_from']);
		}
		if (!empty($arguments['event_date_to'])) {
			$ew[] = 'e.event_date < ' . $this->db->quote((string) $arguments['event_date_to'] . ' 23:59:59');
		}

		$w    = array_merge(["(r.published = 1 OR (r.published = 0 AND r.payment_method LIKE 'os_offline%'))", "r.email <> ''"], $ew);
		$from = ' FROM ' . $this->acyQn($prefix . 'eb_registrants') . ' r JOIN ' . $this->acyQn($prefix . 'eb_events') . ' e ON e.id = r.event_id WHERE ' . implode(' AND ', $w);

		$registrations = (int) $this->db->setQuery('SELECT COUNT(*)' . $from)->loadResult();
		$events        = (int) $this->db->setQuery('SELECT COUNT(DISTINCT r.event_id)' . $from)->loadResult();
		$emails        = array_values(array_unique(array_map(
			static fn ($e) => strtolower(trim((string) $e)),
			$this->db->setQuery('SELECT r.email' . $from)->loadColumn() ?: []
		)));

		// Mass Mail sends once per registration row per event (its dedupe cannot cross events).
		$massMail = (int) $this->db->setQuery('SELECT COUNT(*) FROM (SELECT r.event_id, LOWER(r.email) em' . $from . ' GROUP BY r.event_id, LOWER(r.email)) x')->loadResult();

		$acy = [];
		foreach ($this->db->setQuery(
			'SELECT LOWER(u.email) em, u.active, u.confirmed, ul.status FROM ' . $this->acyQn($this->acyTable('user')) . ' u'
			. ' LEFT JOIN ' . $this->acyQn($this->acyTable('user_has_list')) . ' ul ON ul.user_id = u.id AND ul.list_id = ' . $listId
		)->loadAssocList() ?: [] as $r) {
			$acy[$r['em']] = $r;
		}

		$conf    = $this->acyRequiresConfirmation();
		$buckets = ['on_list_eligible' => [], 'on_list_not_eligible' => [], 'unsubscribed_from_list' => [], 'subscriber_not_on_list' => [], 'not_a_subscriber' => []];
		foreach ($emails as $em) {
			$r = $acy[$em] ?? null;
			if ($r === null) {
				$buckets['not_a_subscriber'][] = $em;
			} elseif ($r['status'] === null) {
				$buckets['subscriber_not_on_list'][] = $em;
			} elseif ((int) $r['status'] === 0) {
				$buckets['unsubscribed_from_list'][] = $em;
			} elseif ((int) $r['active'] === 1 && (!$conf || (int) $r['confirmed'] === 1)) {
				$buckets['on_list_eligible'][] = $em;
			} else {
				$buckets['on_list_not_eligible'][] = $em;
			}
		}

		$sample = (int) ($arguments['sample'] ?? 20);
		$out    = [];
		foreach ($buckets as $k => $v) {
			$out[$k] = ['count' => count($v), 'sample' => array_slice($v, 0, $sample)];
		}

		[$bridgeLine, $bridge, $eventLists, $findings] = $this->inspectBridge($prefix, $ew, $listId, $sample);

		return ToolResult::json([
			'list'                          => $list,
			'events_in_scope'               => $events,
			'registrations_in_scope'        => $registrations,
			'eventbooking_mass_mail_sends'  => $massMail,
			'distinct_registrant_emails'    => count($emails),
			'copies_saved_by_mailing_list'  => $massMail - count($emails),
			'buckets'                       => $out,
			'in_sync'                       => $out['not_a_subscriber']['count'] + $out['subscriber_not_on_list']['count'] === 0,
			'bridge_plugin'                 => $bridgeLine,
			'bridge'                        => $bridge,
			'event_list_settings'           => $eventLists,
			'findings'                      => $findings,
			'notes'                         => [
				'unsubscribed_from_list are people who opted out of this list. Do not re-add them; subscribe_acym_subscribers skips them unless allow_resubscribe: true.',
				'To close the gap: create_acym_subscriber for not_a_subscriber (with list_ids), subscribe_acym_subscribers for subscriber_not_on_list.',
				'Contactable = Events Booking\'s own rule: published = 1, or published = 0 with an offline payment method.',
				'The sync only runs for registrations made while plg_eventbooking_acym was enabled and the event (or the plugin default) named a list, so older registrants missing from a list are expected.',
			],
		]);
	}

	/**
	 * Read-only look at Events Booking's AcyMailing sync plugin and at which list(s) each
	 * published event in scope feeds, using the plugin's own resolution order.
	 *
	 * @param  string[] $ew  event-scope WHERE fragments on alias e
	 *
	 * @return array{0: string, 1: array, 2: ?array, 3: string[]}
	 */
	private function inspectBridge(string $prefix, array $ew, int $listId, int $sample): array
	{
		$findings = [];

		$row = $this->db->setQuery(
			"SELECT enabled, params FROM #__extensions WHERE type = 'plugin' AND folder = 'eventbooking' AND element = 'acym'"
		)->loadAssoc();

		$params       = $row ? (json_decode((string) $row['params'], true) ?: []) : [];
		$defaultRaw   = (string) ($params['default_list_ids'] ?? '');
		$defaultLists = self::parseListIds($defaultRaw);

		if (!$row) {
			$status = 'not installed';
		} else {
			$status = (int) $row['enabled'] === 1 ? 'enabled' : 'DISABLED';
		}
		$line = 'plg_eventbooking_acym (Events Booking - AcyMailing) ' . $status;

		// Opt-in gates: registrants who did not tick the newsletter box are skipped by design.
		$optInCheckbox = false;
		if (\in_array($prefix . 'eb_configs', $this->db->getTableList(), true)) {
			$optInCheckbox = (string) $this->db->setQuery(
				'SELECT config_value FROM ' . $this->acyQn($prefix . 'eb_configs') . " WHERE config_key = 'show_subscribe_newsletter_checkbox'"
			)->loadResult() === '1';
		}
		$optInField = (int) ($params['subscribe_newsletter_field'] ?? 0);

		$bridge = [
			'plugin'                              => 'plg_eventbooking_acym',
			'extension'                           => ['folder' => 'eventbooking', 'element' => 'acym'],
			'status'                              => $status,
			'default_list_ids'                    => $defaultLists,
			'target_list_is_a_default'            => \in_array($listId, $defaultLists, true),
			'add_group_members_to_newsletter'     => (string) ($params['add_group_members_to_newsletter'] ?? '0') === '1',
			'opt_in_required'                     => $optInCheckbox || $optInField > 0,
			'list_resolution'                     => 'event params.acymailing_list_ids, else the plugin default_list_ids, else no list',
		];

		$legacy = $this->db->setQuery(
			"SELECT enabled FROM #__extensions WHERE type = 'plugin' AND folder = 'acymailing' AND element = 'eventbooking'"
		)->loadResult();
		if ($legacy !== null) {
			$bridge['legacy_tag_plugin'] = 'plg_acymailing_eventbooking is also installed (' . ((int) $legacy === 1 ? 'enabled' : 'disabled')
				. '). It is an AcyMailing 5 tag plugin that inserts events into newsletters; it does not sync registrants.';
		}

		if ($status !== 'enabled') {
			$findings[] = $status === 'not installed'
				? 'plg_eventbooking_acym is not installed, so no registrant reaches AcyMailing automatically. The list only grows by import or by hand.'
				: 'plg_eventbooking_acym is DISABLED, so new registrants are not being added to any AcyMailing list.';
		}
		if ($bridge['opt_in_required']) {
			$findings[] = 'Newsletter opt-in is required (Events Booking "subscribe newsletter" checkbox or the plugin\'s subscribe_newsletter_field), so registrants who did not opt in are missing from the list by design.';
		}

		if (!$row) {
			return [$line, $bridge, null, $findings];
		}

		// Per-event list settings for published events in scope.
		$events = $this->db->setQuery(
			'SELECT e.id, e.title, e.event_date, e.params, (e.event_date >= NOW()) AS upcoming FROM '
			. $this->acyQn($prefix . 'eb_events') . ' e WHERE ' . implode(' AND ', array_merge(['e.published = 1'], $ew))
			. ' ORDER BY e.event_date DESC'
		)->loadAssocList() ?: [];

		$feeding = 0;
		$other   = [];
		$none    = [];
		foreach ($events as $e) {
			$ep  = json_decode((string) $e['params'], true);
			$raw = \is_array($ep) ? (string) ($ep['acymailing_list_ids'] ?? '') : '';
			// Mirror the plugin: empty() on the event value falls back to the plugin default.
			$lists = empty($raw) ? $defaultLists : self::parseListIds($raw);
			$info  = ['id' => (int) $e['id'], 'title' => (string) $e['title'], 'event_date' => (string) $e['event_date'], 'upcoming' => (int) $e['upcoming'] === 1];

			if ($lists === []) {
				$none[] = $info;
			} elseif (\in_array($listId, $lists, true)) {
				$feeding++;
			} else {
				$other[] = $info + ['list_ids' => $lists];
			}
		}

		// Upcoming first (soonest first), then the most recent past events.
		$upFirst = static function (array $rows): array {
			$up   = array_reverse(array_values(array_filter($rows, static fn ($r) => $r['upcoming'])));
			$past = array_values(array_filter($rows, static fn ($r) => !$r['upcoming']));

			return array_merge($up, $past);
		};
		$none  = $upFirst($none);
		$other = $upFirst($other);

		$noneUpcoming  = \count(array_filter($none, static fn ($r) => $r['upcoming']));
		$otherUpcoming = \count(array_filter($other, static fn ($r) => $r['upcoming']));

		$eventLists = [
			'published_events_in_scope' => \count($events),
			'feeding_this_list'         => $feeding,
			'feeding_other_lists_only'  => ['count' => \count($other), 'upcoming' => $otherUpcoming, 'sample' => \array_slice($other, 0, $sample)],
			'feeding_no_list'           => ['count' => \count($none), 'upcoming' => $noneUpcoming, 'sample' => \array_slice($none, 0, $sample)],
		];

		if ($none !== []) {
			$findings[] = \count($none) . ' published event(s) in scope (' . $noneUpcoming . ' upcoming) have no AcyMailing list set'
				. ($defaultLists === [] ? ' and the plugin has no default_list_ids' : '')
				. ', so their registrants are added to no list. Set the lists on each event (Events Booking event edit > "Acymailing Settings" tab > Newsletter Lists)'
				. ($defaultLists === [] ? ', or give plg_eventbooking_acym a default_list_ids so new events are covered.' : '.');
		}
		if ($otherUpcoming > 0) {
			$findings[] = $otherUpcoming . ' upcoming published event(s) feed other AcyMailing lists but not list ' . $listId . '.';
		}

		return [$line, $bridge, $eventLists, $findings];
	}

	/** @return int[] */
	private static function parseListIds(string $raw): array
	{
		return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $i) => $i > 0)));
	}
}
