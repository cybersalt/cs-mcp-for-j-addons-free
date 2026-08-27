<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * List rows in `#__pagebuilderck_elements` — the "My Elements" library.
 *
 * A saved element is a reusable fragment of `htmlcode`: a block, a column or a
 * whole row that someone saved out of a page so it could be dropped into another
 * one. It is a COPY, not a reference. Nothing links a page back to the element
 * it was built from, so editing an element here changes nothing on any page that
 * already used it.
 *
 * The `type` column records what shape the fragment is, and it is a plain
 * varchar(50) with no constraint — Page Builder CK's own list screen exposes it
 * only through a `type:` search prefix (administrator/models/elements.php:41-42).
 *
 * This lists metadata and the byte size of `htmlcode`. It deliberately never
 * returns the markup: the library on a mature site holds whole rows, and a
 * listing that dragged every fragment through the response would be unusable.
 * Use get_pagebuilderck_element for one element's content.
 */
final class ListElementsTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	public function getName(): string { return 'list_pagebuilderck_elements'; }

	public function getDescription(): string
	{
		return 'List saved elements from #__pagebuilderck_elements — Page Builder CK\'s "My Elements" '
			. 'library of reusable blocks, columns and rows. '
			. 'A saved element is a COPY of a fragment of page markup, not a live reference: no page '
			. 'records which element it came from, so changing an element here does not change any page '
			. 'built from it, and deleting one does not break any page. '
			. 'Filters: search (substring of title), type (the vendor\'s free-text varchar(50) shape '
			. 'label — there is no fixed vocabulary and no constraint on it), state '
			. '(published/unpublished/trashed/archived or the integer), catid, limit (default 50, max '
			. '200) and offset. Note that Page Builder CK\'s own list screen hides anything with state '
			. '<= -1 (administrator/models/elements.php:49); this tool shows every state unless you '
			. 'filter, so trashed elements the UI has hidden will appear here. '
			. 'Returns the htmlcode BYTE SIZE only, never the markup itself — the library commonly holds '
			. 'whole multi-column rows and a listing that returned their content would be enormous. Use '
			. 'get_pagebuilderck_element to read one. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string', 'description' => 'Case-insensitive substring match on title.'],
				'type'   => ['type' => 'string', 'description' => 'Exact match on the type column, e.g. row, block. Free text — no fixed vocabulary.'],
				'state'  => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer (1, 0, -2, 2). Omit for any state.'],
				'catid'  => ['type' => 'string', 'description' => 'Page Builder CK category id. The column is a varchar, so this is matched as a string.'],
				'limit'  => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset' => ['type' => 'integer', 'description' => 'Rows to skip.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('elements')) {
			return $this->pbckMissingTableError('elements');
		}

		$table  = $this->db->quoteName($this->pbckTable('elements'));
		$limit  = $this->pbckLimit($arguments);
		$offset = $this->pbckOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('title') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		if (($type = trim((string) ($arguments['type'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('type') . ' = ' . $this->db->quote($type);
		}

		// catid is a varchar(255), so it is compared as a string. Casting it to
		// int here would match '' against 0 and return every uncategorised row.
		if (($catid = trim((string) ($arguments['catid'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('catid') . ' = ' . $this->db->quote($catid);
		}

		$state = $this->pbckNormaliseState($arguments['state'] ?? null);

		if ($state !== null) {
			$where[] = $this->db->quoteName('state') . ' = ' . $state;
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		// LENGTH() rather than the column — see the class docblock.
		$select = [
			$this->db->quoteName('id'),
			$this->db->quoteName('title'),
			$this->db->quoteName('description'),
			$this->db->quoteName('type'),
			$this->db->quoteName('ordering'),
			$this->db->quoteName('state'),
			$this->db->quoteName('catid'),
			$this->db->quoteName('checked_out'),
			'LENGTH(' . $this->db->quoteName('htmlcode') . ') AS htmlcode_bytes',
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('ordering') . ' ASC, ' . $this->db->quoteName('id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$elements = [];

		foreach ($rows as $row) {
			$entry = [
				'id'             => (int) $row['id'],
				'title'          => (string) $row['title'],
				'description'    => $this->pbckPreview((string) ($row['description'] ?? ''), 200),
				'type'           => (string) ($row['type'] ?? ''),
				'ordering'       => (int) ($row['ordering'] ?? 0),
				'state'          => $this->pbckStateLabel((int) ($row['state'] ?? 0)),
				'catid'          => (string) ($row['catid'] ?? ''),
				'htmlcode_bytes' => (int) $row['htmlcode_bytes'],
			];

			$checkedOut = $this->pbckCheckedOutBy($row);

			if ($checkedOut !== null) {
				$entry['checked_out_by'] = $checkedOut;
			}

			if ((int) $row['htmlcode_bytes'] === 0) {
				$entry['warning'] = 'This element has no markup. Dropping it into a page inserts nothing. '
					. 'It is usually the result of a save that ran before the editor had serialised the '
					. 'fragment.';
			}

			$elements[] = $entry;
		}

		$response = [
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'showing'  => \count($elements),
			'elements' => $elements,
			'note'     => 'Saved elements are copies, not references. Editing one here has no effect on '
				. 'pages already built from it, and deleting one cannot break a page.',
			'content_note' => 'htmlcode is reported as a byte count only. Call get_pagebuilderck_element '
				. 'for the markup of a single element.',
			'component' => $this->pbckEditionNotice(),
		];

		$response['types'] = $this->typeCounts();

		return ToolResult::json($response);
	}

	/**
	 * The distinct `type` values in use, so a caller can filter without guessing
	 * at a vocabulary the vendor never defined.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function typeCounts(): array
	{
		$rows = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('type') . ', COUNT(*) AS total FROM '
			. $this->db->quoteName($this->pbckTable('elements'))
			. ' GROUP BY ' . $this->db->quoteName('type')
			. ' ORDER BY total DESC'
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[] = [
				'type'  => (string) $row['type'],
				'count' => (int) $row['total'],
			];
		}

		return $out;
	}
}
