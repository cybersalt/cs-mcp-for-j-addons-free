<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools;

\defined('_JEXEC') or die;

/**
 * Outline layouts: finding layout.yaml, reading Gantry's version-2 layout
 * format, resolving inheritance between outlines, and the narrow edits the
 * write tools make. Used through Gantry5BootTrait.
 *
 * FORMAT. A version-2 layout.yaml (Component/Layout/Version/Format2.php) has
 * three maps: `layout` (the tree of sections, grids and blocks, with particles
 * as strings like "logo-9608 15" = particle id + block size), `structure`
 * (per-section settings, keyed by section id) and `content` (per-particle
 * settings, keyed by particle id: title, attributes, block, inherit). A
 * particle's settings therefore live in `content.<id>.attributes`, apart from
 * the tree, and the write tools change only that entry, so the tree is never
 * rebuilt or re-serialised by us.
 *
 * WHICH FILE. Layout::load() (Component/Layout/Layout.php:1053-1089) takes the
 * first gantry-config://<outline>/layout.yaml (custom/ first); with none, the
 * preset named in the outline's index.yaml, else the style's `preset` param,
 * read from gantry-layouts:// (the theme's layouts/, then the engine's).
 * There is no merging of layout files between outlines: inheritance is
 * explicit, per section or particle, through `inherit`.
 *
 * INHERITANCE. An item with `inherit: {outline: X, include: [...]}` takes
 * from outline X's item of the same id (or `inherit.particle`):
 * `attributes` (its settings), `block` (its block's settings except size and
 * fixed) and, for a section, `children` (everything inside it). Inherited
 * children get new ids in this outline, recorded in this outline's index.yaml
 * `inherit` map; Layout::initReferences() picks the FIRST id mapped to the
 * source id (array_search, Layout.php:899-904). If the source outline or item
 * is gone, Gantry silently drops the inheritance and the item renders with no
 * settings at all (Layout.php:860-865). check_gantry5_health reports that.
 */
trait Gantry5LayoutTrait
{
	/** Format2::$sections and $structures. */
	private const G5_SECTION_TYPES = ['wrapper', 'container', 'section', 'grid', 'block', 'offcanvas'];

	private const G5_STRUCTURE_TAGS = ['div', 'section', 'aside', 'nav', 'article', 'header', 'footer', 'main'];

	private int $g5Seq = 0;

	// -----------------------------------------------------------------------
	// Finding the layout
	// -----------------------------------------------------------------------

	/**
	 * @return array{file:string,kind:string,preset:?string,custom:bool,data:array}
	 */
	protected function g5LayoutSource(array $theme, string $folder): array
	{
		$custom = str_replace('\\', '/', $this->g5CustomPath($theme)) . '/';

		foreach ($this->g5ConfigDirs($theme, $folder) as $dir) {
			if (is_file($dir . '/layout.yaml')) {
				$data = $this->g5ParseYamlFile($dir . '/layout.yaml');

				return [
					'file'   => $dir . '/layout.yaml',
					'kind'   => 'outline',
					'preset' => isset($data['preset']['name']) ? (string) $data['preset']['name'] : null,
					'custom' => str_starts_with(str_replace('\\', '/', $dir) . '/', $custom),
					'data'   => $data,
				];
			}
		}

		$preset = null;

		foreach ($this->g5ConfigDirs($theme, $folder) as $dir) {
			if (is_file($dir . '/index.yaml')) {
				$index  = $this->g5ParseYamlFile($dir . '/index.yaml');
				$preset = isset($index['preset']['name']) ? (string) $index['preset']['name'] : null;
				break;
			}
		}

		if ($preset === null || $preset === '') {
			// Outlines::preset(): a style id resolves to its `preset` param; anything else is its own preset.
			$preset = $folder;

			if (ctype_digit($folder)) {
				$styles = $this->g5Styles((string) $theme['element']);
				$preset = (string) ($styles[(int) $folder]['preset'] ?? 'default');
			}
		}

		$file = $this->g5PresetFile($theme, $preset);

		if ($file === null) {
			throw new \RuntimeException('Outline "' . $folder . '" has no layout.yaml and its preset "' . $preset . '" was not found in any layouts/ folder.');
		}

		$data = $this->g5ParseYamlFile($file);

		// LayoutReader::preset() stamps the preset's name and file time.
		$data['preset'] = (\is_array($data['preset'] ?? null) ? $data['preset'] : []);
		$data['preset']['name']      = $preset;
		$data['preset']['timestamp'] = (int) filemtime($file);

		return ['file' => $file, 'kind' => 'preset', 'preset' => $preset, 'custom' => false, 'data' => $data];
	}

