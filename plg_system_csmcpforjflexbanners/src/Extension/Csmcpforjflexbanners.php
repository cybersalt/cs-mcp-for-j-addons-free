<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * FlexBanners MCP add-on. Built against FlexBanners 5.1.10 (Inch Hosting,
 * 2025-12-02) on Joomla 6.2.0, and checked against 6.0.05 (same schema,
 * different module behaviour).
 *
 * WHY THIS ADD-ON IS FREE. FlexBanners is free on the JED with no paid
 * edition, so MCP access to it is free too.
 *
 * ---------------------------------------------------------------------------
 * FOUR FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. ONE MODULE SHOWS ONE LOCATION. mod_flexbanners has a single `locationid`
 *      param, so "several rows of ads" means several locations and several
 *      modules in one position. `create_flexbanners_location_module` does that
 *      job in one call — it was done by hand-written SQL on a client site.
 *
 *   2. THE MODULE'S QUERY IS STRICT AND SILENT. INNER JOINs on the size and
 *      category tables, a site-default-language test, `state = 1`,
 *      `finished = 0`. A banner that fails any of them simply is not there.
 *      Every banner tool runs the same test and says why.
 *
 *   3. THE VENDOR'S BANNER MODEL CANNOT BE DRIVEN FROM OUTSIDE ITS FORM. Its
 *      save() creates stray categories, mis-keys restriction rows on the request
 *      id and always clears `finished`. Writes are targeted SQL (see
 *      FlexbannersBootTrait); module rows go through com_modules' own model.
 *
 *   4. TWO MODULE VERSIONS, TWO BEHAVIOURS. 5.1.x never enforces end dates or
 *      limits. 6.x enforces them on every render — and re-publishes any
 *      unfinished banner whose start date has passed. Detected from the module
 *      code, reported by every tool it affects.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No date-range statistics: FlexBanners keeps lifetime counters only.
 *   - No image upload: use Joomla's media manager, then point image_url at it.
 *   - No module position or menu-assignment editing: core MCP for Joomla's
 *     module tools own those.
 */
final class Csmcpforjflexbanners extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Diagnostics\QueryTableTool::class,

		// Locations — the groups a module shows.
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations\ListLocationsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations\CreateLocationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations\UpdateLocationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations\DeleteLocationTool::class,

		// Modules — including the one-call "add another row of ads".
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules\CreateLocationModuleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules\ListModulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules\UpdateModuleTool::class,

		// Banners.
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\ListBannersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\GetBannerTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\CreateBannerTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\UpdateBannerTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\SetBannerStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\ResetBannerCountersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners\DeleteBannerTool::class,

		// Click-through links.
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Links\ListLinksTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Links\CreateLinkTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Links\UpdateLinkTool::class,

		// Clients (advertisers).
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Clients\ListClientsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Clients\CreateClientTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Clients\UpdateClientTool::class,

		// Size presets.
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes\ListSizesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes\CreateSizeTool::class,
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes\UpdateSizeTool::class,

		// Reports.
		\Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Reports\GetStatsTool::class,
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

		// Some hosts run serialize_precision=17, which prints CTR floats with 17 digits.
		if ((string) ini_get('serialize_precision') !== '-1') {
			@ini_set('serialize_precision', '-1');
		}

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
