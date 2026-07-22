<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Calendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * List DPCalendar calendars — which under the hood are Joomla content
 * categories with extension = com_dpcalendar. Adds per-calendar event count
 * so the agent can quickly see which calendars have content.
 *
 * For calendar CRUD (create / update / delete) use cs-mcp-for-j's core
 * category tools with extension="com_dpcalendar".
 */
final class ListCalendarsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_calendars'; }

	public function getDescription(): string
	{
		return 'List DPCalendar calendars (Joomla categories where extension=com_dpcalendar). '
			. 'Filters: published (1/0/-2), parent_id, search (title/alias LIKE %term%). '
			. 'Returns id, title, alias, parent_id, level, published, published_label, '
			. 'access, event_count. For create/update/delete of calendars, use cs-mcp-for-j '
			. 'core tools (create_category / update_category / delete_category) with '
			. 'extension="com_dpcalendar".';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'published' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2]],
				'parent_id' => ['type' => 'integer'],
				'search'    => ['type' => 'string'],
				'limit'     => ['type' => 'integer'],
				'offset'    => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) {
			return $this->notInstalledError();
		}

		$p  = $this->db->getPrefix();
		$tC = $p . 'categories';
		$tE = $p . 'dpcalendar_events';

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('c.id'),
				$this->db->quoteName('c.title'),
				$this->db->quoteName('c.alias'),
				$this->db->quoteName('c.parent_id'),
				$this->db->quoteName('c.level'),
				$this->db->quoteName('c.published'),
				$this->db->quoteName('c.access'),
				$this->db->quoteName('c.language'),
				'(SELECT COUNT(*) FROM ' . $this->db->quoteName($tE, 'e2')
					. ' WHERE ' . $this->db->quoteName('e2.catid') . ' = CAST(' . $this->db->quoteName('c.id') . ' AS CHAR)) AS event_count',
			])
			->from($this->db->quoteName($tC, 'c'))
			->where($this->db->quoteName('c.extension') . ' = ' . $this->db->quote('com_dpcalendar'));

		if (array_key_exists('published', $arguments)) {
			$q->where($this->db->quoteName('c.published') . ' = ' . (int) $arguments['published']);
		}
		if (array_key_exists('parent_id', $arguments)) {
			$q->where($this->db->quoteName('c.parent_id') . ' = ' . (int) $arguments['parent_id']);
		}
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('c.title') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('c.alias') . ' LIKE ' . $qs . ')');
		}
		$q->order($this->db->quoteName('c.lft') . ' ASC');

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			foreach (['id', 'parent_id', 'level', 'published', 'access', 'event_count'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = (int) $r[$k];
			}
			$r['published_label'] = $this->contentStateLabel((int) $r['published']);
		}
		unset($r);

		$total = (int) $this->db->setQuery(
			$this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($tC))
				->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_dpcalendar'))
		)->loadResult();

		return ToolResult::json([
			'ok'               => true,
			'count'            => count($rows),
			'limit'            => $limit,
			'offset'           => $offset,
			'total_unfiltered' => $total,
			'calendars'        => $rows,
		]);
	}
}
