<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

/**
 * Changes one instance's attributes in the outline's layout.yaml
 * (content.<id>.attributes) and nothing else in the file. The tree, other
 * particles, sections and the preset block are untouched, so this is not a
 * re-save of the layout the way Gantry's Layout editor does it.
 */
final class UpdateParticleTool extends AbstractTool
{
	use Gantry5BootTrait;
	use ParticleSupportTrait;

	public function getName(): string { return 'update_gantry5_particle'; }

	public function getDescription(): string
	{
		return 'Change the settings of ONE particle instance in an outline\'s layout (also Module Position "key"/"chrome" and Module '
			. 'Instance "module_id"/"chrome" items). Keys are validated against the particle\'s blueprint; unknown keys and bad values '
			. 'are refused. Writes only templates/<theme>/custom/config/<outline>/layout.yaml (creating it from the preset if the outline '
			. 'has none yet), backs the file up first (the backup id is returned; restore_gantry5_backup undoes it), keeps every other '
			. 'line of the layout, then clears Gantry\'s cache. Refuses an instance this outline INHERITS from another outline and '
			. 'names the outline to edit instead. Lists the other outlines that inherit this instance, which change with it. '
			. 'Use dry_run first to see the diff.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'id'      => ['type' => 'string', 'description' => 'Particle id from get_gantry5_layout, e.g. "logo-9608".'],
				'set'     => $this->g5SetProp('the particle\'s'),
				'unset'   => $this->g5UnsetProp('The instance then falls back to the outline\'s particle defaults for them.'),
				'dry_run' => $this->g5DryRunProp(),
			],
			'required'             => ['outline', 'id'],
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

		$theme   = $this->g5RequireTheme($arguments);
		$outline = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);
		$id      = $this->requireString($arguments, 'id');
		$set     = $this->g5SetList($arguments);
		$unset   = $this->g5UnsetList($arguments);
		$dryRun  = !empty($arguments['dry_run']);

		if ($set === [] && $unset === []) {
			throw new \InvalidArgumentException('Nothing to change: pass set and/or unset.');
		}

		$folder = $this->g5RequireWritableOutline($theme, $outline);
		$view   = $this->particleView($theme, $outline, $id);
		$item   = $view['item'];

		if (!empty($item['inherited_from'])) {
			$from = $item['inherited_from'];

			throw new \RuntimeException(
				'Outline ' . $outline['id'] . ' inherits "' . $id . '" from outline "' . $from['outline'] . '" (particle "' . $from['id'] . '"'
				. (!empty($item['via_section']) ? ', through its whole section' : '') . '), so its settings are not stored here. Edit it there: '
				. 'update_gantry5_particle {"outline": "' . $from['outline'] . '", "id": "' . $from['id'] . '"} (that also changes every '
				. 'other outline inheriting it). To give this outline its own copy, break the inheritance in Gantry\'s Layout editor. Nothing was written.'
			);
		}

		if (empty($item['id_is_stable'])) {
			throw new \RuntimeException('"' . $id . '" has no fixed id in the layout file (Gantry generates one on every load). Save the layout once in Gantry\'s admin. Nothing was written.');
		}

		if ($view['blueprint'] === null) {
			throw new \RuntimeException((string) $view['note'] . ' Nothing was written.');
		}

		$fields = $view['blueprint']['fields'];
		$what   = 'particle type "' . $view['type'] . '"';
		$values = $this->g5ValidateSet($fields, $set, $what);

		foreach ($unset as $key) {
			$found = false;
			$this->g5GetPath($view['stored'], explode('.', $key), $found);

			if (!isset($fields[$key]) && !$found) {
				throw new \InvalidArgumentException('Cannot unset "' . $key . '": it is neither a ' . $what . ' setting nor stored on this instance. Nothing was written.');
			}
		}

		$twigAfter = \array_key_exists('twig', $values) ? $values['twig']
			: (\in_array('twig', $unset, true) ? ($view['defaults']['data']['twig'] ?? null) : ($view['effective']['twig'] ?? null));
		$this->g5GuardTwig($actor, $fields, array_merge(array_keys($values), $unset), $twigAfter, 'This ' . $view['type'] . ' particle');

		$warnings = $this->particleWarnings($view, $values);

		$source       = $this->g5LayoutSource($theme, $folder);
		$materialized = !$source['custom'];
		$patch        = $this->g5PatchRawAttributes($source['data'], $id, $values, $unset);

		$result = [
			'ok'           => true,
			'dry_run'      => $dryRun,
			'theme'        => $theme['element'],
			'outline'      => $outline['id'],
			'id'           => $id,
			'particle_type' => $view['type'],
			'changes'      => $patch['changed'],
			'layout_file'  => $this->g5Rel($this->g5CustomConfigDir($theme, $folder) . '/layout.yaml'),
			'materialized_from' => $materialized ? $this->g5Rel($source['file']) : null,
			'warnings'     => $warnings,
		];

		$inheritedBy = $this->g5InheritedBy($theme, $folder, $id);

		if ($inheritedBy !== []) {
			$result['also_changes_outlines'] = $this->g5Map($inheritedBy);
		}

		if ($dryRun) {
			return ToolResult::json($result);
		}

		$styleFix = $this->g5EnsureStyleConfiguration($outline);
		$write    = $this->g5WriteConfigFile($theme, $folder, 'layout.yaml', $patch['data'], 20);
		$cache = $this->g5ClearCache($theme);

		$after = $this->particleView($theme, $outline, $id);
		$check = [];

		foreach (array_merge(array_keys($values), $unset) as $key) {
			$check[$key] = $after['settings'][$key] ?? ['value' => $this->g5GetPath($after['effective'], explode('.', $key))];
		}

		return ToolResult::json($result + [
			'file'          => $write['file'],
			'backup'        => $write['backup'],
			'backup_file'   => $write['backup_file'],
			'undo'          => 'restore_gantry5_backup {"backup": "' . $write['backup'] . '"}',
			'effective_now' => $check,
			'renders'       => $after['renders'],
			'style_fix'     => $styleFix,
			'cache'         => $cache,
		]);
	}

	/** Things the admin would do or warn about that a targeted write does not. */
	private function particleWarnings(array $view, array $values): array
	{
		$out = [];

		if ($view['item']['type'] === 'position' && $view['type'] === 'position' && isset($values['key'])) {
			$out[] = 'Changing a Module Position\'s key: Gantry\'s Layout editor would also add "' . $values['key'] . '" to the theme\'s '
				. 'templateDetails.xml and positions language file (EventListener::onLayoutSave()). Modules in that position render '
				. 'either way, but Joomla\'s Module Manager will not offer the name in its position list until the layout is saved once '
				. 'in Gantry\'s admin; type it in by hand meanwhile.';
		}

		if ($view['item']['type'] === 'position' && $view['type'] === 'module' && isset($values['module_id'])) {
			$row = $this->db->setQuery(
				$this->db->getQuery(true)
					->select(['id', 'published', 'client_id', 'title'])
					->from($this->db->quoteName('#__modules'))
					->where($this->db->quoteName('id') . ' = ' . (int) $values['module_id'])
			)->loadAssoc();

			if (!\is_array($row) || (int) $row['client_id'] !== 0) {
				throw new \InvalidArgumentException('module_id ' . $values['module_id'] . ' is not a site module. Nothing was written.');
			}

			if ((int) $row['published'] !== 1) {
				$out[] = 'Module ' . $row['id'] . ' "' . $row['title'] . '" is not published, so this Module Instance will render nothing.';
			}
		}

		return $out;
	}
}
