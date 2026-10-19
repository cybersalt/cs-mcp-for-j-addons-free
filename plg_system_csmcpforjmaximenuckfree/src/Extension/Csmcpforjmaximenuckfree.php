<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Maximenu CK MCP add-on — the free half. Works against Light and Pro.
 *
 * Why it exists: a mega-menu change on a Maximenu site needs two things no core
 * MCP tool reaches. Per-item column settings live in `#__menu.params`, and the
 * custom CSS that actually renders lives on the STYLE row
 * (`#__maximenuck_styles.customcss`) — the module's own `customcss` param is a
 * hidden v8 leftover that v9+ never emits. On cybersalt.com (2026-10-16) both
 * were done by hand, the second as a direct SQL write.
 *
 * Tier split (see the vault note `csmcpforjmaximenuckfree.md`): everything that
 * works on Light ships here — module/style inspection, custom CSS, the compiled
 * CSS cache, the column layout and overflow analysis. WRITING per-item
 * mega-menu settings is gated behind `ckProOny()` in Light's own menu manager,
 * so those writes ship in `csmcpforjmaximenuckpro` and refuse on Light.
 *
 * v1.0.0 ships 11 tools across 4 categories.
 */
final class Csmcpforjmaximenuckfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Orientation and diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Diagnostics\CheckHealthTool::class,

		// Modules.
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Modules\ListModulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Modules\GetModuleTool::class,

		// Styles, custom CSS and the compiled-CSS cache.
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles\ListStylesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles\GetStyleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles\WriteStyleCustomCssTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles\ClearCompiledCssTool::class,

		// Menu items: the mega-menu layout, read-only.
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Items\GetMenuLayoutTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Items\GetItemSettingsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Items\CheckPanelOverflowTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * Runs on EVERY request. RegisterToolsEvent lives in com_csmcpforj, and
		 * reading ::EVENT_NAME off a missing class is a fatal on every page,
		 * admin included. class_exists() returns false instead, so without MCP
		 * for Joomla this plugin subscribes to nothing. Do not "simplify" this.
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
