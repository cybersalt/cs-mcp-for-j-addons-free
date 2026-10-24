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
use Joomla\CMS\Access\Rules;
use Joomla\CMS\User\User;

/**
 * Inserts through jDownloads' DownloadTable (bind + store): that is where its
 * asset row (parented to the category's asset), created/modified stamps and
 * tag handling live. The defaults DownloadModel::prepareTable() and
 * DownloadTable::checkData() apply to a new admin download are reproduced
 * here, because those two read the posted form (see JdownloadsBootTrait).
 */
final class CreateDownloadTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	public function getName(): string { return 'create_jdownloads_download'; }

	public function getDescription(): string
	{
		return 'Create a jDownloads download (the item visitors download) in a category, with its metadata: version, '
			. 'file date, licence, author, descriptions and so on. It starts with no file: attach one with '
			. 'attach_jdownloads_file. Created UNPUBLISHED unless published 1 is passed. As in the jDownloads admin, '
			. 'it takes the category\'s access level unless you pass an access level other than Public (1), and its '
			. 'alias must be unique in the category (made from the title when omitted). Permissions are inherited from '
			. 'the category. Nothing is emailed: jDownloads\' new-upload mail is only sent for front-end uploads.';
	}

	public function getInputSchema(): array
	{
		$props = $this->dlSchemaProps();
		$props['published'] = ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default 0.'];

		return [
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => ['title', 'category_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		$title = $this->requireString($arguments, 'title');
		$cat   = $this->dlRequireCategory($this->requirePositiveInt($arguments, 'category_id'));
		$catid = (int) $cat['id'];
		$asset = 'com_jdownloads.category.' . $catid;

		$this->jdRequireAccess($actor, 'core.create', $asset);

		$published = \array_key_exists('published', $arguments) ? $this->jdParsePublished($arguments['published']) : 0;

		if ($published === 1 && !$actor->authorise('core.edit.state', $asset)) {
			throw new \RuntimeException('Creating a PUBLISHED download needs core.edit.state on ' . $asset . '. Pass published 0, or grant it.');
		}

		$cols  = $this->dlColumns($arguments);
		$notes = [];

		// DownloadTable::checkData() lines 258-266: a new download whose access is
		// Public (1) or unset takes its category's access level.
		$access = (int) ($cols['access'] ?? 0);

		if ($access <= 1) {
			$cols['access'] = (int) $cat['access'];

			if (\array_key_exists('access', $arguments) && (int) $cat['access'] !== $access) {
				$notes[] = 'access ' . $access . ' (Public) was replaced by the category\'s access level ' . $cat['access']
					. ', as the jDownloads admin does for a new download. Use update_jdownloads_download to set Public afterwards.';
			}
		}

		$now = $this->jdNow();

		// DownloadModel::prepareTable(): a published download gets publish_up = now.
		if ($published === 1 && empty($cols['publish_up'])) {
			$cols['publish_up'] = $now;
		}

		$ordering = (int) $this->db->setQuery('SELECT MAX(ordering) FROM #__jdownloads_files')->loadResult() + 1;

		$data = array_merge([
			// NOT NULL text columns with no default (install.mysql.utf8.sql:50-110).
			'description'      => '',
			'description_long' => '',
			'images'           => '',
			'notes'            => '',
			'changelog'        => '',
			'metakey'          => '',
			'metadesc'         => '',
			'language'         => '*',
		], $cols, [
			'id'         => 0,
			'title'      => $title,
			'alias'      => $this->dlAlias(isset($arguments['alias']) ? (string) $arguments['alias'] : null, $title, $catid, 0),
			'catid'      => $catid,
			'published'  => $published,
			'created'    => $now,
			'created_by' => (int) $actor->id,
			'ordering'   => $ordering,
		]);

		$countBefore = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_files')->loadResult();

		$table = $this->jdVendorTable('Download');

		if (!$table->bind($data)) {
			throw new \RuntimeException('DownloadTable::bind() failed. Nothing was written.');
		}

		// What DownloadModel::save() binds when the form sends no rules: nothing explicit, all inherited.
		$table->setRules(new Rules(['core.create' => [], 'core.delete' => [],'core.edit' => [], 'core.edit.state' => [], 'core.edit.own' => [], 'download' => []]));

		if (!$table->store()) {
			$error = method_exists($table, 'getError') ? $table->getError() : '';

			throw new \RuntimeException('DownloadTable::store() failed: ' . ($error instanceof \Throwable ? $error->getMessage() : (string) $error));
		}

		$id  = (int) $table->id;
		$row = $id > 0 ? $this->jdDownloadRow($id) : null;

		$problems = [];

		if ($row === null) {
			throw new \RuntimeException('DownloadTable reported success but no row was found.');
		}

		$countAfter = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_files')->loadResult();

		if ($countAfter !== $countBefore + 1) {
			$problems[] = 'the download count changed by ' . ($countAfter - $countBefore) . ', not 1';
		}

		$assetRow = $this->db->setQuery(
			'SELECT a.id, p.name AS parent FROM #__assets a LEFT JOIN #__assets p ON p.id = a.parent_id WHERE a.name = '
			. $this->db->quote('com_jdownloads.download.' . $id)
		)->loadAssoc();

		if (!$assetRow) {
			$problems[] = 'no permission asset row was created';
		} elseif ((string) $assetRow['parent'] !== $asset) {
			$problems[] = 'the asset is parented to ' . $assetRow['parent'] . ', not ' . $asset;
		}

		foreach ($cols as $col => $value) {
			if ((string) ($row[$col] ?? '') !== (string) ($value ?? '')) {
				$problems[] = $col . ' was stored as "' . $row[$col] . '", not "' . $value . '"';
			}
		}

		$response = ['ok' => $problems === [], 'created' => true, 'download' => $this->dlShape($row, true)];

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		if ($notes !== []) {
			$response['notes'] = $notes;
		}

		$response['next'] = 'Attach a file with attach_jdownloads_file (list_jdownloads_unassigned_files shows what is waiting in the upload folder).';

		return ToolResult::json($response, $problems !== []);
	}
}
