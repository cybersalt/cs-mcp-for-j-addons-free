<?php
/**
 * @package     MCP for Cybersalt Release Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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

	// -----------------------------------------------------------------------
	// Package fields added in Release Manager 1.9-1.13 (addons-free#9)
	// -----------------------------------------------------------------------

	/**
	 * The package columns the create/update tools could not set before 1.12.0.
	 * Each was added by a later Release Manager (signup_url 1.9, catalog_* 1.10,
	 * the rest 1.13), so a site may lack some; csrmPackageFields() checks.
	 */
	private const CSRM_PACKAGE_FIELDS = [
		'relink_hint', 'feed_key', 'successor_package_id',
		'renewable_user_groups', 'renewable_denial_message',
		'group_install_limits', 'license_family', 'install_limit_message',
		'update_xml_url', 'download_url', 'signup_url',
		'catalog_slug', 'catalog_metadata',
	];

	/** The input-schema entries for CSRM_PACKAGE_FIELDS, shared by create and update. */
	private function csrmPackageFieldsSchema(): array
	{
		$url = static fn (string $what): array => ['type' => 'string', 'description' => $what . ' Empty string clears it. Must be an http(s) URL.'];

		return [
			'relink_hint'              => ['type' => 'string', 'description' => 'Account Link Location: where in the extension a customer enters their email, used in Release Manager\'s messages (e.g. "in Components > StageIt > Automatic Updates"). Plain text.'],
			'feed_key'                 => ['type' => 'string', 'description' => 'Feed key, the public &package= value that picks this package when several share an element (e.g. "stageit6"). Lower-case letters, digits, dot, dash, underscore; must be unique. Empty string clears it.'],
			'successor_package_id'     => ['type' => 'integer', 'description' => 'Successor package id: the next major line, whose latest version is offered in this feed to sites on the Joomla it targets. 0 = none. Must be another existing package.'],
			'renewable_user_groups'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Renewable groups: Joomla user-group ids in the renewal window (denied downloads, offered the renewal). Replaces the list; [] clears it.'],
			'renewable_denial_message' => ['type' => 'string', 'description' => 'Message for members in a renewable group; falls back to denial_message when empty. Safe HTML.'],
			'group_install_limits'     => [
				'type'        => 'array',
				'description' => 'Site limits by group: one row per Joomla user group, the most generous match wins, limit 0 = unlimited. Replaces the list; [] clears it.',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'group' => ['type' => 'integer', 'description' => 'Joomla user-group id.'],
						'limit' => ['type' => 'integer', 'description' => 'Sites allowed, 0 = unlimited.'],
					],
					'required'             => ['group', 'limit'],
					'additionalProperties' => false,
				],
			],
			'license_family'           => ['type' => 'string', 'description' => 'Licence family: packages with the same family share one pool of install slots (e.g. "stageit"). Lower-case letters, digits, dot, dash, underscore. Empty string clears it.'],
			'install_limit_message'    => ['type' => 'string', 'description' => 'Shown to a site over its install limit; {limit} and {package} are replaced. Safe HTML. Empty string = Release Manager\'s default.'],
			'update_xml_url'           => $url('Update XML URL.'),
			'download_url'             => $url('Direct URL to the package ZIP.'),
			'signup_url'               => $url('Sign-up URL: where non-members are sent to buy (not on the admin edit screen; blank falls back to the component default).'),
			'catalog_slug'             => ['type' => 'string', 'description' => 'Catalog slug: the catalog this package is listed in (e.g. "cs-mcp-for-j"). Empty string takes it out of every catalog.'],
			// A JSON string, not a free-form object: strict clients reject an object schema with no
			// properties, and the admin form edits this as raw JSON text too. An object is accepted as well.
			'catalog_metadata'         => ['type' => 'string', 'description' => 'Catalog metadata as JSON object text, e.g. {"key":"…","short_description":"…","tier":"free","target_extension":"…"}. Replaces the whole object; "" clears it.'],
		];
	}

	/**
	 * Validate and convert the 1.9-1.13 package fields the caller passed, the
	 * way Release Manager's admin form would (its filters only run in the
	 * controller, not in PackageModel::save(), so they are applied here).
	 *
	 * @return array{data: array<string,mixed>, skipped: string[], errors: string[]}
	 */
	private function csrmPackageFields(array $arguments, int $selfId): array
	{
		$db      = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$columns = array_change_key_case($db->getTableColumns('#__csrm_packages'), CASE_LOWER);
		$filter  = \Joomla\CMS\Filter\InputFilter::getInstance();
		$data    = [];
		$skipped = [];
		$errors  = [];

		foreach (self::CSRM_PACKAGE_FIELDS as $field) {
			if (!array_key_exists($field, $arguments)) {
				continue;
			}

			if (!isset($columns[$field])) {
				$skipped[] = $field;
				continue;
			}

			$value = $arguments[$field];

			switch ($field) {
				case 'relink_hint':
					$data[$field] = mb_substr(trim((string) $filter->clean((string) $value, 'string')), 0, 500);
					break;

				case 'feed_key':
					// PackageLookupHelper::normaliseKey(), which PackageModel::prepareTable() applies.
					$key = (string) preg_replace('/[^a-z0-9._-]/', '', strtolower(trim((string) $value)));

					if (mb_strlen($key) > 64) {
						$errors[] = 'feed_key is longer than 64 characters.';
						break;
					}

					if ($key !== '') {
						$taken = (int) $db->setQuery(
							$db->getQuery(true)
								->select($db->quoteName('id'))
								->from($db->quoteName('#__csrm_packages'))
								->where($db->quoteName('feed_key') . ' = ' . $db->quote($key))
								->where($db->quoteName('id') . ' <> ' . $selfId)
						)->loadResult();

						if ($taken) {
							$errors[] = 'feed_key "' . $key . '" is already used by package ' . $taken . '. Feed keys must be unique.';
							break;
						}
					}

					$data[$field] = $key;
					break;

				case 'license_family':
					$family = (string) preg_replace('/[^a-z0-9._-]/', '', strtolower(trim((string) $value)));

					if (mb_strlen($family) > 64) {
						$errors[] = 'license_family is longer than 64 characters.';
						break;
					}

					$data[$field] = $family;
					break;

				case 'catalog_slug':
					$slug = (string) preg_replace('/[^A-Za-z0-9._-]/', '', trim((string) $value));

					if (mb_strlen($slug) > 64) {
						$errors[] = 'catalog_slug is longer than 64 characters.';
						break;
					}

					$data[$field] = $slug;
					break;

				case 'successor_package_id':
					$successor = (int) $value;

					if ($successor < 0) {
						$errors[] = 'successor_package_id must be 0 (none) or a package id.';
					} elseif ($successor !== 0 && $successor === $selfId) {
						$errors[] = 'successor_package_id cannot be the package itself.';
					} elseif ($successor !== 0 && !$this->csrmPackageExists($successor)) {
						$errors[] = 'successor_package_id ' . $successor . ' is not an existing package.';
					} else {
						$data[$field] = $successor;
					}
					break;

				case 'renewable_user_groups':
					if (!\is_array($value)) {
						$errors[] = 'renewable_user_groups must be an array of user-group ids.';
						break;
					}

					$groups  = array_values(array_unique(array_map('intval', $value)));
					$missing = $this->csrmMissingGroups($groups);

					if ($missing !== []) {
						$errors[] = 'renewable_user_groups: no such user group ' . implode(', ', $missing) . '.';
						break;
					}

					$data[$field] = json_encode($groups);
					break;

				case 'group_install_limits':
					if (!\is_array($value)) {
						$errors[] = 'group_install_limits must be an array of {group, limit} rows.';
						break;
					}

					$rows = [];

					foreach (array_values($value) as $i => $row) {
						if (!\is_array($row) || !isset($row['group']) || !\array_key_exists('limit', $row)) {
							$errors[] = 'group_install_limits[' . $i . '] needs both group and limit.';
							continue;
						}

						if ((int) $row['limit'] < 0) {
							$errors[] = 'group_install_limits[' . $i . '].limit must be 0 (unlimited) or more.';
							continue;
						}

						// Keyed the way the admin form's repeatable subform stores it.
						$rows['group_install_limits' . $i] = ['group' => (int) $row['group'], 'limit' => (int) $row['limit']];
					}

					$missing = $this->csrmMissingGroups(array_column($rows, 'group'));

					if ($missing !== []) {
						$errors[] = 'group_install_limits: no such user group ' . implode(', ', $missing) . '.';
					}

					$data[$field] = json_encode($rows === [] ? [] : $rows);
					break;

				case 'renewable_denial_message':
				case 'install_limit_message':
					// The form's filter="safehtml": Joomla's block-list instance, which removes
					// dangerous tags and attributes but keeps links and formatting. (The default
					// InputFilter instance is an allow-list with nothing allowed: it strips every tag.)
					$data[$field] = (string) \Joomla\CMS\Filter\InputFilter::getInstance(
						[],
						[],
						\Joomla\CMS\Filter\InputFilter::ONLY_BLOCK_DEFINED_TAGS,
						\Joomla\CMS\Filter\InputFilter::ONLY_BLOCK_DEFINED_ATTRIBUTES
					)->clean((string) $value, 'html');
					break;

				case 'update_xml_url':
				case 'download_url':
				case 'signup_url':
					$url = trim((string) $value);

					if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url))) {
						$errors[] = $field . ' must be an http(s) URL or an empty string; got "' . $url . '".';
					} elseif (mb_strlen($url) > 500) {
						$errors[] = $field . ' is longer than 500 characters.';
					} else {
						$data[$field] = $url;
					}
					break;

				case 'catalog_metadata':
					if (\is_string($value)) {
						$decoded = trim($value) === '' ? [] : json_decode($value, true);

						if (!\is_array($decoded)) {
							$errors[] = 'catalog_metadata must be a JSON object.';
							break;
						}

						$value = $decoded;
					}

					if (!\is_array($value)) {
						$errors[] = 'catalog_metadata must be a JSON object.';
						break;
					}

					// The encoding PackageModel::prepareTable() uses for an array.
					$data[$field] = $value === []
						? ''
						: json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
					break;
			}
		}

		return ['data' => $data, 'skipped' => $skipped, 'errors' => $errors];
	}

	/**
	 * Compare what was stored with what was sent, for the fields a save set.
	 * PackageModel::prepareTable() turns an empty catalog_slug into NULL, which
	 * Table::store() skips, so "clear the slug" is applied directly here.
	 *
	 * @param  array<string,mixed> $sent
	 * @return array{mismatched: array<string,array{sent:mixed,stored:mixed}>, cleared_directly: string[]}
	 */
	private function csrmVerifyPackage(int $id, array $sent): array
	{
		$db  = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$row = (array) $db->setQuery(
			$db->getQuery(true)->select('*')->from($db->quoteName('#__csrm_packages'))->where($db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		$cleared = [];

		if (\array_key_exists('catalog_slug', $sent) && $sent['catalog_slug'] === '' && ($row['catalog_slug'] ?? null) !== null) {
			$db->setQuery(
				$db->getQuery(true)
					->update($db->quoteName('#__csrm_packages'))
					->set($db->quoteName('catalog_slug') . ' = NULL')
					->where($db->quoteName('id') . ' = ' . $id)
			)->execute();
			$row['catalog_slug'] = null;
			$cleared[]           = 'catalog_slug';
		}

		$mismatched = [];

		foreach ($sent as $field => $value) {
			if (!\array_key_exists($field, $row)) {
				continue;
			}

			$stored = $row[$field];

			if (\in_array($field, ['renewable_user_groups', 'group_install_limits', 'catalog_metadata', 'user_groups'], true)) {
				$same = json_decode((string) $stored, true) == json_decode((string) $value, true);
			} elseif ($field === 'catalog_slug' && $value === '') {
				$same = $stored === null;
			} else {
				$same = (string) $stored === (string) $value;
			}

			if (!$same) {
				$mismatched[$field] = ['sent' => $value, 'stored' => $stored];
			}
		}

		return ['mismatched' => $mismatched, 'cleared_directly' => $cleared];
	}

	private function csrmHasColumn(string $column): bool
	{
		$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

		return isset(array_change_key_case($db->getTableColumns('#__csrm_packages'), CASE_LOWER)[strtolower($column)]);
	}

	/** One column of a package row as stored (raw, so JSON stays JSON). */
	private function csrmStoredColumn(int $id, string $column): ?string
	{
		$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

		$value = $db->setQuery(
			$db->getQuery(true)->select($db->quoteName($column))->from($db->quoteName('#__csrm_packages'))->where($db->quoteName('id') . ' = ' . $id)
		)->loadResult();

		return $value === null ? null : (string) $value;
	}

	private function csrmPackageExists(int $id): bool
	{
		$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

		return (int) $db->setQuery(
			$db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__csrm_packages'))->where($db->quoteName('id') . ' = ' . $id)
		)->loadResult() > 0;
	}

	/**
	 * @param  int[] $groups
	 * @return int[] the ids with no #__usergroups row
	 */
	private function csrmMissingGroups(array $groups): array
	{
		$groups = array_values(array_unique(array_map('intval', $groups)));

		if ($groups === []) {
			return [];
		}

		$db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$found = array_map('intval', $db->setQuery(
			$db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'))->whereIn($db->quoteName('id'), $groups)
		)->loadColumn() ?: []);

		return array_values(array_diff($groups, $found));
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
