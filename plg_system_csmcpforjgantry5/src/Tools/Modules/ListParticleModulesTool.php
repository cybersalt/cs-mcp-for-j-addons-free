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
use Joomla\CMS\User\User;

final class ListParticleModulesTool extends AbstractTool
{
	use Gantry5BootTrait;
	use ParticleModuleSupportTrait;

	public function getName(): string { return 'list_gantry5_particle_modules'; }

	public function getDescription(): string
	{
		return 'Every "Gantry 5 Particle" module (mod_gantry5_particle): a Joomla module that renders one particle in an ordinary '
			. 'module position. Shows the particle type, its stored settings and block settings, position, published state and menu '
			. 'assignment, and flags modules whose particle JSON is broken or empty. On render the stored settings are joined over '
			. 'the page outline\'s defaults for that particle type. Change settings with update_gantry5_particle_module.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'published' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Optional filter: 1 published, 0 unpublished. Trashed modules are never listed.'],
				'particle'  => ['type' => 'string', 'description' => 'Optional filter by particle type, e.g. "custom".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor);

		if (!$actor->authorise('core.manage', 'com_modules')) {
			throw new \RuntimeException('Listing modules needs core.manage on com_modules.');
		}

		$query = $this->db->getQuery(true)
			->select(['id', 'title', 'module', 'position', 'published', 'ordering', 'access', 'language', 'showtitle', 'publish_up', 'publish_down', 'params'])
			->from($this->db->quoteName('#__modules'))
			->where($this->db->quoteName('module') . ' = ' . $this->db->quote('mod_gantry5_particle'))
			->where($this->db->quoteName('client_id') . ' = 0')
			->where($this->db->quoteName('published') . ' <> -2')
			->order($this->db->quoteName('position') . ', ' . $this->db->quoteName('ordering'));

		if (isset($arguments['published'])) {
			$query->where($this->db->quoteName('published') . ' = ' . ((int) $arguments['published'] ? 1 : 0));
		}

		$rows   = $this->db->setQuery($query)->loadAssocList() ?: [];
		$menus  = $this->g5ModuleMenus(array_column($rows, 'id'));
		$filter = trim((string) ($arguments['particle'] ?? ''));
		$out    = [];

		foreach ($rows as $row) {
			$shape = $this->particleModuleShape($row, $menus[(int) $row['id']] ?? []);

			if ($filter !== '' && $shape['particle_type'] !== $filter) {
				continue;
			}

			$out[] = $shape;
		}

		return ToolResult::json(['count' => \count($out), 'modules' => $out]);
	}
}
