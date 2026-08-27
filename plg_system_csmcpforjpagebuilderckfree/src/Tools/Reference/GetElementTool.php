<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\User\User;

/**
 * One row of `#__pagebuilderck_elements`, with its markup.
 *
 * `htmlcode` is stored with the site root collapsed to the `|URIROOT|` token and
 * expanded on read — the vendor's own models do exactly this
 * (administrator/models/page.php:50). We match that, so what comes back here is
 * what the renderer would see, and we say which form was returned rather than
 * leaving the caller to work out why a URL looks different from the database.
 *
 * The `lossless` flag is the important field. An element whose markup does not
 * survive a parse/serialise round-trip byte for byte cannot be safely mutated by
 * these tools — any write would silently alter markup nobody asked to change —
 * so it is reported up front rather than discovered at write time.
 */
final class GetElementTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	public function getName(): string { return 'get_pagebuilderck_element'; }

	public function getDescription(): string
	{
		return 'Read one saved element from #__pagebuilderck_elements, including its htmlcode. '
			. 'The markup is returned EXPANDED: Page Builder CK stores internal URLs with the site root '
			. 'collapsed to the |URIROOT| token and expands it on read, and this matches that, so what '
			. 'you get back is what the renderer sees. The raw tokenised form is available by passing '
			. 'expand_uriroot = false. '
			. 'Reports `lossless` — whether the markup survives a parse and re-serialise byte for byte '
			. 'under Page Builder CK\'s own bundled simple_html_dom. A false here means no tool in this '
			. 'add-on will agree to mutate the element, because the round-trip would rewrite markup that '
			. 'was not asked to change. '
			. 'Also returns a structural outline (rows, columns, blocks and their data-type values) and '
			. 'flags any block whose type has no enabled pagebuilderck plugin on this site. That last '
			. 'point matters: Page Builder CK does not blank an unknown block type, it renders the inner '
			. 'markup as static HTML with the CSS still applied and no error anywhere, so a fragment can '
			. 'look almost right while being inert. '
			. 'Read-only. Saved elements are copies with no link back to any page.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'Element id from #__pagebuilderck_elements.',
				],
				'expand_uriroot' => [
					'type'        => 'boolean',
					'description' => 'Default true. When false, htmlcode is returned exactly as stored, with |URIROOT| still tokenised.',
				],
				'include_content' => [
					'type'        => 'boolean',
					'description' => 'Default true. When false, only metadata, sizes and the outline are returned.',
				],
			],
			'required'             => ['id'],
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

		$id = (int) ($arguments['id'] ?? 0);

		if ($id <= 0) {
			return ToolResult::json(['ok' => false, 'error' => 'id must be a positive integer.'], true);
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->pbckTable('elements')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No element with id ' . $id . ' in ' . $this->pbckTable('elements') . '.',
			], true);
		}

		$expand  = ($arguments['expand_uriroot'] ?? true) !== false;
		$include = ($arguments['include_content'] ?? true) !== false;

		// The stored bytes are what a write would have to reproduce, so the
		// lossless probe runs against them, not against the expanded form.
		$stored   = (string) ($row['htmlcode'] ?? '');
		$expanded = $this->pbckExpandRoot($stored);

		$enabledTypes = $this->pbckEnabledAddonTypes();

		$element = [
			'id'             => (int) $row['id'],
			'title'          => (string) ($row['title'] ?? ''),
			'description'    => (string) ($row['description'] ?? ''),
			'type'           => (string) ($row['type'] ?? ''),
			'ordering'       => (int) ($row['ordering'] ?? 0),
			'state'          => $this->pbckStateLabel((int) ($row['state'] ?? 0)),
			'catid'          => (string) ($row['catid'] ?? ''),
			'stored_bytes'   => \strlen($stored),
			'expanded_bytes' => \strlen($expanded),
			'uriroot_tokenised' => str_contains($stored, '|URIROOT|'),
		];

		$checkedOut = $this->pbckCheckedOutBy($row);

		if ($checkedOut !== null) {
			$element['checked_out_by'] = $checkedOut;
		}

		$lossless = $this->pbckIsLossless($stored);

		$element['lossless'] = $lossless;

		if (!$lossless) {
			$element['lossless_warning'] = 'This element\'s markup does NOT survive a parse and '
				. 're-serialise byte for byte. It is safe to read but not safe to edit — any write would '
				. 'silently alter markup that was not asked to change, so the write tools in this add-on '
				. 'will refuse it. Either the content is larger than the 8 MB parse ceiling, or it '
				. 'contains constructs Page Builder CK\'s own bundled parser cannot reproduce exactly.';
		}

		$outline = $this->pbckOutline($expanded, $enabledTypes);

		$element['outline'] = $outline;

		$summary = $this->summariseOutline($outline);

		$element['summary'] = $summary;

		if ($summary['unknown_types'] !== []) {
			$element['addon_warning'] = sprintf(
				'This element contains block type(s) %s with no enabled `pagebuilderck` plugin on this '
					. 'site. Page Builder CK does NOT blank such a block — site/models/page.php:645-646 '
					. 'returns \'\' from renderElement(), and :575 then falls through to $e->innertext, so '
					. 'the raw inner markup renders as static HTML with its CSS still applied, with no '
					. 'error and no log entry. Dropping this element into a page produces something that '
					. 'looks almost right and is inert.',
				implode(', ', array_map(static fn ($t) => '"' . $t . '"', $summary['unknown_types']))
			);
		}

		if ($include) {
			$element['htmlcode'] = $expand ? $expanded : $stored;
			$element['htmlcode_form'] = $expand
				? 'expanded — |URIROOT| replaced with the live site root'
				: 'as stored — |URIROOT| left tokenised';
		}

		return ToolResult::json([
			'ok'      => true,
			'element' => $element,
			'note'    => 'Saved elements are standalone copies. Nothing records which pages were built '
				. 'from this element, so editing or deleting it cannot affect any existing page.',
			'component' => $this->pbckEditionNotice(),
		]);
	}

	/**
	 * Flatten the outline into counts a caller can act on without walking the
	 * tree themselves.
	 *
	 * @param  array<string,mixed> $outline
	 * @return array<string,mixed>
	 */
	private function summariseOutline(array $outline): array
	{
		$rows     = 0;
		$columns  = 0;
		$blocks   = 0;
		$types    = [];
		$unknown  = [];

		$walk = function (array $rowList) use (&$walk, &$rows, &$columns, &$blocks, &$types, &$unknown): void {
			foreach ($rowList as $row) {
				$rows++;

				foreach ($row['columns'] ?? [] as $column) {
					$columns++;

					foreach ($column['blocks'] ?? [] as $block) {
						$blocks++;

						$type = (string) ($block['type'] ?? '');

						if ($type !== '') {
							$types[$type] = ($types[$type] ?? 0) + 1;
						}

						if (isset($block['addon_missing']) && $type !== '' && !\in_array($type, $unknown, true)) {
							$unknown[] = $type;
						}
					}

					if (($column['nested_rows'] ?? []) !== []) {
						$walk($column['nested_rows']);
					}
				}
			}
		};

		$walk($outline['rows'] ?? []);

		arsort($types);

		return [
			'rows'          => $rows,
			'columns'       => $columns,
			'blocks'        => $blocks,
			'block_types'   => $types,
			'unknown_types' => $unknown,
		];
	}
}
