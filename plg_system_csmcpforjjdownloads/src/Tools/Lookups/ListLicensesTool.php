<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

final class ListLicensesTool extends AbstractTool
{
	use JdownloadsBootTrait;

	public function getName(): string { return 'list_jdownloads_licenses'; }

	public function getDescription(): string
	{
		return 'Read-only list of jDownloads licences (id, title, URL, language, published, ordering) with how many '
			. 'downloads use each. The id is what create/update_jdownloads_download take as license_id. Pass '
			. 'with_text true for the full licence text.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
				'with_text' => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		if (!$this->jdTableExists('jdownloads_licenses')) {
			return ToolResult::json(['ok' => true, 'licenses' => [], 'note' => '#__jdownloads_licenses does not exist.']);
		}

		$db    = $this->db;
		$where = \array_key_exists('published', $arguments) ? ' WHERE l.published = ' . $this->jdParsePublished($arguments['published']) : '';

		$rows = $db->setQuery(
			// files.license is a varchar holding the id; compare numerically ('' = 0) to avoid a collation clash.
			'SELECT l.*, (SELECT COUNT(*) FROM #__jdownloads_files f WHERE f.license <> \'\' AND f.license = l.id) AS used_by'
			. ' FROM #__jdownloads_licenses l' . $where . ' ORDER BY l.ordering, l.title'
		)->loadAssocList() ?: [];

		$withText = !empty($arguments['with_text']);

		$out = array_map(function (array $r) use ($withText): array {
			$item = [
				'id'        => (int) $r['id'],
				'title'     => $this->jdText((string) $r['title']),
				'alias'     => (string) $r['alias'],
				'url'       => $this->jdText((string) $r['url']),
				'language'  => (string) $r['language'],
				'published' => (int) $r['published'],
				'ordering'  => (int) $r['ordering'],
				'used_by'   => (int) $r['used_by'],
			];

			if ($withText) {
				$item['description'] = $this->jdText((string) $r['description']);
			}

			return $item;
		}, $rows);

		return ToolResult::json(['ok' => true, 'total' => \count($out), 'licenses' => $out]);
	}
}
