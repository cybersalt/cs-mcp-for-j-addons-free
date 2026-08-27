<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * List rows in `#__pagebuilderck_styles`.
 *
 * A "style" is a reusable stylesheet a page can opt into. Each row holds two
 * columns: `htmlcode`, which is the authored source the style editor works on,
 * and `stylecode`, which the vendor regenerates from `htmlcode` on every save
 * (administrator/models/style.php:88) and which is what actually gets injected
 * into the front end.
 *
 * Both are MySQL `text`, not `longtext` — 65,535 bytes, and the installer's
 * schema is explicit about it (administrator/sql/install.mysql.utf8.sql:53,55).
 * MySQL in non-strict mode truncates an oversized value silently, at whatever
 * byte it happens to reach, so a style can be cut off mid-selector with no
 * error anywhere. This listing therefore reports the byte size of both columns
 * and warns as soon as either passes 90% of the limit, which is the last point
 * at which there is comfortable room to act.
 */
final class ListStylesTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	/** MySQL `text`. Both style columns use it. */
	private const TEXT_LIMIT = 65535;

	/** Warn from here up. Below this there is still room to grow the style. */
	private const WARN_AT = 58981; // 90% of 65,535

	public function getName(): string { return 'list_pagebuilderck_styles'; }

	public function getDescription(): string
	{
		return 'List Page Builder CK styles from #__pagebuilderck_styles. A style is a reusable stylesheet '
			. 'that pages opt into; it is not applied to anything by itself. '
			. 'Filters: search (substring of title), state (published | unpublished | trashed | archived, or '
			. 'the integer). Supports limit (default 50, max 200) and offset. '
			. 'Reports the byte size of both htmlcode and stylecode, and warns when either passes 90% of the '
			. '65,535-byte limit of its `text` column — MySQL truncates such a value silently in non-strict '
			. 'mode, cutting the stylesheet off at an arbitrary byte with no error in any log. '
			. 'Note on state: Page Builder CK\'s front end loads a page\'s styles with `state > -1` '
			. '(site/models/page.php:931), so an UNPUBLISHED style still renders. Only a trashed one (-2) is '
			. 'skipped. Unpublishing a style is not a way to switch it off. '
			. 'Returns no style content; use get_pagebuilderck_style for that.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string', 'description' => 'Case-insensitive substring match on title.'],
				'state'  => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer (1, 0, -2, 2). Omit for any state.'],
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

		if (!$this->pbckTableExists('styles')) {
			return $this->pbckMissingTableError('styles');
		}

		$table  = $this->db->quoteName($this->pbckTable('styles'));
		$limit  = $this->pbckLimit($arguments);
		$offset = $this->pbckOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('title') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		$state = $this->pbckNormaliseState($arguments['state'] ?? null);

		if ($state !== null) {
			$where[] = $this->db->quoteName('state') . ' = ' . $state;
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		// LENGTH(), not the columns themselves — a listing must not drag every
		// stylesheet on the site through the response.
		$select = [
			$this->db->quoteName('id'),
			$this->db->quoteName('title'),
			$this->db->quoteName('ordering'),
			$this->db->quoteName('state'),
			$this->db->quoteName('checked_out'),
			'LENGTH(' . $this->db->quoteName('htmlcode') . ') AS htmlcode_bytes',
			'LENGTH(' . $this->db->quoteName('stylecode') . ') AS stylecode_bytes',
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('ordering') . ' ASC, ' . $this->db->quoteName('id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$styles     = [];
		$atRisk     = [];
		$emptyDerived = [];

		foreach ($rows as $row) {
			$id        = (int) $row['id'];
			$htmlBytes = (int) $row['htmlcode_bytes'];
			$cssBytes  = (int) $row['stylecode_bytes'];

			$entry = [
				'id'              => $id,
				'title'           => (string) $row['title'],
				'state'           => $this->pbckStateLabel((int) $row['state']),
				'ordering'        => (int) $row['ordering'],
				'htmlcode_bytes'  => $htmlBytes,
				'stylecode_bytes' => $cssBytes,
			];

			if (($checkedOut = $this->pbckCheckedOutBy($row)) !== null) {
				$entry['checked_out_by'] = $checkedOut;
			}

			$sizeWarnings = [];

			foreach (['htmlcode' => $htmlBytes, 'stylecode' => $cssBytes] as $column => $bytes) {
				if ($bytes < self::WARN_AT) {
					continue;
				}

				$sizeWarnings[] = sprintf(
					'%s is %d bytes, %s%% of the %d-byte limit of its `text` column. MySQL truncates an '
						. 'oversized value silently in non-strict mode, so this stylesheet is close to being '
						. 'cut off mid-rule with no error anywhere. Split it across two style rows.',
					$column,
					$bytes,
					number_format($bytes / self::TEXT_LIMIT * 100, 1),
					self::TEXT_LIMIT
				);
			}

			if ($sizeWarnings !== []) {
				$entry['size_warning'] = $sizeWarnings;
				$atRisk[]              = $id;
			}

			// stylecode is derived from htmlcode on save. Source but no output
			// means the derivation produced nothing — usually because htmlcode
			// carries no .ckstyle or .ckcolumnwidth block for it to harvest.
			if ($htmlBytes > 0 && $cssBytes === 0) {
				$entry['no_derived_css'] = 'htmlcode has content but stylecode is empty. stylecode is what the '
					. 'front end actually injects, so this style currently does nothing. It is regenerated '
					. 'from htmlcode by getStylesFromHtml() on every save '
					. '(administrator/models/style.php:88), which only harvests <style> tags inside .ckstyle, '
					. '.ckstyleresponsive and .ckcolumnwidth wrappers — CSS written anywhere else in '
					. 'htmlcode is ignored. Re-saving the style in the vendor\'s editor rebuilds it.';
				$emptyDerived[] = $id;
			}

			$styles[] = $entry;
		}

		$result = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($styles),
			'filter'  => [
				'search' => $search === '' ? null : $search,
				'state'  => $state === null ? 'any' : $this->pbckStateLabel($state),
			],
			'styles'  => $styles,
			'note'    => 'stylecode is DERIVED from htmlcode by the vendor on every save and there is no setter '
				. 'for it. Edit htmlcode; stylecode follows. Also note that a style row is inert until a page '
				. 'opts into it — see get_pagebuilderck_page_styles and set_pagebuilderck_page_styles.',
		];

		if ($atRisk !== []) {
			$result['warning'] = sprintf(
				'Style id(s) %s are within 10%% of the 65,535-byte `text` column limit. See size_warning on '
					. 'each row.',
				implode(', ', $atRisk)
			);
		}

		if ($emptyDerived !== []) {
			$result['inert_styles'] = sprintf(
				'Style id(s) %s have htmlcode but no stylecode, so they inject nothing on the front end.',
				implode(', ', $emptyDerived)
			);
		}

		$result['column_limits'] = [
			'htmlcode'  => 'text, ' . self::TEXT_LIMIT . ' bytes',
			'stylecode' => 'text, ' . self::TEXT_LIMIT . ' bytes',
			'why'       => 'Unlike #__pagebuilderck_pages.htmlcode, which is longtext, both style columns are '
				. 'plain `text`. This is the vendor\'s installer schema, not a site misconfiguration.',
		];

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}
}
