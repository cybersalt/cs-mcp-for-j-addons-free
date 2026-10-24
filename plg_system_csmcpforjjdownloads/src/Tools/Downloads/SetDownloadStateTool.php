<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * Publishes through jDownloads' DownloadModel::publish() (Joomla's
 * AdminModel::publish(): checked-out test, Table::publish(), the
 * onContentChangeState event), which reads no request state.
 */
final class SetDownloadStateTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	public function getName(): string { return 'set_jdownloads_download_state'; }

	public function getDescription(): string
	{
		return 'Publish or unpublish one or more jDownloads downloads, through jDownloads\' own publish routine. '
			. 'jDownloads has only these two states (no archive, no trash). Publishing warns about anything that will '
			. 'still stop visitors getting the file: an unpublished category, no file attached, or a file missing from '
			. 'disk. Refuses items checked out to another user.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Download ids (max 100).'],
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'required'             => ['ids', 'published'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor, 'core.edit.state');

		$value = $this->jdParsePublished($arguments['published'] ?? null);
		$ids   = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['ids'] ?? [])), static fn ($v) => $v > 0)));

		if ($ids === [] || \count($ids) > 100) {
			throw new \InvalidArgumentException('ids must list 1 to 100 download ids.');
		}

		$rows = [];

		foreach ($ids as $id) {
			$row = $this->jdDownloadRow($id);

			if ($row === null) {
				throw new \InvalidArgumentException('No jDownloads download with id ' . $id . '. Nothing was written.');
			}

			if (!$actor->authorise('core.edit.state', 'com_jdownloads.download.' . $id)) {
				throw new \RuntimeException('This user is not granted core.edit.state on com_jdownloads.download.' . $id . '. Nothing was written.');
			}

			$this->jdRequireNotCheckedOut($row, $actor, 'Download ' . $id);
			$rows[$id] = $row;
		}

		$model = $this->getModel('com_jdownloads', 'Download');
		$pks   = $ids;

		if (!$model->publish($pks, $value)) {
			$error = method_exists($model, 'getError') ? (string) $model->getError() : '';

			throw new \RuntimeException('jDownloads\' publish routine refused: ' . ($error !== '' ? $error : 'no reason given'));
		}

		$results  = [];
		$problems = [];

		foreach ($ids as $id) {
			$saved = $this->jdDownloadRow($id);
			$warn  = [];

			if ((int) $saved['published'] !== $value) {
				$problems[] = 'download ' . $id . ' is still published = ' . $saved['published'];
			}

			if ($value === 1) {
				$cat = $this->jdCategoryRow((int) $saved['catid']);

				if ($cat === null || (int) $cat['published'] !== 1) {
					$warn[] = 'its category is unpublished, so visitors still cannot see it';
				}

				$abs = $this->dlFileAbs($saved, $cat);

				if ((string) $saved['url_download'] === '' && (int) $saved['other_file_id'] === 0 && trim((string) $saved['extern_file']) === '') {
					$warn[] = 'it has no file attached (attach_jdownloads_file)';
				} elseif ($abs !== null && !is_file($abs)) {
					$warn[] = 'its file is missing from disk';
				}
			}

			$results[] = [
				'id'        => $id,
				'title'     => (string) $saved['title'],
				'was'       => (int) $rows[$id]['published'],
				'published' => (int) $saved['published'],
			] + ($warn !== [] ? ['warnings' => $warn] : []);
		}

		$response = ['ok' => $problems === [], 'results' => $results];

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		return ToolResult::json($response, $problems !== []);
	}
}
