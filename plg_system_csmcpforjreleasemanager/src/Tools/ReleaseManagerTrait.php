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

	/**
	 * SHA-256 of a version's zip as it sits in the package's downloads folder,
	 * /media/com_csreleasemanager/downloads/{extension_element}/{filename}.
	 *
	 * PackageversionModel::save() only hashes a file on its own upload,
	 * "existing file" and external-URL branches. A save that just names the
	 * filename (which is all an MCP call can do) stores an empty sha256, and the
	 * update feed then omits <sha256> for that version. So the tools hash the
	 * file themselves.
	 *
	 * @return array{hash: ?string, path: string, error: ?string}
	 */
	private function csrmHashVersionFile(int $packageId, string $filename): array
	{
		$filename = trim($filename);

		if ($filename === '' || $filename !== basename(str_replace('\\', '/', $filename)) || str_contains($filename, '..')) {
			return ['hash' => null, 'path' => '', 'error' => 'filename must be a bare file name (no folders), got "' . $filename . '".'];
		}

		$db      = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$element = (string) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('extension_element'))
				->from($db->quoteName('#__csrm_packages'))
				->where($db->quoteName('id') . ' = ' . $packageId)
		)->loadResult();

		if ($element === '') {
			return ['hash' => null, 'path' => '', 'error' => 'Package ' . $packageId . ' not found, or it has no extension_element, so its downloads folder is unknown.'];
		}

		$relative = 'media/com_csreleasemanager/downloads/' . $element . '/' . $filename;
		$path     = JPATH_ROOT . '/' . $relative;

		if (!is_file($path)) {
			return ['hash' => null, 'path' => $relative, 'error' => 'The file is not on the server at ' . $relative . '. Upload it there first (this tool does not upload files), or pass sha256 explicitly.'];
		}

		$hash = hash_file('sha256', $path);

		if ($hash === false) {
			return ['hash' => null, 'path' => $relative, 'error' => 'Could not read ' . $relative . ' to hash it.'];
		}

		return ['hash' => $hash, 'path' => $relative, 'error' => null];
	}

	/**
	 * The sha256 currently stored on a version row, read straight from the table.
	 */
	private function csrmStoredSha256(int $versionId): string
	{
		$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

		return (string) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('sha256'))
				->from($db->quoteName('#__csrm_package_versions'))
				->where($db->quoteName('id') . ' = ' . $versionId)
		)->loadResult();
	}
}