	/** gantry-layouts:// = gantry-theme://layouts then gantry-engine://layouts. */
	protected function g5PresetFile(array $theme, string $preset): ?string
	{
		if (!$this->g5SafeName($preset)) {
			return null;
		}

		$dirs = [];

		foreach ($this->g5ThemePaths($theme) as $path) {
			$dirs[] = $path . '/layouts';
		}

		$dirs[] = $this->g5EnginePath($theme) . '/layouts';

		foreach ($dirs as $dir) {
			if (is_file($dir . '/' . $preset . '.yaml')) {
				return $dir . '/' . $preset . '.yaml';
			}
		}

		return null;
	}

	/** The outline's index.yaml `inherit` map: source outline => [id here => source id]. */
	protected function g5IndexInheritMap(array $theme, string $folder): array
	{
		foreach ($this->g5ConfigDirs($theme, $folder) as $dir) {
			if (is_file($dir . '/index.yaml')) {
				try {
					$index = $this->g5ParseYamlFile($dir . '/index.yaml');
				} catch (\Throwable $e) {
					return [];
				}

				return \is_array($index['inherit'] ?? null) ? $index['inherit'] : [];
			}
		}

		return [];
	}

	// -----------------------------------------------------------------------
	// Parsing (Format2::load(), without the random ids)
	// -----------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>> top-level nodes
	 */
	protected function g5ParseLayout(array $data, string $label = 'layout.yaml'): array
	{
		$version = $data['version'] ?? ((isset($data['children']) && \is_array($data['children'])) ? 0 : 1);

		if ((int) $version !== 2) {
			throw new \RuntimeException(
				$label . ' is in Gantry\'s legacy layout format ' . $version . '. Open the outline\'s Layout in Gantry\'s admin and save it '
				. 'once to convert it to format 2; these tools read and write format 2 only.'
			);
		}

		$this->g5Seq = 0;
		$result      = [];
		$noParent    = null;

		foreach ((array) ($data['layout'] ?? []) as $field => $params) {
			$params = \is_array($params) ? $params : [];
			$node   = $this->g5ParseNode((string) $field, $params, 0, $noParent, $data);
			unset($node['size']);
			$result[] = $node;
		}

		return $result;
	}

	protected function g5ParseNode(string $field, array $content, int $scope, ?array &$parent, array $data): array
	{
		if (ctype_digit($field)) {
			$type   = $scope === 0 ? 'grid' : 'block';
			$result = ['id' => $type . '-#' . (++$this->g5Seq), 'type' => $type, 'subtype' => $type, 'layout' => true, 'attributes' => []];
			$scope  = ($scope + 1) % 2;
		} else {
			[$type, $subtype, $id, $size, $sectionId, $boxed] = $this->g5ParseSectionString($field);

			if ($type === 'grid') {
				$scope = 1;
			}

			if ($type === 'block') {
				$scope = 0;
			}

			$structure = \is_array($data['structure'][$sectionId] ?? null) ? $data['structure'][$sectionId] : [];
			$result    = $structure + [
				'id'         => $sectionId,
				'layout'     => true,
				'type'       => $type,
				'subtype'    => $subtype,
				'title'      => $this->g5Title($type, $subtype, $id),
				'attributes' => [],
			];
			$result['attributes'] = \is_array($result['attributes']) ? $result['attributes'] : [];

			if ($boxed !== null && !isset($result['attributes']['boxed'])) {
				$result['attributes']['boxed'] = $boxed;
			}

			if ($parent !== null && $parent['type'] === 'block' && !empty($result['block']) && \is_array($result['block'])) {
				$parent['attributes'] = $result['block'] + $parent['attributes'];
			}

			unset($result['block']);

			if ($size) {
				$result['size'] = $size;
			}
		}

		if ($content !== []) {
			$result['children'] = [];

			foreach ($content as $child => $params) {
				if (!$params && !\is_array($params)) {
					$params = [];
				}

				if (\is_array($params)) {
					$node = $this->g5ParseNode((string) $child, $params, $scope, $result, $data);
				} else {
					$node = $this->g5ResolveContent((string) $params, $scope, $result, $data);
				}

				if (!empty($node['size'])) {
					$result['attributes']['size'] = $node['size'];
				}

				unset($node['size']);
				$result['children'][] = $node;
			}
		}

		return $result;
	}

