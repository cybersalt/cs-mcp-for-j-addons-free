<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

/**
 * Gantry's "Duplicate outline" (Framework/Outlines.php duplicate() and create(),
 * Component/Outline/OutlineCollection.php:499-536) without booting Gantry:
 *
 *   1. a new #__template_styles row for the theme, titled "<Theme> - <title>",
 *      with params {"preset": <the source layout's preset>, "configuration":
 *      <new id>} (StyleHelper::update() sets exactly these two). Setting
 *      `configuration` matters: without it Gantry's outline list would later
 *      copy the preset's folder over this one (Outlines::load(), lines 104-111);
 *   2. custom/config/<new id>/layout.yaml: the source layout, either as a copy or
 *      with every section inheriting from the source (Layout::inheritAll());
 *   3. for a source other than the Base Outline, the source's other config
 *      files (styles, particle defaults, page settings), as Folder::copy() does
 *      with index/layout/assignments excluded. A Base Outline duplicate gets
 *      none, so it keeps inheriting those settings.
 *
 * index.yaml is not written: Gantry rebuilds it from layout.yaml the first time
 * the outline is used (Layout::loadIndex()).
 */
final class CreateOutlineTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'create_gantry5_outline'; }

	public function getDescription(): string
	{
		return 'Create a new outline (a new template style of the theme) by duplicating an existing one, as Gantry\'s Outlines page '
			. 'does. inherit=false copies the source layout; inherit=true makes every section inherit from the source, so later '
			. 'changes to the source flow through (the way Gantry\'s own "Default" outline inherits from the Base Outline). '
			. 'Duplicating a normal outline also copies its styles and particle defaults; duplicating the Base Outline copies only '
			. 'the layout. The new outline is assigned to no page: use set_gantry5_outline_assignments. Needs core.create and '
			. 'core.edit on com_templates.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'          => $this->g5ThemeProp(),
				'title'          => ['type' => 'string', 'description' => 'Outline title, e.g. "Landing page". The style is named "<Theme> - <title>".'],
				'source_outline' => ['type' => 'string', 'description' => 'Outline to duplicate. Default "default" (the Base Outline).'],
				'inherit'        => ['type' => 'boolean', 'description' => 'Make every section inherit from the source instead of copying it. Default false.'],
			],
			'required'             => ['title'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor, true);

		if (!$actor->authorise('core.create', 'com_templates')) {
			throw new \RuntimeException('Creating an outline creates a template style, which needs core.create on com_templates (Gantry: outline.create).');
		}

		$theme   = $this->g5RequireTheme($arguments);
		$title   = trim((string) ($arguments['title'] ?? ''));
		$inherit = !empty($arguments['inherit']);

		if ($title === '' || preg_match('/[<>]/', $title)) {
			throw new \InvalidArgumentException('title is required and may not contain < or >.');
		}

		$styleTitle = $this->g5StyleTitlePrefix($theme) . $title;

		if (mb_strlen($styleTitle) > 255) {
			throw new \InvalidArgumentException('The style title would be ' . mb_strlen($styleTitle) . ' characters; #__template_styles.title holds 255. Refusing rather than truncating.');
		}

		foreach ($this->g5Styles((string) $theme['element']) as $style) {
			if (mb_strtolower($style['title']) === mb_strtolower($styleTitle)) {
				throw new \InvalidArgumentException('The theme already has a style titled "' . $styleTitle . '" (id ' . $style['id'] . ').');
			}
		}

		$source       = $this->g5RequireOutline($theme, $arguments['source_outline'] ?? 'default', 'source_outline');
		$sourceFolder = $this->g5OutlineFolder($theme, $source)['folder'];
		$layout       = $this->g5LayoutSource($theme, $sourceFolder);

		// Parse it once now so a broken or legacy source fails before anything is created.
		$this->g5ParseLayout($layout['data'], $this->g5Rel($layout['file']));

		$data   = $inherit ? $this->g5InheritAllRaw($layout['data'], $sourceFolder) : $layout['data'];
		$preset = (string) ($data['preset']['name'] ?? ($layout['preset'] ?? 'default')) ?: 'default';

		// 1. The template style.
		$query = $this->db->getQuery(true)
			->insert($this->db->quoteName('#__template_styles'))
			->columns($this->db->quoteName(['template', 'client_id', 'home', 'title', 'inheritable', 'parent', 'params']))
			->values(implode(',', [
				$this->db->quote((string) $theme['element']), '0', $this->db->quote('0'), $this->db->quote($styleTitle), '0', $this->db->quote(''), $this->db->quote('{}'),
			]));
		$this->db->setQuery($query)->execute();
		$id = (int) $this->db->insertid();

		if ($id <= 0) {
			throw new \RuntimeException('Creating the template style failed.');
		}

		$created    = [];
		$dirExisted = false;

		try {
			$params = json_encode(['preset' => $preset, 'configuration' => (string) $id]);

			$this->db->setQuery(
				$this->db->getQuery(true)
					->update($this->db->quoteName('#__template_styles'))
					->set($this->db->quoteName('params') . ' = ' . $this->db->quote((string) $params))
					->where($this->db->quoteName('id') . ' = ' . $id)
			)->execute();

			$dir = $this->g5CustomConfigDir($theme, (string) $id);

			if (file_exists($dir)) {
				$dirExisted = true;

				throw new \RuntimeException('A config folder ' . $this->g5Rel($dir) . ' already exists (left over from a deleted style?). Remove it first.');
			}

			// 2. The layout.
			$created[] = $this->g5WriteConfigFile($theme, (string) $id, 'layout.yaml', $data, 20)['file'];

			// 3. The other config files of a non-Base source.
			if ($sourceFolder !== 'default') {
				$srcDirs = $this->g5ConfigDirs($theme, $sourceFolder);
				$srcDir  = $srcDirs[0] ?? null;

				if ($srcDir !== null) {
					$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));

					foreach ($it as $f) {
						if (!$f->isFile() || !str_ends_with($f->getFilename(), '.yaml')) {
							continue;
						}

						$rel = str_replace('\\', '/', substr($f->getPathname(), \strlen($srcDir) + 1));

						if (preg_match('#^(index|layout|assignments)\.#', $rel)) {
							continue;
						}

						$created[] = $this->g5WriteConfigFile($theme, (string) $id, $rel, $this->g5ParseYamlFile($f->getPathname()), 5)['file'];
					}
				}
			}
		} catch (\Throwable $e) {
			// Roll back: the files we wrote, the folder, the style row.
			foreach ($created as $rel) {
				@unlink(JPATH_ROOT . '/' . $rel);
			}

			if (isset($dir) && !$dirExisted && is_dir($dir)) {
				$this->g5EmptyDir($dir);
				@rmdir($dir);
			}

			$this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__template_styles') . ' WHERE id = ' . $id)->execute();

			throw new \RuntimeException('Creating the outline failed and was rolled back: ' . $e->getMessage());
		}

		$this->g5ResetStyleCache();
		$cache = $this->g5ClearCache($theme);

		return ToolResult::json([
			'ok'             => true,
			'theme'          => $theme['element'],
			'outline'        => (string) $id,
			'style_id'       => $id,
			'style_title'    => $styleTitle,
			'style_params'   => ['preset' => $preset, 'configuration' => (string) $id],
			'source_outline' => $source['id'],
			'mode'           => $inherit ? 'inherit (every section inherits from the source)' : 'copy',
			'files_created'  => $created,
			'assigned_pages' => [],
			'next'           => 'Assign pages with set_gantry5_outline_assignments {"outline": "' . $id . '", "assign": [menu item ids]}. Undo: delete the style in Templates > Styles and remove ' . $this->g5Rel($this->g5CustomConfigDir($theme, (string) $id)) . '.',
			'cache'          => $cache,
		]);
	}
}
