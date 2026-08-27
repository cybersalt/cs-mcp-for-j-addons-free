<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * Which block types exist on this site, and which of them will actually work.
 *
 * ---------------------------------------------------------------------------
 * THE PLUGIN IS THE TYPE
 * ---------------------------------------------------------------------------
 *
 * Page Builder CK has no addon registry, no manifest of types, no directory to
 * scan. A block's `data-type` attribute is looked up directly as a plugin
 * element name in the `pagebuilderck` group:
 *
 *     PluginHelper::isEnabled('pagebuilderck', $type)     site/models/page.php:645
 *
 * So `#__extensions` IS the list of block types, and a plugin's `element` IS the
 * `data-type`. Nothing else defines one.
 *
 * ---------------------------------------------------------------------------
 * A MISSING TYPE FAILS OPEN — AND SILENTLY
 * ---------------------------------------------------------------------------
 *
 * This is the single most important thing to understand about this component,
 * and it is the opposite of how SP Page Builder behaves.
 *
 * When no enabled plugin provides a type, `renderElement()` returns `''`
 * (site/models/page.php:645-646). `replaceElement()` then treats that falsy
 * return as "nothing to substitute" and falls through to `return $e->innertext`
 * (:575) — the block's own inner markup, rendered verbatim as static HTML, with
 * its `#id`-scoped CSS from `.ckstyle` still applied.
 *
 * The result is a page that looks almost right. The text is there, the styling
 * is there, the layout is there. What is gone is everything the plugin did: the
 * slider does not slide, the tabs do not switch, the accordion does not open,
 * the form does not submit, the module does not get loaded. There is no error in
 * the page, nothing in the Joomla log, and nothing in the admin UI. The only way
 * to know is to cross-check `data-type` against the enabled plugin list, which
 * is what this tool exists to let you do.
 *
 * The one exception is a block with NO `data-type` at all, which takes the else
 * branch and renders a literal red "ERROR - PAGEBUILDER CK DEBUG : ELEMENT TYPE
 * NOT FOUND" paragraph into the page (:575-577).
 */
