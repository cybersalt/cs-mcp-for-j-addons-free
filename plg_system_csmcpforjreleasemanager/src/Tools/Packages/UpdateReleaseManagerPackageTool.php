<?php
/**
 * @package     MCP for Cybersalt Release Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class UpdateReleaseManagerPackageTool extends AbstractTool
{
	use ReleaseManagerTrait;

	private const UPDATABLE = [
		'title', 'alias', 'description',
		'extension_type', 'extension_element', 'extension_folder', 'extension_client',
		'is_public', 'install_limit',
		'denial_message', 'renewal_url',
		'state',
	];

	public function getName(): string { return 'update_release_manager_package'; }

	public function getDescription(): string
	{
		return 'Update an existing cs-release-manager Package. Required: id. Only fields you '
			. 'supply are changed, including the Release Manager 1.9-1.13 fields (relink_hint, feed_key, '
			. 'successor_package_id, renewable groups and message, group_install_limits, license_family, '
			. 'install_limit_message, update/download/sign-up URLs, catalog_slug, catalog_metadata); a field '
			. 'the site\'s Release Manager does not have yet is skipped and listed. Every changed field is '
			. 'read back and reported. Pass user_groups[] to replace the membership list. NOTE: renaming '
			. 'extension_element moves /media/com_csreleasemanager/downloads/{old}/ to /{new}/.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'                => ['type' => 'integer'],
				'title'             => ['type' => 'string'],
				'alias'             => ['type' => 'string'],
				'description'       => ['type' => 'string'],
				'extension_type'    => ['type' => 'string', 'enum' => ['package', 'component', 'plugin', 'module', 'library', 'template', 'file', 'language']],
				'extension_element' => ['type' => 'string'],
				'extension_folder'  => ['type' => 'string'],
				'extension_client'  => ['type' => 'integer', 'enum' => [0, 1]],
				'is_public'         => ['type' => 'integer', 'enum' => [0, 1]],
				'user_groups'       => ['type' => 'array', 'items' => ['type' => 'integer']],
				'install_limit'     => ['type' => 'integer'],
				'denial_message'    => ['type' => 'string'],
				'renewal_url'       => ['type' => 'string'],
				'state'             => ['type' => 'integer', 'enum' => [0, 1, -2]],
			] + $this->csrmPackageFieldsSchema(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->csrmModel('Package');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Package ' . $id . ' not found.');
		}

		$extra = $this->csrmPackageFields($arguments, $id);

		if ($extra['errors'] !== []) {
			return ToolResult::json(['ok' => false, 'id' => $id, 'errors' => $extra['errors'], 'note' => 'Nothing was saved.'], true);
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		if (isset($arguments['user_groups']) && is_array($arguments['user_groups'])) {
			$data['user_groups'] = json_encode(array_map('intval', $arguments['user_groups']));
		}
		$data += $extra['data'];

		// PackageModel::save() reads "install_limit posted without group_install_limits" as
		// the admin form's repeatable subform with every row removed, and empties the
		// per-group limits. An MCP call that only changes install_limit must not do that,
		// so the stored limits are sent back unchanged (1.11.3 and earlier wiped them).
		$preserved = false;
		if (array_key_exists('install_limit', $data) && !array_key_exists('group_install_limits', $data)
			&& $this->csrmHasColumn('group_install_limits')) {
			$data['group_install_limits'] = $this->csrmStoredColumn($id, 'group_install_limits');
			$preserved = true;
		}

		$result = $this->saveAdminModel($model, $data);
		if (!$result['ok'] && $result['error'] !== '') {
			$check = $model->getItem($id);
			if (!$check || empty($check->id)) {
				return ToolResult::error('cs-release-manager rejected the update: ' . $result['error']);
			}
		}

		$changed = array_values(array_diff(array_keys($data), ['id']));
		$verify  = $this->csrmVerifyPackage($id, array_intersect_key($data, array_flip(array_merge(self::CSRM_PACKAGE_FIELDS, ['user_groups']))));

		$response = [
			'ok'             => $verify['mismatched'] === [],
			'id'             => $id,
			'fields_changed' => $preserved ? array_values(array_diff($changed, ['group_install_limits'])) : $changed,
		];
		if ($extra['skipped'] !== []) {
			$response['fields_skipped'] = $extra['skipped'];
			$response['skipped_note']   = 'This site\'s Release Manager has no column for these fields yet (they arrived in 1.9-1.13). Update Release Manager, then set them again.';
		}
		if ($preserved) {
			$response['group_install_limits_kept'] = 'install_limit was changed on its own, so the stored per-group limits were sent back unchanged.';
		}
		if ($verify['cleared_directly'] !== []) {
			$response['cleared_directly'] = $verify['cleared_directly'];
		}
		if ($verify['mismatched'] !== []) {
			$response['not_stored_as_sent'] = $verify['mismatched'];
		}
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response, $verify['mismatched'] !== []);
	}
}
