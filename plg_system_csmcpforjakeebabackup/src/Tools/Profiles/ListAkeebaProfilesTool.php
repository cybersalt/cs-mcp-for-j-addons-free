<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Lists Akeeba Backup profiles (#__akeebabackup_profiles).
 *
 * Profiles are Akeeba's "named bundle of backup settings" — destination folder,
 * archive format, included/excluded directories, post-processing engine, etc.
 * Every backup is taken against a profile. Site comes with profile id=1
 * ("Default Backup Profile") pre-installed; users add more via the admin UI's
 * Profiles screen.
 *
 * Read-only — no Akeeba Engine bootstrap needed, just SELECT from the table.
 */
final class ListAkeebaProfilesTool extends AbstractTool
{
	public function getName(): string { return 'list_akeeba_profiles'; }

	public function getDescription(): string
	{
		return 'List every Akeeba Backup profile on the site. Returns id, description, '
			. 'quickicon flag (1 = surfaced as a Quick Icon), and access level. The '
			. '`configuration` and `filters` columns are deliberately omitted from the '
			. 'list response (they are large serialized blobs); use get_akeeba_profile '
			. 'when you need the full profile detail.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$table = $this->db->getPrefix() . 'akeebabackup_profiles';

		// Surface a clear error if Akeeba Backup isn't installed, rather than
		// the generic "table doesn't exist" SQL throw. The MCP add-on is meant
		// to install cleanly on any Joomla site — if the user installed the
		// add-on without Akeeba Backup, they should see a friendly message.
		$tables = $this->db->getTableList();
		if (!in_array($table, $tables, true)) {
			return ToolResult::error(
				'Akeeba Backup is not installed on this site (table ' . $table . ' missing). '
				. 'Install com_akeebabackup from https://www.akeeba.com/download/akeeba-backup.html, '
				. 'then this tool will work.'
			);
		}

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('description'),
				$this->db->quoteName('quickicon'),
				$this->db->quoteName('access'),
			])
			->from($this->db->quoteName('#__akeebabackup_profiles'))
			->order($this->db->quoteName('id') . ' ASC');

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		// Cast for readability + downstream JSON shape stability.
		$out = array_map(static function (array $r): array {
			return [
				'id'          => (int) $r['id'],
				'description' => (string) $r['description'],
				'quickicon'   => (int) $r['quickicon'] === 1,
				'access'      => (int) $r['access'],
			];
		}, $rows);

		return ToolResult::json([
			'count'    => count($out),
			'profiles' => $out,
		]);
	}
}