	protected function g5ResolveContent(string $field, int $scope, array &$parent, array $data): array
	{
		[$type, $subtype, $id, $size, $contentId, $stableId] = $this->g5ParseContentString($field);

		$result = \is_array($data['content'][$contentId] ?? null) ? $data['content'][$contentId] : [];
		$result += ['id' => $contentId, 'title' => $this->g5Title($type, $subtype, $id), 'type' => $type, 'subtype' => $subtype, 'attributes' => []];

		$result['attributes'] = (\is_array($result['attributes']) ? $result['attributes'] : []) + ['enabled' => 1];
		$result['id_is_stable'] = $stableId;

		$block = \is_array($result['block'] ?? null) ? $result['block'] : [];
		unset($result['block']);

		if ($type === 'position' && !isset($result['attributes']['key']) && !\in_array($subtype, ['module', 'widget'], true)) {
			$result['attributes']['key'] = $id;
		}

		// Format2::resolve(): a content item always sits in its own block (and grid at scope 0).
		$wrapped = ['id' => 'block-#' . (++$this->g5Seq), 'type' => 'block', 'subtype' => 'block', 'layout' => true, 'children' => [$result], 'attributes' => $block];

		if ($size) {
			$wrapped['attributes']['size'] = $size;
		}

		if ($scope === 0) {
			$wrapped = ['id' => 'grid-#' . (++$this->g5Seq), 'type' => 'grid', 'subtype' => 'grid', 'layout' => true, 'children' => [$wrapped], 'attributes' => []];
		}

		return $wrapped;
	}

	/** Format2::parseSectionString(): "[/]section-id[/] [size]". */
	protected function g5ParseSectionString(string $string): array
	{
		$list      = explode(' ', $string, 2);
		$sectionId = (string) array_shift($list);
		$size      = ((float) array_shift($list)) ?: null;

		$boxedLeft  = $sectionId !== '' && $sectionId[0] === '/';
		$boxedRight = $sectionId !== '' && $sectionId[\strlen($sectionId) - 1] === '/';
		$boxed      = ($boxedLeft && $boxedRight ? '' : ($boxedLeft ? '1' : ($boxedRight ? '0' : null)));
		$sectionId  = trim($sectionId, '/');

		$list    = explode('-', $sectionId, 2);
		$section = (string) reset($list);
		$type    = \in_array($section, self::G5_SECTION_TYPES, true) ? $section : 'section';
		$subtype = ($type !== 'section' || \in_array($section, self::G5_STRUCTURE_TAGS, true)) ? $section : 'section';

		$id = ($type === 'section' && \in_array($section, self::G5_STRUCTURE_TAGS, true)) ? array_pop($list) : $sectionId;

		return [$type, $subtype, (string) $id, $size, $sectionId, $boxed];
	}

