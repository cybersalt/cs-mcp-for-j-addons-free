<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Cookies Policy Notification Bar (Web357) MCP add-on — the free tier.
 * Built against CPNB 5.1.2 on Joomla 6.2.0.
 *
 * WHY THERE ARE TWO PACKAGES. CPNB has a free and a Pro edition, and Cybersalt's
 * standing rule is that an add-on's tier mirrors the wrapped extension's: nobody
 * pays for MCP access to something they got for free. This package covers what
 * the free edition does — the banner (position, behaviour, texts in the site's
 * default language, colours, buttons), cookie categories, the cookie
 * declarations shown in the Cookies Manager, visibility rules, and diagnostics.
 * It works against BOTH editions. The capabilities Web357 documents as Pro-only
 * — cookie blocking, consent logs, GeoIP targeting, multilingual texts and
 * import/export — get dedicated tools in csmcpforjcpnbpro, which refuses unless
 * the vendor's own Pro build is installed.
 *
 * On a free build this package also refuses to WRITE the settings behind those
 * Pro capabilities (cpnbSave() checks), even though CPNB stores everything in
 * one JSON blob that would accept them.
 *
 * ---------------------------------------------------------------------------
 * THE FACTS THAT SHAPED THE TOOLS (all confirmed in 5.1.2 source)
 * ---------------------------------------------------------------------------
 *
 *   1. Settings are one JSON blob in the SYSTEM plugin's #__extensions.params,
 *      written only by plg_ajax's SettingsModel, which merges top-level keys.
 *      No truncation — but a subform (categories, declarations, blocking rules,
 *      button order) is replaced whole.
 *
 *   2. That model reads its starting params through PluginHelper::getPlugin(),
 *      which returns nothing for a DISABLED plugin, so a partial save with the
 *      bar switched off wipes every other setting. We inject params ourselves.
 *
 *   3. Deleting every cookie category does not remove them from the site: the
 *      system plugin falls back to four hard-coded sample categories when the
 *      stored list is empty (getCookieCategoriesGroup()). The delete tool
 *      refuses to remove the last one.
 *
 *   4. Uninstalling CPNB DROPS the consent-log table (script.install.php
 *      uninstall()). check_cpnb_health says so when logs exist.
 *
 *   5. Texts are per language: every text setting is `<name>_<xx_XX>`, and the
 *      front end reads the visitor's language tag. A text saved for en_GB is
 *      invisible on a site whose default language is en_US.
 */
final class Csmcpforjcpnbfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Diagnostics\CheckFrontendTool::class,

		// The bar's configuration (non-text, non-list settings).
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Config\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Config\SetConfigTool::class,

		// Cookie categories (the Cookies Manager's toggles).
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories\ListCategoriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories\CreateCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories\UpdateCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories\DeleteCategoryTool::class,

		// Cookie declarations (name, lifetime, description shown to visitors).
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Declarations\ListCookieDeclarationsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Declarations\SetCookieDeclarationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Declarations\DeleteCookieDeclarationTool::class,

		// Texts in the site's default language.
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Texts\GetTextsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Texts\SetTextsTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * This runs on EVERY request, for every enabled system plugin, before
		 * anything has a chance to check whether MCP for Joomla is present.
		 * RegisterToolsEvent lives in com_csmcpforj; reading ::EVENT_NAME off it
		 * without the component installed is a fatal on every page load, front
		 * end AND administrator. class_exists() returns false instead of
		 * throwing. Do not "simplify" this back to a bare constant access.
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
