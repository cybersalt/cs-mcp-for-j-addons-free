<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Gantry 5 MCP add-on. Built against Gantry 5.6.4 (Tiger12, 2026-09-03) and the
 * Helium 5.6.4 theme on Joomla 6.2.0.
 *
 * WHY THIS ADD-ON IS FREE. Gantry 5 is free (GPL, github.com/gantry/gantry5) with
 * no paid edition, so MCP access to it is free too.
 *
 * ---------------------------------------------------------------------------
 * FOUR FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. AN OUTLINE IS A TEMPLATE STYLE, AND ITS SETTINGS ARE YAML FILES. Under
 *      templates/<theme>/custom/config/<outline>/: layout.yaml (the layout and
 *      every particle instance's settings), styles.yaml, particles/<type>.yaml
 *      (particle defaults), page/*.yaml. The Base Outline ("default") is what
 *      every other outline falls back to, key by key. These tools read those
 *      files with symfony/yaml and re-implement Gantry's lookup order rather
 *      than booting Gantry (Gantry5BootTrait says why), and report where every
 *      value comes from.
 *
 *   2. INHERITANCE IS EVERYWHERE AND SILENT. Settings fall back from outline to
 *      Base Outline to theme to blueprint; layout sections and particles can
 *      inherit from any other outline. A change to the wrong outline either
 *      does nothing (the value is overridden lower down) or changes many pages
 *      (other outlines inherit it). Every read says where a value lives; every
 *      write refuses inherited items and lists the outlines it also reaches.
 *
 *   3. WRITES TOUCH ONE FILE, ONE KEY AT A TIME. Gantry's admin saves whole
 *      posted forms (and its Styles save even writes inherited values into the
 *      outline). These tools change only the keys passed, validated against the
 *      blueprint, in custom/config/ only, after a backup, then clear Gantry's
 *      cache (and the compiled CSS for styles) the way Gantry's admin does.
 *
 *   4. "MODULE SETTINGS PER OUTLINE" are layout items. A Module Position item
 *      renders a Joomla position (key, chrome), a Module Instance item renders
 *      one module (module_id). Both are edited like any particle; a Gantry 5
 *      Particle module (mod_gantry5_particle) keeps its particle in its own
 *      params and has its own pair of tools.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No layout restructuring (adding, moving or deleting sections and
 *     particles): Gantry's drag-and-drop Layout editor rebuilds the whole tree
 *     and index, and getting that wrong breaks every page of the outline.
 *   - No atom or page-settings writes (page/*.yaml): read-only for now.
 *   - No outline deletion: Joomla's Template Styles does it (the leftover
 *     folder is reported by check_gantry5_health).
 *   - No Gantry menu-editor writes: core MCP for J's menu tools own menus.
 */
final class Csmcpforjgantry5 extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Diagnostics\ClearCacheTool::class,

		// Themes and outlines.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Themes\ListThemesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines\ListOutlinesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines\GetOutlineTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines\ListAssignmentsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines\SetOutlineAssignmentsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines\CreateOutlineTool::class,

		// Layouts and the modules they render.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Layouts\GetLayoutTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Layouts\ListOutlineModulesTool::class,

		// Particles.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles\ListParticleTypesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles\GetParticleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles\UpdateParticleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles\GetParticleDefaultsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles\UpdateParticleDefaultsTool::class,

		// Styles.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Styles\GetStylesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Styles\UpdateStylesTool::class,

		// Gantry 5 Particle modules.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Modules\ListParticleModulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Modules\UpdateParticleModuleTool::class,

		// Backups.
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Backups\ListBackupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Backups\RestoreBackupTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * Runs on EVERY request. RegisterToolsEvent lives in com_csmcpforj; a bare
		 * ::EVENT_NAME access would autoload it and fatal the whole site (front end
		 * and administrator) when MCP for Joomla is not installed. class_exists()
		 * returns false instead. Do not "simplify" this.
		 */
		if (!class_exists(RegisterToolsEvent::class)) {
			return [];
		}

		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