	/**
	 * Format2::parseContentString(): "[type-]subtype[-id] [size]". Gantry gives an
	 * item without a numeric id a RANDOM id on every load (Format2::id()), so
	 * such ids are flagged unstable and are not writable.
	 */
	protected function g5ParseContentString(string $string): array
	{
		$list      = explode(' ', $string, 2);
		$contentId = (string) array_shift($list);
		$size      = ((float) array_shift($list)) ?: null;

		$list = explode('-', $contentId);
		$type = (string) reset($list);
		$test = (string) end($list);
		$id   = ((string) (int) $test === $test) ? array_pop($list) : null;

		if (\in_array($type, ['system', 'position', 'particle', 'spacer'], true)) {
			array_shift($list);
		} else {
			$type = 'particle';
		}

		$subtype = implode('-', $list);
		$stable  = $id !== null;

		if ($type === 'position' && !\in_array($subtype, ['module', 'widget'], true)) {
			$id      = ($subtype ?: $type) . ($id !== null ? '-' . $id : '');
			$subtype = 'position';
			$stable  = true;
		}

		return [$type, $subtype ?: $type, $id, $size, $contentId, $stable];
	}

	/** Format2::getTitle(). */
	protected function g5Title(string $type, string $subtype, ?string $id): ?string
	{
		if (\in_array($type, self::G5_SECTION_TYPES, true)) {
			if ($type === 'offcanvas') {
				return 'Offcanvas';
			}

			if ($type === 'grid' || $type === 'block') {
				return null;
			}

			return ucfirst((string) ((string) (int) $id === (string) $id ? ($subtype ?: $type) . '-' . $id : $id));
		}

		if ($type === 'position' && !\in_array($subtype, ['module', 'widget'], true)) {
			return ucfirst((string) preg_replace('/^position-(.*?[a-z])/ui', '\1', (string) $id));
		}

		if ($type === 'system') {
			if ($subtype === 'messages') {
				return 'System Messages';
			}

			if ($subtype === 'content') {
				return 'Page Content';
			}
		}

		return ucfirst($subtype ?: $type);
	}

	// -----------------------------------------------------------------------
	// Resolving inheritance
	// -----------------------------------------------------------------------

	/**
	 * An outline's layout with every `inherit` applied, as Gantry renders it.
	 *
	 * @return array{source:array,nodes:array,problems:array<int,string>}
	 */
	protected function g5ResolvedLayout(array $theme, string $folder, int $depth = 0, array $seen = []): array
	{
		if ($depth > 8 || \in_array($folder, $seen, true)) {
			throw new \RuntimeException('Layout inheritance loops or nests deeper than 8 outlines: ' . implode(' > ', array_merge($seen, [$folder])) . '.');
		}

		$source   = $this->g5LayoutSource($theme, $folder);
		$nodes    = $this->g5ParseLayout($source['data'], $this->g5Rel($source['file']));
		$map      = $this->g5IndexInheritMap($theme, $folder);
		$problems = [];
		$cache    = [];

		$this->g5ApplyInheritance($theme, $folder, $nodes, $map, $cache, $problems, $depth, array_merge($seen, [$folder]));

		return ['source' => $source, 'nodes' => $nodes, 'problems' => $problems];
	}

