<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class UpdateBannerTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use BannerSupportTrait;

	public function getName(): string { return 'update_flexbanner'; }

	public function getDescription(): string
	{
		return 'Change named fields of one FlexBanners banner. Only the fields you pass are written; everything '
			. 'else, including the impression and click counters, is left exactly as it is. Moving a banner to '
			. 'another group = pass location_id. link_url rewrites this banner\'s own link row, or splits off a new '
			. 'one if the link is shared with other banners. Restrictions (restrict_category_ids / '
			. 'restrict_article_ids) replace the current set; [] for both removes the restriction. Same validation '
			. 'as create_flexbanner, and the response re-runs the display audit so you can see whether the banner '
			. 'still shows. Refuses if the banner is checked out to another user in the admin.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer', 'description' => 'Banner id.']] + $this->bannerSchemaProps(),
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.edit');

		$id       = $this->requirePositiveInt($arguments, 'id');
		$existing = $this->fbBanner($id);

		if ($existing === null) {
			throw new \InvalidArgumentException('No banner with id ' . $id . '.');
		}

		$checkedOut = (int) ($existing['checked_out'] ?? 0);

		if ($checkedOut > 0 && $checkedOut !== (int) $actor->id) {
			throw new \RuntimeException('Banner ' . $id . ' is checked out to user ' . $checkedOut . ' in the FlexBanners admin. Their next Save would overwrite this change; ask them to close it (or check it in) first.');
		}

		if (\array_key_exists('state', $arguments) && !$actor->authorise('core.edit.state', 'com_flexbanners')) {
			throw new \RuntimeException('Changing state requires core.edit.state on com_flexbanners.');
		}

		$fields = array_diff(array_keys($arguments), ['id']);

		if ($fields === []) {
			throw new \InvalidArgumentException('Nothing to change: pass at least one field besides id.');
		}

		[$cols, $warnings] = $this->bannerColumns($arguments, $existing, $actor);

		$db    = $this->db;
		$notes = [];
		$restrictions = null;

		$countBefore = $this->fbCount('');

		$db->transactionStart();

		try {
			[$linkid, $linkNotes] = $this->bannerResolveLink(
				$arguments,
				$existing,
				(string) ($cols['name'] ?? $existing['name']),
				(int) ($cols['clientid'] ?? $existing['clientid'])
			);
			$notes = array_merge($notes, $linkNotes);

			if ($linkid !== null) {
				$cols['linkid'] = $linkid;
			}

			$catid    = (int) ($cols['catid'] ?? $existing['catid']);
			$language = (string) ($cols['language'] ?? $existing['language']);

			if (\array_key_exists('alias', $arguments) || \array_key_exists('catid', $cols)) {
				$base          = trim((string) ($arguments['alias'] ?? '')) !== '' ? (string) $arguments['alias'] : (string) $existing['alias'];
				$cols['alias'] = $this->fbUniqueAlias($base !== '' ? $base : (string) ($cols['name'] ?? $existing['name']), $catid, $language, $id);
			}

			$set = [];

			foreach ($cols as $col => $value) {
				$set[] = $db->quoteName($col) . ' = ' . ($value === null ? 'NULL' : $db->quote((string) $value));
			}

			$set[] = $db->quoteName('modified') . ' = ' . $db->quote($this->fbNow());
			$set[] = $db->quoteName('modified_by') . ' = ' . (int) $actor->id;
			$set[] = $db->quoteName('version') . ' = ' . $db->quoteName('version') . ' + 1';

			$db->setQuery('UPDATE ' . $db->quoteName($this->fbTable('')) . ' SET ' . implode(', ', $set) . ' WHERE id = ' . $id)->execute();

			[$cats, $arts] = $this->bannerRestrictionArgs($arguments);
			$restrictions  = $this->bannerWriteRestrictions($id, $cats, $arts);

			$db->transactionCommit();
		} catch (\Throwable $e) {
			$db->transactionRollback();

			throw $e;
		}

		if ($this->fbCount('') !== $countBefore) {
			throw new \RuntimeException('The banner count changed during an update (' . $countBefore . ' -> ' . $this->fbCount('') . '). Something inserted a row; inspect with list_flexbanners.');
		}

		$saved = $this->fbBanner($id);

		$changed = [];

		foreach ($cols as $col => $value) {
			if ((string) ($existing[$col] ?? '') !== (string) ($saved[$col] ?? '')) {
				$changed[] = $col;
			}
		}

		$response = ['ok' => true, 'updated' => true, 'changed_columns' => $changed] + $this->shapeBanner($saved);

		if ($restrictions !== null) {
			$response['restrictions_written'] = $restrictions;
		}

		if ($notes !== []) {
			$response['notes'] = $notes;
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}
}
