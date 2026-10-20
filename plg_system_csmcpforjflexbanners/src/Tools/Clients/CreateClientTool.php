<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Clients;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class CreateClientTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use ClientSupportTrait;

	public function getName(): string { return 'create_flexbanners_client'; }

	public function getDescription(): string
	{
		return 'Create a FlexBanners client (advertiser). The banner admin form requires every banner to have one, '
			. 'though the front end never reads it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => $this->clientSchemaProps(),
			'required'             => ['name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.create');

		if (trim((string) ($arguments['name'] ?? '')) === '') {
			throw new \InvalidArgumentException('name is required.');
		}

		$row = (object) array_merge(['contactname' => '', 'contactemail' => '', 'barred' => 0, 'juserid' => 0, 'state' => 1], $this->clientColumns($arguments));
		$this->db->insertObject($this->fbTable('client'), $row, 'clientid');

		$saved = $this->fbRow('client', 'clientid', (int) $row->clientid);

		return ToolResult::json(['ok' => $saved !== null, 'created' => true] + $this->clientShape($saved));
	}
}