	protected function g5ApplyInheritance(array $theme, string $folder, array &$nodes, array $map, array &$cache, array &$problems, int $depth, array $seen): void
	{
		foreach ($nodes as &$node) {
			$inherit = \is_array($node['inherit'] ?? null) ? $node['inherit'] : null;

			if ($inherit !== null && !empty($inherit['outline']) && !empty($inherit['include'])) {
				$from     = (string) $inherit['outline'];
				$sourceId = !empty($inherit['particle']) ? (string) $inherit['particle'] : (string) $node['id'];
				$include  = (array) $inherit['include'];
				$srcNode  = null;
				$srcBlock = null;

				/*
				 * An outline id that is no longer a template style (deleted in Joomla's
				 * Template Styles, which does not update other outlines' inheritance the
				 * way Gantry's own delete does). Layout::load() then falls back to the
				 * style's preset ("default" for a missing style) and Layout::loadIndex(…,
				 * true) WRITES custom/config/<id>/layout.yaml and index.yaml on the next
				 * page view (Layout.php:1063-1074, 1117-1160): seen live on mcpfree. We
				 * resolve it the same way for display, and report it.
				 */
				if (!isset($this->g5Outlines($theme)[$from])) {
					$problems[] = $node['id'] . ' inherits from outline "' . $from . '", which is not an outline of ' . $theme['element']
						. ' (its template style was deleted?). Gantry falls back to that id\'s preset and creates custom/config/' . $from
						. '/ on the next page view; re-point the inheritance in Gantry\'s Layout editor.';
					$node['inherit_source_missing'] = $from;
				}

				if ($this->g5SafeName($from)) {
					try {
						$cache[$from] ??= $this->g5ResolvedLayout($theme, $from, $depth + 1, $seen);
						[$srcNode, $srcBlock] = $this->g5FindNode($cache[$from]['nodes'], $sourceId);
					} catch (\Throwable $e) {
						$problems[] = $node['id'] . ' inherits from outline "' . $from . '", which cannot be read: ' . $e->getMessage();
					}
				}

				if ($srcNode === null) {
					// Layout::initInheritance(): inheritance is dropped and, for attributes, the item keeps none.
					$problems[] = $node['id'] . ' inherits from ' . $from . '/' . $sourceId . ', which does not exist; Gantry drops the inheritance'
						. (\in_array('attributes', $include, true) ? ' and the item renders with NO settings of its own' : '');
					$node['inherit_broken'] = ['outline' => $from, 'id' => $sourceId];

					if (\in_array('attributes', $include, true)) {
						$node['attributes'] = !\in_array($node['type'], self::G5_SECTION_TYPES, true) ? ['enabled' => 1] : [];
					}
				} else {
					$node['inherited_from'] = ['outline' => $from, 'id' => $sourceId, 'include' => array_values($include)];

					if (\in_array('attributes', $include, true)) {
						$node['attributes'] = $srcNode['attributes'] ?? [];
					}

					if (\in_array('block', $include, true) && $srcBlock !== null) {
						$node['inherited_block'] = array_diff_key($srcBlock['attributes'] ?? [], ['fixed' => 1, 'size' => 1]);
					}

					if (\in_array('children', $include, true)) {
						// The source subtree is already resolved in its own outline; do not re-apply inheritance to it.
						$node['children']           = $this->g5AdoptChildren($srcNode['children'] ?? [], $from, \is_array($map[$from] ?? null) ? $map[$from] : []);
						$node['children_inherited'] = true;
					}
				}
			}

			if (!empty($node['children']) && empty($node['children_inherited'])) {
				$this->g5ApplyInheritance($theme, $folder, $node['children'], $map, $cache, $problems, $depth, $seen);
			}
		}

		unset($node);
	}

	/**
	 * Children copied from another outline's section: each content item gets
	 * the id this outline's index.yaml maps to it (first match, as Gantry does)
	 * and is marked as inheriting its attributes and block.
	 */
	protected function g5AdoptChildren(array $children, string $from, array $map): array
	{
		foreach ($children as &$child) {
			unset($child['inherit']);

			if (!\in_array($child['type'], self::G5_SECTION_TYPES, true) && empty($child['layout'])) {
				$sourceId = (string) $child['id'];
				$newId    = array_search($sourceId, $map, true);

				$child['inherited_from']   = ($child['inherited_from'] ?? null) ?: ['outline' => $from, 'id' => $sourceId, 'include' => ['attributes', 'block']];
				$child['via_section']      = true;
				$child['id']               = $newId !== false ? (string) $newId : $sourceId;
				$child['id_is_stable']     = $newId !== false || !empty($child['id_is_stable']);
				$child['id_note']          = $newId !== false ? null : 'not in this outline\'s index.yaml; Gantry assigns it an id at runtime';
			} elseif (\in_array($child['type'], ['section', 'offcanvas'], true)) {
				$child['via_section'] = true;
			}

			if (!empty($child['children'])) {
				$child['children'] = $this->g5AdoptChildren($child['children'], $from, $map);
			}
		}

		unset($child);

		return $children;
	}