final class ListAddonsTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	public function getName(): string { return 'list_pagebuilderck_addons'; }

	public function getDescription(): string
	{
		return 'List the block types available on this site: every `pagebuilderck` group plugin with its '
			. 'enabled state, plus the three types the component handles itself with no plugin (row, '
			. 'rowinrow, readmore). '
			. 'A PLUGIN\'S ELEMENT NAME IS THE BLOCK data-type. There is no addon registry and no '
			. 'manifest of types — the renderer looks the data-type up directly with '
			. 'PluginHelper::isEnabled(\'pagebuilderck\', $type) at site/models/page.php:645, so '
			. '#__extensions is the authoritative list and a plugin\'s `element` column is the type '
			. 'string you will find in the markup. '
			. 'CRITICAL, AND THE OPPOSITE OF SP PAGE BUILDER: when no enabled plugin provides a type, '
			. 'Page Builder CK does NOT blank the block and does NOT show an error. renderElement() '
			. 'returns \'\' (site/models/page.php:645-646) and replaceElement() falls through to '
			. '$e->innertext (:575), so the block\'s inner markup renders as static HTML with its '
			. '#id-scoped CSS still applied. The page looks almost right — text, styling and layout all '
			. 'intact — while every behaviour the plugin provided is gone: the slider does not slide, '
			. 'the tabs do not switch, the form does not submit. Nothing appears in the page, in the '
			. 'Joomla log or in the admin UI. Cross-checking data-type against this list is the ONLY way '
			. 'to detect it. Use check_pagebuilderck_health to find affected pages. '
			. 'The single exception is a block with no data-type at all, which does render a visible red '
			. '"ELEMENT TYPE NOT FOUND" paragraph. '
			. 'Pass enabled_only to hide disabled plugins. Read-only — this does not enable, disable or '
			. 'install anything.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'enabled_only' => [
					'type'        => 'boolean',
					'description' => 'Default false. When true, only plugins that will actually render are listed.',
				],
				'search' => [
					'type'        => 'string',
					'description' => 'Case-insensitive substring match on the type (element) or the plugin name.',
				],
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

		$enabledOnly = ($arguments['enabled_only'] ?? false) === true;
		$search      = strtolower(trim((string) ($arguments['search'] ?? '')));

		$inventory = $this->pbckAddonInventory();

		$types    = [];
		$disabled = [];

		foreach ($inventory as $addon) {
			if ($enabledOnly && !$addon['enabled']) {
				continue;
			}

			if ($search !== ''
				&& !str_contains(strtolower($addon['type']), $search)
				&& !str_contains(strtolower($addon['name']), $search)) {
				continue;
			}

			$entry = [
				'data_type' => $addon['type'],
				'source'    => 'plugin',
				'plugin'    => $addon['name'],
				'enabled'   => $addon['enabled'],
				'renders'   => $addon['enabled'],
			];

			if (!$addon['enabled']) {
				$disabled[] = $addon['type'];

				$entry['warning'] = sprintf(
					'This plugin is DISABLED, so every block with data-type="%s" anywhere on this site '
						. 'renders as inert static HTML — visible, styled, and doing nothing — with no '
						. 'error in the page, the log or the admin UI.',
					$addon['type']
				);
			}

			$types[] = $entry;
		}

		$nested = $this->pbckNestedRowsEnabled();

		foreach ($this->pbckCoreTypes() as $type) {
			if ($search !== '' && !str_contains($type, $search)) {
				continue;
			}

			$entry = [
				'data_type' => $type,
				'source'    => 'core',
				'plugin'    => null,
				'enabled'   => true,
				'renders'   => true,
				'note'      => 'Handled by the component itself. No plugin is involved and it cannot be '
					. 'disabled.',
			];

			if ($type === 'rowinrow' && !$nested) {
				$entry['note'] = 'Handled by the component itself. The `nestedrows` component parameter '
					. 'is OFF, so the editor does not offer nested rows — but any nested row already in a '
					. 'page still renders normally. The parameter gates the UI, not the renderer.';
			}

			$types[] = $entry;
		}

		$enabledCount = 0;

		foreach ($types as $type) {
			if ($type['renders']) {
				$enabledCount++;
			}
		}

		$response = [
			'ok'           => true,
			'total'        => \count($types),
			'will_render'  => $enabledCount,
			'types'        => $types,
			'plugin_is_the_type' => 'A block\'s data-type attribute IS a plugin element name in the '
				. '`pagebuilderck` group. The renderer resolves it with '
				. 'PluginHelper::isEnabled(\'pagebuilderck\', $type) at site/models/page.php:645 — there '
				. 'is no separate addon registry, so #__extensions is the whole list.',
			'fails_open_warning' => 'When no enabled plugin provides a type, Page Builder CK does NOT '
				. 'blank the block. renderElement() returns \'\' (site/models/page.php:645-646) and '
				. 'replaceElement() falls through to $e->innertext (:575), so the inner markup renders '
				. 'verbatim as static HTML with its #id-scoped CSS still applied. The page looks almost '
				. 'right and is inert. There is no error in the page, no log entry and no admin '
				. 'indication. This is the opposite of SP Page Builder, which renders nothing for a '
				. 'missing addon — do not carry that assumption over.',
			'no_data_type_note' => 'The one loud failure: a block with no data-type at all takes the '
				. 'else branch and renders a literal red "ERROR - PAGEBUILDER CK DEBUG : ELEMENT TYPE '
				. 'NOT FOUND" paragraph into the page (site/models/page.php:575-577).',
			'component'    => $this->pbckEditionNotice(),
		];

		if ($disabled !== []) {
			$response['disabled_types'] = $disabled;
			$response['disabled_warning'] = sprintf(
				'%d installed addon plugin(s) are disabled: %s. Any existing block of those types is '
					. 'currently rendering as inert static HTML. Run check_pagebuilderck_health to find '
					. 'which pages are affected before enabling or removing anything.',
				\count($disabled),
				implode(', ', $disabled)
			);
		}

		if ($inventory === []) {
			$response['warning'] = 'No `pagebuilderck` plugins are installed at all. Only row, rowinrow '
				. 'and readmore will render with their real behaviour; every other block on the site is '
				. 'inert static HTML. This usually means the component was installed without its addon '
				. 'package.';
		}

		return ToolResult::json($response);
	}
}
