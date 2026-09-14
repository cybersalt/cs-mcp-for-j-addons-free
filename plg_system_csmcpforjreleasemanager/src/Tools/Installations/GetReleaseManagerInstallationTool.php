<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Installations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class GetReleaseManagerInstallationTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'get_release_manager_installation'; }

	public function getDescription(): string
	{
		return 'Fetch a single cs-release-manager Installation record. Provide either '
			. 'installation_id (the public token issued to the client site) or id (the '
			. 'internal #__csrm_installations primary key). Returns every column '
			. 'including both email and email_hash — useful for confirming they are in '
			. 'sync before/after an update. Treat email as PII.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'installation_id' => ['type' => 'string'],
				'id'              => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		$q = $this->db->getQuery(true)
			->select('*')
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

		return ToolResult::json($row);
	}
}
