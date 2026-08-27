<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\User\User;

/**
 * Which `#__pagebuilderck_styles` rows a page has opted into.
 *
 * There is NO `styles` column on `#__pagebuilderck_pages`. The vendor's admin
 * model reads one — `administrator/models/page.php:49` does
 * `if (isset($this->item->styles)) $this->item->styles = explode(',', …)` — and
 * writes one back at `:81-86`, but the property never exists on the loaded row
 * and the write is silently dropped by the storage layer. Anyone reasoning from
 * that model code will conclude there is a column to query, and there is not.
 *
 * The real association is a comma-separated id list held in an ATTRIBUTE inside
 * the page's own markup: `div.pagebuilderckparams[data-styles]`. That is what
 * the front end reads (`site/models/page.php:215-218`) and it is the only place
 * the link exists. Which in turn means a page's styles cannot be found with SQL
 * — the content has to be parsed, which is what this tool does.
 */
final class GetPageStylesTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	public function getName(): string { return 'get_pagebuilderck_page_styles'; }

	public function getDescription(): string
	{
		return 'Report which Page Builder CK styles a page has opted into, resolved to titles and states. '
			. 'CRITICAL: there is no `styles` column on #__pagebuilderck_pages. The vendor\'s admin model reads '
			. 'and writes one (administrator/models/page.php:49 and :81-86) but the column does not exist and '
			. 'the write is silently dropped, so any SQL written against it will be wrong. The real '
			. 'association is a comma-separated id list in the data-styles attribute of the '
			. 'div.pagebuilderckparams singleton INSIDE htmlcode, which is what the front end reads '
			. '(site/models/page.php:215-218). '
			. 'Each id is resolved against #__pagebuilderck_styles and flagged when it points at a row that no '
			. 'longer exists, or at a trashed one — the front end loads styles with `state > -1` '
			. '(site/models/page.php:931), so an unpublished style STILL renders and only a trashed one is '
			. 'skipped. '
			. 'Also reports whether the .pagebuilderckparams singleton exists at all, since a page without one '
			. 'has no way to reference a style, and whether more than one exists, since the front end applies '
			. 'every one it finds.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id' => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
			],
			'required'             => ['page_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$pageId = $this->requirePositiveInt($arguments, 'page_id');

		$page = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('title')
			. ', ' . $this->db->quoteName('state') . ', ' . $this->db->quoteName('htmlcode')
			. ' FROM ' . $this->db->quoteName($this->pbckTable('pages'))
			. ' WHERE ' . $this->db->quoteName('id') . ' = ' . $pageId
		)->loadAssoc();

		if (!$page) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('No row with id %d in %s.', $pageId, $this->pbckTable('pages')),
			], true);
		}

		$html     = (string) ($page['htmlcode'] ?? '');
		$styleIds = $this->pbckGetPageStyleIds($html);
		$warnings = [];

		// Count the singleton and the raw attribute values separately from
		// pbckGetPageStyleIds(), which unions and de-duplicates them.
		$paramsCount = 0;
		$rawValues   = [];

		if ($html !== '') {
			$dom = $this->pbckParse($html);

			if ($dom === null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => sprintf(
						'The content of page %d could not be parsed, so its style association cannot be read. '
							. 'The association lives in an attribute inside htmlcode, not in a column, so there '
							. 'is no fallback.',
						$pageId
					),
				], true);
			}

			foreach ($dom->find('div.pagebuilderckparams') as $node) {
				$paramsCount++;
				$rawValues[] = (string) ($node->getAttribute('data-styles') ?? '');
			}

			$dom->clear();
		}

		if ($paramsCount === 0) {
			$warnings[] = 'This page has no div.pagebuilderckparams, so it cannot reference a style at all. '
				. 'The builder emits that div as a page-level singleton immediately after .googlefontscall. '
				. 'set_pagebuilderck_page_styles will create it if you assign styles.';
		} elseif ($paramsCount > 1) {
			$warnings[] = sprintf(
				'This page has %d div.pagebuilderckparams elements. It is meant to be a singleton, and the '
					. 'front end loops over every one it finds (site/models/page.php:215), so the effective '
					. 'style set is the union of them all. The builder will only ever update one of them.',
				$paramsCount
			);
		}

		// --- resolve ----------------------------------------------------------
		$styles  = [];
		$missing = [];
		$trashed = [];

		if ($styleIds !== [] && $this->pbckTableExists('styles')) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('id'),
						$this->db->quoteName('title'),
						$this->db->quoteName('state'),
						'LENGTH(' . $this->db->quoteName('stylecode') . ') AS stylecode_bytes',
					])
					->from($this->db->quoteName($this->pbckTable('styles')))
					->whereIn($this->db->quoteName('id'), $styleIds)
			)->loadAssocList('id') ?: [];

			foreach ($styleIds as $id) {
				if (!isset($rows[$id])) {
					$styles[] = [
						'id'      => $id,
						'exists'  => false,
						'problem' => 'No style row with this id. The page still lists it, and the front end '
							. 'silently ignores the id — nothing is logged and nothing appears in the page.',
					];
					$missing[] = $id;

					continue;
				}

				$row   = $rows[$id];
				$state = (int) $row['state'];

				$entry = [
					'id'              => $id,
					'exists'          => true,
					'title'           => (string) $row['title'],
					'state'           => $this->pbckStateLabel($state),
					'stylecode_bytes' => (int) $row['stylecode_bytes'],
					'renders'         => $state > -1,
				];

				if ($state === -2) {
					$entry['problem'] = 'This style is trashed. loadStyles() filters on `state > -1` '
						. '(site/models/page.php:931), so its CSS is NOT applied to this page even though the '
						. 'page still lists it.';
					$trashed[] = $id;
				} elseif ($state !== 1) {
					$entry['note'] = sprintf(
						'This style is %s, but it STILL renders on this page. loadStyles() filters on '
							. '`state > -1`, so only a trashed style is skipped. Unpublishing is not a way to '
							. 'switch a style off.',
						$this->pbckStateLabel($state)
					);
				}

				if ((int) $row['stylecode_bytes'] === 0) {
					$entry['inert'] = 'stylecode is empty, so this style injects nothing regardless of state. '
						. 'stylecode is derived from htmlcode on save (administrator/models/style.php:88).';
				}

				$styles[] = $entry;
			}
		} elseif ($styleIds !== []) {
			$warnings[] = sprintf(
				'%s does not exist on this site, so the ids listed by this page cannot be resolved.',
				$this->pbckTable('styles')
			);
		}

		$result = [
			'ok'        => true,
			'page'      => [
				'id'    => $pageId,
				'title' => (string) $page['title'],
				'state' => $this->pbckStateLabel((int) $page['state']),
			],
			'style_ids' => $styleIds,
			'styles'    => $styles,
			'source'    => [
				'where'  => 'div.pagebuilderckparams[data-styles] inside #__pagebuilderck_pages.htmlcode',
				'raw'    => $rawValues,
				'not_a_column' => 'There is NO `styles` column on #__pagebuilderck_pages. The vendor\'s admin '
					. 'model reads and writes one (administrator/models/page.php:49, :81-86), but the property '
					. 'never exists on the loaded row and the write is silently dropped. Do not query for it.',
			],
			'state_rule' => 'The front end loads a page\'s styles with `state > -1` '
				. '(site/models/page.php:931). Published, unpublished and archived styles all render; only '
				. 'trashed ones are skipped.',
		];

		if ($missing !== []) {
			$warnings[] = sprintf(
				'Style id(s) %s are listed by this page but no longer exist in %s. The reference is dead and '
					. 'is ignored without any error. Use set_pagebuilderck_page_styles to clean the list up.',
				implode(', ', $missing),
				$this->pbckTable('styles')
			);
		}

		if ($trashed !== []) {
			$warnings[] = sprintf(
				'Style id(s) %s are trashed, so their CSS is not applied even though the page still lists them.',
				implode(', ', $trashed)
			);
		}

		if ($warnings !== []) {
			$result['warnings'] = $warnings;
		}

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}
}