	/**
	 * Find an item by id. Returns [node, its parent block or null].
	 *
	 * @return array{0:?array,1:?array}
	 */
	protected function g5FindNode(array $nodes, string $id, ?array $parent = null): array
	{
		foreach ($nodes as $node) {
			if ((string) ($node['id'] ?? '') === $id) {
				return [$node, $parent !== null && $parent['type'] === 'block' ? $parent : null];
			}

			if (!empty($node['children'])) {
				$found = $this->g5FindNode($node['children'], $id, $node);

				if ($found[0] !== null) {
					return $found;
				}
			}
		}

		return [null, null];
	}

	/**
	 * Every content item (particle, position, module instance, system item,
	 * spacer) of a resolved layout, flattened, with the section it sits in.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function g5LayoutItems(array $nodes, array $sections = [], ?array $block = null): array
	{
		$out = [];

		foreach ($nodes as $node) {
			$isLayout = !empty($node['layout']) || \in_array($node['type'], self::G5_SECTION_TYPES, true);

			if (!$isLayout) {
				$out[] = [
					'id'             => (string) $node['id'],
					'type'           => (string) $node['type'],
					'subtype'        => (string) $node['subtype'],
					'title'          => $node['title'] ?? null,
					'section'        => $sections !== [] ? end($sections) : null,
					'section_path'   => $sections,
					'size'           => isset($block['attributes']['size']) ? $block['attributes']['size'] + 0 : null,
					'block'          => array_diff_key(($node['inherited_block'] ?? []) + ($block['attributes'] ?? []), ['size' => 1, 'fixed' => 1]),
					'attributes'     => $node['attributes'] ?? [],
					'enabled'        => !isset($node['attributes']['enabled']) || (bool) $node['attributes']['enabled'],
					'inherited_from' => $node['inherited_from'] ?? null,
					'via_section'    => !empty($node['via_section']),
					'inherit_broken' => $node['inherit_broken'] ?? null,
					'id_is_stable'   => !empty($node['id_is_stable']),
					'id_note'        => $node['id_note'] ?? null,
				];
				continue;
			}

			if (!empty($node['children'])) {
				$path = \in_array($node['type'], ['section', 'offcanvas', 'container', 'wrapper'], true) ? array_merge($sections, [(string) $node['id']]) : $sections;
				$out  = array_merge($out, $this->g5LayoutItems($node['children'], $path, $node['type'] === 'block' ? $node : null));
			}
		}

		return $out;
	}

	/**
	 * A compact tree for get_gantry5_layout: sections and content items, with
	 * grids and blocks folded into the items' size.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function g5CompactTree(array $nodes, ?array $block = null): array
	{
		$out = [];

		foreach ($nodes as $node) {
			$type = (string) $node['type'];

			if ($type === 'grid' || $type === 'block') {
				foreach ($this->g5CompactTree($node['children'] ?? [], $type === 'block' ? $node : null) as $c) {
					$out[] = $c;
				}

				continue;
			}

			$item = ['id' => (string) $node['id'], 'type' => $type];

			if (($node['subtype'] ?? $type) !== $type) {
				$item['subtype'] = (string) $node['subtype'];
			}

			if (!empty($node['title'])) {
				$item['title'] = (string) $node['title'];
			}

			if (isset($block['attributes']['size'])) {
				$item['size'] = $block['attributes']['size'] + 0;
			}

			if (!empty($node['inherited_from'])) {
				$item['inherited_from'] = $node['inherited_from']['outline'] . '/' . $node['inherited_from']['id'];
			}

			if (!empty($node['inherit_broken'])) {
				$item['inherit_broken'] = $node['inherit_broken'];
			}

			if (\in_array($type, ['section', 'offcanvas', 'container', 'wrapper'], true)) {
				$attrs = array_filter((array) ($node['attributes'] ?? []), static fn ($v) => $v !== '' && $v !== null && $v !== []);

				if ($attrs !== []) {
					$item['attributes'] = $attrs;
				}

				$item['children'] = $this->g5CompactTree($node['children'] ?? []);
			} else {
				if (isset($node['attributes']['enabled']) && !$node['attributes']['enabled']) {
					$item['enabled'] = false;
				}

				if ($type === 'position') {
					foreach (['key', 'module_id', 'chrome'] as $k) {
						if (isset($node['attributes'][$k]) && $node['attributes'][$k] !== '') {
							$item[$k] = $node['attributes'][$k];
						}
					}
				}
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * The outlines a raw layout names in `inherit` (sections and items), with
	 * how many entries name each. Cheap: no resolving.
	 *
	 * @return array<string,int>
	 */
	protected function g5RawInheritSources(array $data): array
	{
		$out = [];

		foreach (['structure', 'content'] as $part) {
			foreach ((array) ($data[$part] ?? []) as $entry) {
				if (\is_array($entry) && !empty($entry['inherit']['outline']) && !empty($entry['inherit']['include'])) {
					$o       = (string) $entry['inherit']['outline'];
					$out[$o] = ($out[$o] ?? 0) + 1;
				}
			}
		}

		return $out;
	}

