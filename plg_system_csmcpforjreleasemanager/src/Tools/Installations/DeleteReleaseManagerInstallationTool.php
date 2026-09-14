<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Installations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

/**
 * Hard-delete an installation row. Intended for cleaning up orphan rows left
 * by retry loops (the paxstellar 2026-09-13 incident produced three from a
 * single customer's link attempts). Once the linkemail flow is entitlement-
 * aware (cs-release-manager#2) these orphans should stop being created, but
 * historical rows still need a way to be removed without SSH.
 */
final class DeleteReleaseManagerInstallationTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'delete_release_manager_installation'; }

	public function getDescription(): string
	{
		return 'Hard-delete an installation row from #__csrm_installations. Required: '
			. 'installation_id or id, plus confirm:true. Use this to clean up orphan '
			. 'rows from failed link attempts. The client site can re-link and produce '
			. 'a fresh row afterwards; there is no soft-delete or trash state on this '
			. 'table.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['confirm'],
			'properties' => [
				'installation_id' => ['type' => 'string'],
				'id'              => ['type' => 'integer'],
				'confirm'         => ['type' => 'boolean', 'enum' => [true]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		if (empty($arguments['confirm'])) {
			return ToolResult::error('confirm:true is required to delete an installation row.');
		}

		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'installation_id', 'domain', 'email']))
			->from($this->db->quoteName('#__csrm_installations'));

		if (!empty($arguments['installation_id'])) {
			$q->where($this->db->quoteName('installation_id') . ' = ' . $this->db->quote((string) $arguments['installation_id']));
		} elseif (!empty($arguments['id'])) {
			$q->where($this->db->quoteName('id') . ' = ' . (int) $arguments['id']);
		} else {
			return ToolResult::error('Provide installation_id or id.');
		}

		$row = $this->db->setQuery($q)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Installation not found.');
		}

		$del = $this->db->getQuery(true)
			->delete($this->db->quoteName('#__csrm_installations'))
			->where($this->db->quoteName('id') . ' = ' . (int) $row['id']);

		$this->db->setQuery($del)->execute();

		return ToolResult::json([
			'ok'              => true,
			'deleted' => [
				'id'              => (int) $row['id'],
				'installation_id' => (string) $row['installation_id'],
				'domain'          => (string) $row['domain'],
				'email'           => (string) $row['email'],
			],
		]);
	}
}
