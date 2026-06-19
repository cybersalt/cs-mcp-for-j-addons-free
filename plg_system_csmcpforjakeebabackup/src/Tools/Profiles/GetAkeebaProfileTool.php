<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Fetches one Akeeba Backup profile by id, including the raw configuration +
 * filters blobs that list_akeeba_profiles intentionally omits (those columns
 * are large and not interesting for browsing the catalogue).
 *
 * The configuration blob is Akeeba's encrypted serialized engine config
 * (per-profile destination directory, archive format, included paths, etc.).
 * Returned verbatim; decoding is outside this tool's scope — Akeeba's own
 * Configuration view + Engine handle that.
 */
final class GetAkeebaProfileTool extends AbstractTool
{
	public function getName(): string { return 'get_akeeba_profile'; }

	public function getDescription(): string
	{
		return 'Get one Akeeba Backup profile by id. Returns description, quickicon, access, '
			. 'and the raw configuration + filters blobs. The blobs are Akeeba\'s serialized '
			. '(and possibly encrypted) per-profile engine settings — opaque from outside, '
			. 'kept on the response for completeness.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'profile_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The profile id.'],
			],
			'required'             => ['profile_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'profile_id');

		$table  = $this->db->getPrefix() . 'akeebabackup_profiles';
		$tables = $this->db->getTableList();
		if (!in_array($table, $tables, true)) {
			return ToolResult::error('Akeeba Backup is not installed on this site.');
		}

		$q = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__akeebabackup_profiles'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($q)->loadAssoc();

		if (!$row) {
			return ToolResult::error('No Akeeba profile found with id ' . $id);
		}

		return ToolResult::json([
			'id'            => (int) $row['id'],
			'description'   => (string) $row['description'],
			'quickicon'     => (int) $row['quickicon'] === 1,
			'access'        => (int) $row['access'],
			'configuration' => (string) ($row['configuration'] ?? ''),
			'filters'       => (string) ($row['filters'] ?? ''),
		]);
	}
}
