<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * cs-release-manager MCP add-on plugin. Registers a tool set that lets Claude
 * manage Cybersalt Release Manager packages, releases, and audit data.
 *
 * Why this exists: cs-release-manager is Cybersalt's own distribution backend
 * for paid + free Joomla extensions. Until now every Package record and every
 * release upload had to be done through the admin GUI. With this add-on, the
 * MCP catalog inside cs-mcp-for-j can dogfood itself — the same MCP that
 * shows the user a list of available add-ons can also be used to publish new
 * ones on the cybersalt.com side.
 *
 * Tools go through cs-release-manager's own AdminModel classes (PackageModel,
 * PackageversionModel) so all side effects fire — activity log entries,
 * on-disk file move when extension_element is renamed, etc. — exactly as if
 * the operator clicked Save in the GUI.
 *
 * If com_csreleasemanager is not installed on the target site, the plugin
 * still loads cleanly. Individual tools detect the missing component at run
 * time and return a friendly error instead of throwing.
 */
final class Csmcpforjreleasemanager extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Packages (the distribution records — one per extension you sell or give away)
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages\ListReleaseManagerPackagesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages\GetReleaseManagerPackageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages\CreateReleaseManagerPackageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages\UpdateReleaseManagerPackageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages\DeleteReleaseManagerPackageTool::class,

		// Package versions (each release uploaded under a Package)
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions\ListReleaseManagerPackageVersionsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions\GetReleaseManagerPackageVersionTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions\CreateReleaseManagerPackageVersionTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions\UpdateReleaseManagerPackageVersionTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions\DeleteReleaseManagerPackageVersionTool::class,

		// Read-only operational data
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ListReleaseManagerInstallationsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ListReleaseManagerActivityLogTool::class,
	];

	public static function getSubscribedEvents(): array
	{
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

	/**
	 * Tool list for the dashboard's domain map.
	 */
	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
