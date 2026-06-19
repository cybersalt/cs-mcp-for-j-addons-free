<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;

/**
 * Shared helpers for cs-release-manager MCP tools.
 *
 * Every release_manager_* tool starts by confirming com_csreleasemanager is installed
 * AND enabled before doing anything else. Without that check, tools throw
 * unhelpful class-not-found errors when the add-on is installed on a site
 * that doesn't run cs-release-manager.
 */
trait ReleaseManagerTrait
{
	/**
	 * Returns null if cs-release-manager is available, or a ToolResult::error
	 * the calling tool can return verbatim if it's missing.
	 */
	private function requireReleaseManager(): ?ToolResult
	{
		if (!ComponentHelper::isEnabled('com_csreleasemanager')) {
			return ToolResult::error(
				'cs-release-manager (com_csreleasemanager) is not installed or not enabled on '
				. 'this site. The csmcpforjreleasemanager add-on tools only work on sites running '
				. 'cs-release-manager.'
			);
		}
		return null;
	}

	/**
	 * Boots cs-release-manager and returns an Administrator model for one of
	 * its data types (Package, Packageversion, etc.) with request coupling
	 * disabled.
	 */
	private function csrmModel(string $name): object
	{
		$component = \Joomla\CMS\Factory::getApplication()->bootComponent('com_csreleasemanager');
		return $component->getMVCFactory()->createModel($name, 'Administrator', ['ignore_request' => true]);
	}
}
