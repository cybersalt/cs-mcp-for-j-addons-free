<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Inventory of the #__acym_* tables query_acym_table can read.
 */
final class ListTablesTool extends AbstractTool
{
	use AcymailingBootTrait;

	/** Columns query_acym_table never returns (credentials or per-subscriber secrets). */
	public const REDACTED_COLUMNS = [
		'user'           => ['key'],
		'mailbox_action' => ['password'],
		'plugin'         => ['settings'],
	];

	/** What each table is, in one line, and the edition whose feature writes it. */
	private const DESCRIPTIONS = [
		'user'                 => ['subscribers (one row per email; the unique index is case-insensitive)', 'starter'],
		'list'                 => ['lists; type standard | front | followup. subscribers/unsubscribed_users are a stale cache', 'starter'],
		'user_has_list'        => ['subscriptions; status 1 subscribed, 0 unsubscribed (a row is kept on unsubscribe)', 'starter'],
		'mail'                 => ['every email body: campaigns, templates, notifications, welcome/unsubscribe, overrides. name/subject/body/preheader are stored double-encoded', 'starter'],
		'campaign'             => ['campaign wrapper around a mail: draft/active/sent, sending_type now|scheduled|auto', 'starter'],
		'mail_has_list'        => ['campaign mail -> target lists', 'starter'],
		'queue'                => ['pending sends; PK (mail_id, user_id) is what deduplicates recipients', 'starter'],
		'mail_stat'            => ['per-mail totals: sent, fail, opens, clicks, bounces, unsubscribes', 'starter'],
		'user_stat'            => ['per-subscriber per-mail delivery/open record', 'starter'],
		'url'                  => ['tracked link targets', 'starter'],
		'url_click'            => ['per-subscriber link clicks', 'starter'],
		'history'              => ['subscriber audit trail (created, subscribed, unsubscribed, test_sent...)', 'starter'],
		'configuration'        => ['settings (name PK / value); credentials redacted row by row', 'starter'],
		'field'                => ['subscriber field definitions; core rows are name/email/language', 'starter'],
		'user_has_field'       => ['subscriber field values', 'starter'],
		'form'                 => ['subscription forms', 'starter'],
		'tag'                  => ['tags on mails and lists', 'starter'],
		'mail_archive'         => ['archived rendered copies of sent mails', 'starter'],
		'plugin'               => ['AcyMailing add-on registry; settings redacted', 'starter'],
		'custom_zone'          => ['saved editor zones', 'starter'],
		'mail_stat_detail'     => ['anonymised per-mail device/source/time breakdown (11.x only)', 'starter'],
		'segment'              => ['segments', 'enterprise'],
		'automation'           => ['automations', 'enterprise'],
		'step'                 => ['automation steps (triggers)', 'enterprise'],
		'condition'            => ['automation conditions', 'enterprise'],
		'action'               => ['automation actions and filters', 'enterprise'],
		'followup'             => ['follow-up sequences (each owns a hidden list of type followup)', 'enterprise'],
		'followup_has_mail'    => ['follow-up emails with delays', 'enterprise'],
		'mail_override'        => ['Joomla core email overrides', 'enterprise'],
		'mailbox_action'       => ['mailbox actions / bounce handling; password redacted', 'enterprise'],
		'rule'                 => ['bounce rules', 'enterprise'],
		'scenario'             => ['scenarios (11.x)', 'enterprise'],
		'scenario_step'        => ['scenario steps', 'enterprise'],
		'scenario_process'     => ['scenario runs per subscriber', 'enterprise'],
		'scenario_queue'       => ['scenario scheduled steps', 'enterprise'],
		'scenario_history_line'=> ['scenario run log', 'enterprise'],
	];

	public function getName(): string { return 'list_acym_tables'; }

	public function getDescription(): string
	{
		return 'List the #__acym_* tables that query_acym_table can read, with row counts, a one-line meaning, '
			. 'which edition\'s feature writes each one, and which columns are always redacted. Tables used by '
			. 'Enterprise features exist on every edition (the schema is identical); on Starter they are '
			. 'normally empty.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$out = [];
		foreach (self::ACYM_TABLES as $short) {
			if (!$this->acyTableExists($short)) {
				continue;
			}
			$rows = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable($short)))->loadResult();
			$out[] = [
				'table'            => $short,
				'rows'             => $rows,
				'meaning'          => self::DESCRIPTIONS[$short][0] ?? '',
				'feature_edition'  => self::DESCRIPTIONS[$short][1] ?? 'starter',
				'redacted_columns' => self::REDACTED_COLUMNS[$short] ?? [],
			];
		}

		return ToolResult::json(['count' => count($out), 'tables' => $out]);
	}
}