	/**
	 * Outlines whose layout takes something from $folder: any item (or, with
	 * $id, that item) inherited from it, directly or through a section.
	 *
	 * @return array<string,string[]> outline id => ids of the inheriting items there
	 */
	protected function g5InheritedBy(array $theme, string $folder, ?string $id = null): array
	{
		$out = [];

		foreach ($this->g5Outlines($theme) as $oid => $outline) {
			$f = $this->g5OutlineFolder($theme, $outline)['folder'];

			if ($f === $folder) {
				continue;
			}

			try {
				$items = $this->g5LayoutItems($this->g5ResolvedLayout($theme, $f)['nodes']);
			} catch (\Throwable $e) {
				continue;
			}

			foreach ($items as $item) {
				if (($item['inherited_from']['outline'] ?? null) === $folder && ($id === null || $item['inherited_from']['id'] === $id)) {
					$out[(string) $oid][] = $item['id'];
				}
			}
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Raw edits (the write tools)
	// -----------------------------------------------------------------------

	/**
	 * Every content id written in a raw layout's tree (not the inherited ones).
	 *
	 * @return string[]
	 */
	protected function g5RawContentIds(mixed $tree): array
	{
		$out = [];

		if (!\is_array($tree)) {
			return $out;
		}

		foreach ($tree as $key => $value) {
			if (\is_array($value)) {
				$out = array_merge($out, $this->g5RawContentIds($value));
			} elseif (\is_string($value) && $value !== '') {
				$out[] = (string) explode(' ', $value, 2)[0];
			}
		}

		return $out;
	}

	/**
	 * Patch one item's attributes in a raw layout. Mirrors what Format2::build()
	 * would store: `enabled` is dropped when true, empty attributes and empty
	 * content entries are removed.
	 *
	 * @param array<string,mixed> $set   dotted key => value
	 * @param string[]            $unset dotted keys
	 * @return array{data:array,changed:array<string,array{before:mixed,after:mixed}>}
	 */
	protected function g5PatchRawAttributes(array $data, string $id, array $set, array $unset): array
	{
		if (!\in_array($id, $this->g5RawContentIds($data['layout'] ?? []), true)) {
			throw new \InvalidArgumentException('Particle "' . $id . '" is not in this layout file.');
		}

		$entry = \is_array($data['content'][$id] ?? null) ? $data['content'][$id] : [];
		$attrs = \is_array($entry['attributes'] ?? null) ? $entry['attributes'] : [];

		if (!empty($entry['inherit']['outline']) && \in_array('attributes', (array) ($entry['inherit']['include'] ?? []), true)) {
			throw new \RuntimeException('Particle "' . $id . '" inherits its settings from outline "' . $entry['inherit']['outline'] . '". Nothing was written.');
		}

		$changed = [];

		foreach ($unset as $key) {
			$path   = explode('.', (string) $key);
			$before = $this->g5GetPath($attrs, $path);

			if ($this->g5UnsetPath($attrs, $path)) {
				$changed[$key] = ['before' => $before, 'after' => null];
			}
		}

		foreach ($set as $key => $value) {
			$path   = explode('.', (string) $key);
			$before = $this->g5GetPath($attrs, $path);

			$this->g5SetPath($attrs, $path, $value);
			$changed[$key] = ['before' => $before, 'after' => $value];
		}

		if (isset($attrs['enabled']) && !empty($attrs['enabled']) && (string) $attrs['enabled'] !== '0') {
			unset($attrs['enabled']);
		}

		if ($attrs === []) {
			unset($entry['attributes']);
		} else {
			$entry['attributes'] = $attrs;
		}

		if ($entry === []) {
			unset($data['content'][$id]);

			if (isset($data['content']) && $data['content'] === []) {
				unset($data['content']);
			}
		} else {
			$data['content']      = \is_array($data['content'] ?? null) ? $data['content'] : [];
			$data['content'][$id] = $entry;
		}

		return ['data' => $data, 'changed' => $changed];
	}

	/**
	 * Turn a copied layout into one that inherits every section from $from, the
	 * way Layout::inheritAll() + clean() + Format2::build() leave it (compare
	 * the "Default" outline Gantry's installer writes: every section inherits
	 * attributes, block and children from the Base Outline).
	 */
	protected function g5InheritAllRaw(array $data, string $from): array
	{
		$structure = \is_array($data['structure'] ?? null) ? $data['structure'] : [];
		$content   = \is_array($data['content'] ?? null) ? $data['content'] : [];

		$data['layout'] = $this->g5InheritTree((array) ($data['layout'] ?? []), $from, $structure, $content);

		$data['structure'] = $structure;
		$data['content']   = $content;

		if ($data['structure'] === []) {
			unset($data['structure']);
		}

		if ($data['content'] === []) {
			unset($data['content']);
		}

		return $data;
	}

	protected function g5InheritTree(array $tree, string $from, array &$structure, array &$content): array
	{
		$out = [];

		foreach ($tree as $key => $value) {
			if (\is_string($key) && !ctype_digit($key)) {
				[$type, , , $size, $sectionId] = $this->g5ParseSectionString($key);

				if ($type === 'section' || $type === 'offcanvas') {
					$entry   = \is_array($structure[$sectionId] ?? null) ? $structure[$sectionId] : [];
					$keep    = array_intersect_key($entry, ['type' => 1, 'subtype' => 1, 'title' => 1]);
					$block   = \is_array($entry['block'] ?? null) ? array_intersect_key($entry['block'], ['fixed' => 1, 'size' => 1]) : [];
					$newItem = $keep + ['inherit' => ['outline' => $from, 'include' => ['attributes', 'block', 'children']]];

					if ($block !== []) {
						$newItem['block'] = $block;
					}

					$structure[$sectionId] = $newItem;

					// Attributes (boxed included) now come from the source; drop the "/x/" markers with them.
					$out[trim($sectionId . ' ' . ($size !== null ? $this->g5SizeText($size) : ''))] = [];

					foreach ($this->g5RawContentIds($value) as $cid) {
						unset($content[$cid]);
					}

					continue;
				}

				$out[$key] = \is_array($value) ? $this->g5InheritTree($value, $from, $structure, $content) : $value;
				continue;
			}

			if (\is_array($value)) {
				$out[$key] = $this->g5InheritTree($value, $from, $structure, $content);
			} elseif (\is_string($value) && $value !== '') {
				// A content item outside any section: inherit its attributes and block.
				$cid   = (string) explode(' ', $value, 2)[0];
				$entry = \is_array($content[$cid] ?? null) ? $content[$cid] : [];

				$content[$cid] = array_intersect_key($entry, ['title' => 1, 'type' => 1, 'subtype' => 1])
					+ ['inherit' => ['outline' => $from, 'include' => ['attributes', 'block']]];
				$out[$key] = $value;
			} else {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	protected function g5SizeText(float $size): string
	{
		return rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.');
	}
}
