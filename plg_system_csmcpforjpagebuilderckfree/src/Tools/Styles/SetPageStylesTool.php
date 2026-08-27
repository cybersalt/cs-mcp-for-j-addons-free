<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Set the list of `#__pagebuilderck_styles` rows a page opts into.
 *
 * This is a CONTENT write, not a column write, and that is the whole point of
 * the tool. There is no `styles` column on `#__pagebuilderck_pages`: the
 * vendor's admin model reads one at `administrator/models/page.php:49` and
 * writes one back at `:81-86`, but the property never exists on the loaded row
 * and the write is silently dropped. An UPDATE against a `styles` column would
 * fail outright; an attempt to go through the vendor model would appear to
 * succeed and change nothing.
 *
 * The association that actually works is a comma-separated id list in the
 * `data-styles` attribute of `div.pagebuilderckparams`, a page-level singleton
 * inside `htmlcode`. The front end reads exactly that
 * (`site/models/page.php:215-218`), so that is what this tool edits — which
 * means it goes through the full content write guard chain: round-trip
 * losslessness, root collapsing, validation, and refusal on any fatal.
 */
final class SetPageStylesTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	public function getName(): string { return 'set_pagebuilderck_page_styles'; }

	public function getDescription(): string
	{
		return 'Set which Page Builder CK styles a page opts into. Arguments: page_id (required) and '
			. 'style_ids (required array of ids; pass an empty array to detach every style). '
			. 'This edits PAGE CONTENT, not a column. There is no `styles` column on #__pagebuilderck_pages — '
			. 'the vendor\'s admin model reads and writes one (administrator/models/page.php:49 and :81-86) but '
			. 'it does not exist and the write is silently dropped. The real association is the data-styles '
			. 'attribute on the div.pagebuilderckparams singleton inside htmlcode, which is what the front end '
			. 'reads (site/models/page.php:215-218). If that div is absent it is created at the top of the '
			. 'page, immediately after .googlefontscall, where the builder puts it. '
			. 'Every id given is checked against #__pagebuilderck_styles first and the whole call is refused if '
			. 'any of them does not exist, so a typo cannot leave a page pointing at nothing. Ids that exist '
			. 'but are trashed are accepted with a warning, because the front end loads styles with '
			. '`state > -1` (site/models/page.php:931) and will skip them. '
			. 'Runs the full content write guard chain: refuses if the page\'s existing markup does not survive '
			. 'a parse/serialise round-trip byte for byte, collapses the site root back to |URIROOT|, '
			. 'validates the result and refuses on any fatal problem. Pass dry_run to preview.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'   => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'style_ids' => [
					'type'        => 'array',
					'items'       => ['type' => 'integer'],
					'description' => 'The complete list of style ids this page should use. This REPLACES the '
						. 'existing list rather than adding to it. An empty array detaches every style.',
				],
				'dry_run'   => ['type' => 'boolean', 'description' => 'Report the result without writing.'],
			],
			'required'             => ['page_id', 'style_ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		if (!\array_key_exists('style_ids', $arguments) || !\is_array($arguments['style_ids'])) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'style_ids is required and must be an array of integers. Pass [] to detach every '
					. 'style — there is no separate "clear" argument, because the list is always written whole.',
			], true);
		}

		$pageId = $this->requirePositiveInt($arguments, 'page_id');

		// --- normalise the requested ids --------------------------------------
		$wanted = [];

		foreach ($arguments['style_ids'] as $raw) {
			if (!is_numeric($raw) || (int) $raw <= 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Every entry in style_ids must be a positive integer.',
				], true);
			}

			$id = (int) $raw;

			if (!\in_array($id, $wanted, true)) {
				$wanted[] = $id;
			}
		}

		$duplicates = \count($arguments['style_ids']) - \count($wanted);

		// --- every id must exist ----------------------------------------------
		$warnings = [];
		$resolved = [];

		if ($wanted !== []) {
			if (!$this->pbckTableExists('styles')) {
				return $this->pbckMissingTableError('styles');
			}

			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('id'),
						$this->db->quoteName('title'),
						$this->db->quoteName('state'),
						'LENGTH(' . $this->db->quoteName('stylecode') . ') AS stylecode_bytes',
					])
					->from($this->db->quoteName($this->pbckTable('styles')))
					->whereIn($this->db->quoteName('id'), $wanted)
			)->loadAssocList('id') ?: [];

			$missing = [];

			foreach ($wanted as $id) {
				if (!isset($rows[$id])) {
					$missing[] = $id;

					continue;
				}

				$state = (int) $rows[$id]['state'];

				$entry = [
					'id'      => $id,
					'title'   => (string) $rows[$id]['title'],
					'state'   => $this->pbckStateLabel($state),
					'renders' => $state > -1,
				];

				if ($state === -2) {
					$warnings[] = sprintf(
						'Style %d ("%s") is trashed. The page will list it, but loadStyles() filters on '
							. '`state > -1` (site/models/page.php:931) so its CSS will not be applied.',
						$id,
						(string) $rows[$id]['title']
					);
				} elseif ($state !== 1) {
					$warnings[] = sprintf(
						'Style %d ("%s") is %s, and it WILL still render. loadStyles() filters on `state > -1`, '
							. 'so only a trashed style is skipped.',
						$id,
						(string) $rows[$id]['title'],
						$this->pbckStateLabel($state)
					);
				}

				if ((int) $rows[$id]['stylecode_bytes'] === 0) {
					$warnings[] = sprintf(
						'Style %d ("%s") has an empty stylecode, so it injects nothing whatever its state. '
							. 'stylecode is derived from htmlcode on save '
							. '(administrator/models/style.php:88).',
						$id,
						(string) $rows[$id]['title']
					);
				}

				$resolved[] = $entry;
			}

			if ($missing !== []) {
				return ToolResult::json([
					'ok'      => false,
					'error'   => sprintf(
						'Style id(s) %s do not exist in %s, so nothing was written. Page Builder CK ignores a '
							. 'dead style id silently — no error, no log entry, no visible difference — so '
							. 'writing one would create a fault that is invisible until someone wonders why '
							. 'the page looks wrong. Refusing the whole call rather than writing the valid '
							. 'ids only.',
						implode(', ', $missing),
						$this->pbckTable('styles')
					),
					'missing' => $missing,
					'valid'   => array_values(array_diff($wanted, $missing)),
				], true);
			}
		}

		// --- load the page -----------------------------------------------------
		$page = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('title')
			. ', ' . $this->db->quoteName('htmlcode') . ', ' . $this->db->quoteName('checked_out')
			. ' FROM ' . $this->db->quoteName($this->pbckTable('pages'))
			. ' WHERE ' . $this->db->quoteName('id') . ' = ' . $pageId
		)->loadAssoc();

		if (!$page) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('No row with id %d in %s.', $pageId, $this->pbckTable('pages')),
			], true);
		}

		$html = (string) ($page['htmlcode'] ?? '');

		if ($html !== '' && !$this->pbckIsLossless($html)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'The stored content of page %d does not survive a parse/serialise round-trip byte for '
						. 'byte, so editing the data-styles attribute would silently alter markup nobody asked '
						. 'to change. Refusing. The style association only exists inside htmlcode, so there is '
						. 'no column to write instead.',
					$pageId
				),
			], true);
		}

		$previous = $this->pbckGetPageStyleIds($html);
		$value    = implode(',', $wanted);

		$edit = $this->pbckWriteStyleAttribute($html, $value);

		if (isset($edit['error'])) {
			return ToolResult::json(['ok' => false, 'error' => $edit['error']], true);
		}

		$updated = $this->pbckCollapseRoot($edit['html']);

		$enabledTypes = array_merge($this->pbckEnabledAddonTypes(), $this->pbckCoreTypes());
		$problems     = $this->pbckValidate($updated, $enabledTypes);

		if ($this->pbckHasFatal($problems)) {
			$before = $this->pbckValidate($html, $enabledTypes);

			return ToolResult::json([
				'ok'                    => false,
				'error'                 => 'The page would have a fatal structural problem after this change, so nothing was '
					. 'written.',
				'problems_after'        => $problems,
				'problems_before'       => $before,
				'already_broken_before' => $this->pbckHasFatal($before),
			], true);
		}

		foreach ($problems as $problem) {
			if (($problem['severity'] ?? '') === 'warning') {
				$warnings[] = $problem['path'] . ': ' . $problem['problem'];
			}
		}

		if ($duplicates > 0) {
			$warnings[] = sprintf(
				'%d duplicate id(s) in style_ids were collapsed. The front end would have loaded each '
					. 'duplicate\'s CSS more than once.',
				$duplicates
			);
		}

		sort($previous);
		$sortedWanted = $wanted;
		sort($sortedWanted);

		$result = [
			'ok'           => true,
			'page'         => ['id' => $pageId, 'title' => (string) $page['title']],
			'previous_ids' => $previous,
			'style_ids'    => $wanted,
			'styles'       => $resolved,
			'unchanged'    => $previous === $sortedWanted,
			'attribute'    => [
				'element' => 'div.pagebuilderckparams',
				'name'    => 'data-styles',
				'value'   => $value,
				'action'  => $edit['action'],
			],
			'not_a_column' => 'This was written into the page\'s htmlcode, because there is no `styles` column '
				. 'on #__pagebuilderck_pages. The vendor\'s admin model reads and writes one '
				. '(administrator/models/page.php:49, :81-86) but it does not exist and that write is silently '
				. 'dropped.',
		];

		if ($wanted === []) {
			$result['note'] = 'data-styles is now empty, so this page opts into no styles at all. The '
				. 'div.pagebuilderckparams singleton is left in place — the builder expects it, and the front '
				. 'end strips it at render either way.';
		}

		if ($warnings !== []) {
			$result['warnings'] = $warnings;
		}

		if (($checkedOut = $this->pbckCheckedOutBy($page)) !== null) {
			$result['checked_out'] = sprintf(
				'This page is checked out to user %d. Page Builder CK never releases a checkout '
					. 'automatically, and if that session is still open its next Save will overwrite this '
					. 'change with the content it loaded beforehand.',
				$checkedOut
			);
		}

		if ($arguments['dry_run'] ?? false) {
			$result['dry_run']       = true;
			$result['written']       = false;
			$result['content_bytes'] = \strlen($updated);
			$result['component']     = $this->pbckEditionNotice();

			return ToolResult::json($result);
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->pbckTable('pages')))
			->set($this->db->quoteName('htmlcode') . ' = ' . $this->db->quote($updated))
			->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(Factory::getDate()->toSql()))
			->where($this->db->quoteName('id') . ' = ' . $pageId);

		$this->db->setQuery($query)->execute();

		$result['written']       = true;
		$result['content_bytes'] = \strlen($updated);

		if (($clobber = $this->pbckEditorClobberNotice(['htmlcode', 'modified'])) !== null) {
			$result['editor_clobber'] = $clobber;
		}

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}

	/**
	 * Write `$value` into `div.pagebuilderckparams[data-styles]`, creating the
	 * singleton when the page has none.
	 *
	 * @return array{html?:string, action?:string, error?:string}
	 */
	private function pbckWriteStyleAttribute(string $html, string $value): array
	{
		// The full singleton, with the two colour-palette attributes at the
		// defaults the builder emits. Omitting them would leave the palette
		// pickers with nothing to read.
		$singleton = '<div class="pagebuilderckparams" data-colorpalettefromtemplate=""'
			. ' data-colorpalettefromsettings=",,,," data-styles="' . $value . '"></div>';

		if ($html === '') {
			return [
				'html'   => $singleton,
				'action' => 'created div.pagebuilderckparams on a page that had no content at all',
			];
		}

		$dom = $this->pbckParse($html);

		if ($dom === null) {
			return ['error' => 'The page content could not be parsed, so it cannot be edited safely.'];
		}

		$nodes = $dom->find('div.pagebuilderckparams') ?: [];

		if ($nodes !== []) {
			foreach ($nodes as $node) {
				$node->setAttribute('data-styles', $value);
			}

			$updated = $this->pbckSerialise($dom, $html);
			$dom->clear();

			return [
				'html'   => $updated,
				'action' => \count($nodes) === 1
					? 'updated the existing div.pagebuilderckparams'
					: sprintf(
						'updated all %d div.pagebuilderckparams elements on this page — it is meant to be a '
							. 'singleton, and the front end reads every one it finds',
						\count($nodes)
					),
			];
		}

		// Absent. It belongs at the top, directly after .googlefontscall, which
		// is the order the builder emits its page-level singletons in.
		$anchor = $dom->find('div.googlefontscall', 0);

		if ($anchor !== null) {
			$anchor->outertext = $anchor->outertext . $singleton;
			$updated           = $this->pbckSerialise($dom, $html);
			$dom->clear();

			return ['html' => $updated, 'action' => 'created div.pagebuilderckparams after .googlefontscall'];
		}

		$first = $dom->find('div', 0);

		if ($first !== null) {
			$anchor            = $first;
			$anchor->outertext = $singleton . $anchor->outertext;
			$updated           = $this->pbckSerialise($dom, $html);
			$dom->clear();

			return [
				'html'   => $updated,
				'action' => 'created div.pagebuilderckparams before the first element (this page has no '
					. '.googlefontscall singleton either)',
			];
		}

		$dom->clear();

		return [
			'html'   => $singleton . $html,
			'action' => 'created div.pagebuilderckparams at the very start (this page contains no elements)',
		];
	}
}
