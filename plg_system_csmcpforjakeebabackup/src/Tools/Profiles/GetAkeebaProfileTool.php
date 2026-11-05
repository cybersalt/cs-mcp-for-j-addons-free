<?php
/**
 * @package     MCP for Akeeba Backup Core
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\AkeebaAccessTrait;
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
	use AkeebaAccessTrait;

	public function getName(): string { return 'get_akeeba_profile'; }

	public function getDescription(): string
	{
		return 'Get one Akeeba Backup profile by id. Returns description, quickicon, access, '
			. 'and the raw configuration + filters blobs. The blobs are Akeeba\'s serialized '
			. '(and possibly encrypted) per-profile engine settings — opaque from outside, '
			. 'kept on the response for completeness, and returned only to an account with '
			. 'Akeeba Backup\'s "Configure" permission.';
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

		if ($refusal = $this->akeebaRefusal($actor)) {
			return $refusal;
		}

		$q = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__akeebabackup_profiles'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($q)->loadAssoc();

		if (!$row) {
			return ToolResult::error('No Akeeba profile found with id ' . $id);
		}

		if (!$this->akeebaCanUseProfile($actor, (int) $row['access'])) {
			return ToolResult::error('Refused: profile ' . $id . ' is at an access level the Joomla account this AI app acts for does not have.');
		}

		$out = [
			'id'            => (int) $row['id'],
			'description'   => (string) $row['description'],
			'quickicon'     => (int) $row['quickicon'] === 1,
			'access'        => (int) $row['access'],
		];

		// The blobs can hold remote storage credentials and the archive password,
		// and Akeeba shows them only on its Configuration page.
		if ($actor->authorise('akeebabackup.configure', 'com_akeebabackup')) {
			$out['configuration'] = (string) ($row['configuration'] ?? '');
			$out['filters']       = (string) ($row['filters'] ?? '');
		} else {
			$out['settings_hidden'] = 'The configuration and filters need Akeeba Backup\'s "Configure" permission (akeebabackup.configure).';
		}

		return ToolResult::json($out);
	}
}
