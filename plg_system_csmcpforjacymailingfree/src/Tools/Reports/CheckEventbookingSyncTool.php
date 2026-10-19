<?php

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
 * AcyMailing list (plg_acymailing_eventbooking or an import) and mail the list,
 * because AcyMailing dedupes by subscriber. This tool shows whether that list
 * is actually in sync — read-only, it never adds anyone.
 *
 * "Registrant" uses Events Booking's own contactable predicate, repeated in
 * three places in its source:
 *     published = 1 OR (published = 0 AND payment_method LIKE 'os_offline%')
 * Group-member rows normally carry email = '' and are skipped.
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
			. 'many UNSUBSCRIBED from it (those must not be re-added). This is the check behind the standard '
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

		$w = ["(r.published = 1 OR (r.published = 0 AND r.payment_method LIKE 'os_offline%'))", "r.email <> ''"];
		if (!empty($arguments['event_ids'])) {
			$w[] = 'r.event_id IN (' . implode(',', array_map('intval', (array) $arguments['event_ids'])) . ')';
		}
		if (!empty($arguments['category_id'])) {
			$w[] = 'r.event_id IN (SELECT event_id FROM ' . $this->acyQn($prefix . 'eb_event_categories') . ' WHERE category_id = ' . (int) $arguments['category_id'] . ')';
		}
		if (!empty($arguments['event_date_from'])) {
			$w[] = 'e.event_date >= ' . $this->db->quote((string) $arguments['event_date_from']);
		}
		if (!empty($arguments['event_date_to'])) {
			$w[] = 'e.event_date < ' . $this->db->quote((string) $arguments['event_date_to'] . ' 23:59:59');
		}
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

		$bridge = $this->db->setQuery("SELECT enabled FROM #__extensions WHERE type = 'plugin' AND folder = 'acymailing' AND element = 'eventbooking'")->loadResult();

		return ToolResult::json([
			'list'                          => $list,
			'events_in_scope'               => $events,
			'registrations_in_scope'        => $registrations,
			'eventbooking_mass_mail_sends'  => $massMail,
			'distinct_registrant_emails'    => count($emails),
			'copies_saved_by_mailing_list'  => $massMail - count($emails),
			'buckets'                       => $out,
			'in_sync'                       => $out['not_a_subscriber']['count'] + $out['subscriber_not_on_list']['count'] === 0,
			'bridge_plugin'                 => $bridge === null ? 'plg_acymailing_eventbooking not installed' : ((int) $bridge === 1 ? 'plg_acymailing_eventbooking enabled' : 'plg_acymailing_eventbooking DISABLED'),
			'notes'                         => [
				'unsubscribed_from_list are people who opted out of this list. Do not re-add them; subscribe_acym_subscribers skips them unless allow_resubscribe: true.',
				'To close the gap: create_acym_subscriber for not_a_subscriber (with list_ids), subscribe_acym_subscribers for subscriber_not_on_list.',
				'Contactable = Events Booking\'s own rule: published = 1, or published = 0 with an offline payment method.',
			],
		]);
	}
}
