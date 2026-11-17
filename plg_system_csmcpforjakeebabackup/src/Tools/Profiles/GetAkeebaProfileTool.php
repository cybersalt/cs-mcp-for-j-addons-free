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
			. 'Akeeba Backup\'s "Configure" permission. Passwords, keys and tokens in an unencrypted '
			. 'configuration and in the filters (Multiple Databases passwords) are masked unless reveal=true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'profile_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The profile id.'],
				'reveal'     => ['type' => 'boolean', 'description' => 'Show passwords, keys and tokens in an unencrypted configuration and in the filters in clear. Needs Akeeba Backup\'s "Configure ACL & Options" permission (a Super User has it). Default false: they are masked.'],
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

		$reveal = !empty($arguments['reveal']);

		if ($reveal && !$actor->authorise('core.admin', 'com_akeebabackup')) {
			return ToolResult::error('Refused: reveal needs Akeeba Backup\'s "Configure ACL & Options" permission (core.admin). '
				. 'Call again without reveal to get the profile with its passwords and keys masked.');
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

		// The blobs can hold remote storage credentials, the archive password and
		// (filters, Multiple Databases) database passwords, and Akeeba shows them
		// only on its own configuration screens. In a plain (unencrypted) blob
		// those are masked unless reveal is asked for by an account with Akeeba
		// Backup's "Configure ACL & Options" permission.
		if ($actor->authorise('akeebabackup.configure', 'com_akeebabackup')) {
			$configuration = (string) ($row['configuration'] ?? '');
			$encrypted     = \in_array(substr($configuration, 0, 12), ['###AES128###', '###CTR128###'], true);
			$filters       = (string) ($row['filters'] ?? '');

			$out['configuration_encrypted'] = $encrypted;
			$out['configuration']           = $reveal ? $configuration : $this->akeebaMaskConfiguration($configuration);
			$out['filters']                 = $reveal ? $filters : $this->akeebaMaskFilters($filters);

			if ($out['filters'] === null) {
				$out['filters_hidden'] = 'The filters are stored in an older format that can\'t be masked, so they are left out. '
					. 'Call again with reveal=true to get them (needs "Configure ACL & Options").';
			}
		} else {
			$out['settings_hidden'] = 'The configuration and filters need Akeeba Backup\'s "Configure" permission (akeebabackup.configure).';
		}

		return ToolResult::json($out);
	}
}
