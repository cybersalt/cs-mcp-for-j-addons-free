<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Rewrites only params.particle.options.particle of one mod_gantry5_particle
 * module, by a named-column UPDATE of #__modules.params. com_modules'
 * ModuleModel::save() is not used: it would need the whole module form
 * (position, assignment, publishing) re-supplied, and this change touches none
 * of that. Access follows Gantry's own rule for particle modules: the Gantry
 * admin lets com_modules managers edit them (com_gantry5/gantry5.php:31).
 */
final class UpdateParticleModuleTool extends AbstractTool
{
	use Gantry5BootTrait;
	use ParticleModuleSupportTrait;

	public function getName(): string { return 'update_gantry5_particle_module'; }

	public function getDescription(): string
	{
		return 'Change the particle settings stored in one "Gantry 5 Particle" module (mod_gantry5_particle). Keys are validated '
			. 'against the particle type\'s blueprint (from `theme`, else the only Gantry theme, else the site default\'s); unknown '
			. 'keys and bad values are refused. Only the module\'s particle settings change: title, position, menu assignment and '
			. 'publishing are not touched (use core update_module for those). Returns the previous params so the change can be '
			. 'reverted. Needs core.edit on the module in com_modules.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'      => ['type' => 'integer', 'description' => 'Module id (list_gantry5_particle_modules).'],
				'theme'   => $this->g5ThemeProp(),
				'set'     => $this->g5SetProp('the particle\'s'),
				'unset'   => $this->g5UnsetProp('The module then uses the page outline\'s particle defaults for them.'),
				'dry_run' => $this->g5DryRunProp(),
			],
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('Gantry 5 tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', 'com_modules') || !$actor->authorise('core.edit', 'com_modules.module.' . $id)) {
			throw new \RuntimeException('Editing module ' . $id . ' needs core.manage on com_modules and core.edit on that module.');
		}

		$set    = $this->g5SetList($arguments);
		$unset  = $this->g5UnsetList($arguments);
		$dryRun = !empty($arguments['dry_run']);

		if ($set === [] && $unset === []) {
			throw new \InvalidArgumentException('Nothing to change: pass set and/or unset.');
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'module', 'position', 'published', 'ordering', 'access', 'language', 'showtitle', 'publish_up', 'publish_down', 'params', 'client_id', 'checked_out'])
				->from($this->db->quoteName('#__modules'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($row) || $row['module'] !== 'mod_gantry5_particle' || (int) $row['client_id'] !== 0) {
			throw new \InvalidArgumentException('Module ' . $id . ' is not a site "Gantry 5 Particle" module (mod_gantry5_particle). list_gantry5_particle_modules lists them.');
		}

		if ((int) $row['published'] === -2) {
			throw new \InvalidArgumentException('Module ' . $id . ' is trashed.');
		}

		if (!empty($row['checked_out']) && (int) $row['checked_out'] !== (int) $actor->id) {
			throw new \RuntimeException('Module ' . $id . ' is checked out by user ' . (int) $row['checked_out'] . '; their save would overwrite this change. Nothing was written.');
		}

		$params = json_decode((string) $row['params'], true);
		$params = \is_array($params) ? $params : [];
		$error  = null;
		$data   = $this->particleModuleData($params, $error);

		if ($data === null) {
			throw new \RuntimeException('Module ' . $id . ' has no usable particle (' . ($error ?? 'none chosen yet') . '). Pick the particle in the module editor first. Nothing was written.');
		}

		$type      = (string) ($data['particle'] ?? '');
		$theme     = $this->particleModuleTheme($arguments);
		$blueprint = $this->g5ParticleBlueprint($theme, $type);
		$fields    = $blueprint['fields'];
		$values    = $this->g5ValidateSet($fields, $set, 'particle type "' . $type . '"');

		$settings = \is_array($data['options']['particle'] ?? null) ? $data['options']['particle'] : [];
		$changes  = [];

		foreach ($unset as $key) {
			$path   = explode('.', $key);
			$found  = false;
			$before = $this->g5GetPath($settings, $path, $found);

			if (!isset($fields[$key]) && !$found) {
				throw new \InvalidArgumentException('Cannot unset "' . $key . '": it is neither a ' . $type . ' setting nor stored on this module. Nothing was written.');
			}

			if ($this->g5UnsetPath($settings, $path)) {
				$changes[$key] = ['before' => $before, 'after' => null];
			}
		}

		foreach ($values as $key => $value) {
			$path          = explode('.', $key);
			$changes[$key] = ['before' => $this->g5GetPath($settings, $path), 'after' => $value];
			$this->g5SetPath($settings, $path, $value);
		}

		$this->g5GuardTwig($actor, $fields, array_keys($changes), $settings['twig'] ?? null, 'This ' . $type . ' particle module');

		// Gantry's own save always stores enabled (Particle::validate(): def('options.particle.enabled', 1)).
		$settings += ['enabled' => '1'];

		$data['options']             = \is_array($data['options'] ?? null) ? $data['options'] : [];
		$data['options']['particle'] = $settings;

		// Decoding to arrays turns an empty {} into []; keep the objects Gantry wrote.
		foreach (['block', 'particle'] as $k) {
			if (isset($data['options'][$k]) && $data['options'][$k] === []) {
				$data['options'][$k] = new \stdClass();
			}
		}

		// Gantry's particle field stores json_encode(value, JSON_UNESCAPED_UNICODE) (forms/fields/gantry/particle.html.twig).
		$params['particle'] = json_encode($data, JSON_UNESCAPED_UNICODE);
		$newParams                   = (string) json_encode($params);

		if (\strlen($newParams) > 65535) {
			throw new \RuntimeException('The module params would be ' . \strlen($newParams) . ' bytes; #__modules.params (TEXT) holds 65535. Refusing rather than truncating.');
		}

		$result = [
			'ok'            => true,
			'dry_run'       => $dryRun,
			'module_id'     => $id,
			'module_title'  => (string) $row['title'],
			'particle_type' => $type,
			'blueprint'     => $blueprint['file'],
			'changes'       => $changes,
		];

		if ($dryRun) {
			return ToolResult::json($result);
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName('#__modules'))
				->set($this->db->quoteName('params') . ' = :params')
				->where($this->db->quoteName('id') . ' = :id')
				->bind(':params', $newParams)
				->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER)
		)->execute();

		$cleaned = [];

		foreach (['com_modules', 'mod_gantry5_particle'] as $group) {
			try {
				Factory::getContainer()->get(CacheControllerFactoryInterface::class)
					->createCacheController('callback', ['defaultgroup' => $group, 'cachebase' => (string) (Factory::getApplication()->get('cache_path') ?: JPATH_SITE . '/cache')])
					->clean();
				$cleaned[] = $group;
			} catch (\Throwable $e) {
				$cleaned[] = $group . ': ' . $e->getMessage();
			}
		}

		$after = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'module', 'position', 'published', 'ordering', 'access', 'language', 'showtitle', 'publish_up', 'publish_down', 'params'])
				->from($this->db->quoteName('#__modules'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		return ToolResult::json($result + [
			'settings_now'    => $this->particleModuleShape($after, $this->g5ModuleMenus([$id])[$id] ?? [])['settings'],
			'previous_params' => (string) $row['params'],
			'undo'            => 'Call this tool again with the "before" values in set (and the keys whose "before" was null in unset). previous_params is the full params column as it was.',
			'cache_cleaned'   => $cleaned,
		]);
	}
}
