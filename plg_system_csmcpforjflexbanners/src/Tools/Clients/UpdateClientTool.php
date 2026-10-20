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

final class UpdateClientTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use ClientSupportTrait;

	public function getName(): string { return 'update_flexbanners_client'; }

	public function getDescription(): string
	{
		return 'Change named fields of a FlexBanners client. Only the fields you pass are written.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer']] + $this->clientSchemaProps(),
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.edit');

		$id     = $this->requirePositiveInt($arguments, 'id');
		$before = $this->fbRow('client', 'clientid', $id);

		if ($before === null) {
			throw new \InvalidArgumentException('No client with id ' . $id . '.');
		}

		$cols = $this->clientColumns($arguments);

		if ($cols === []) {
			throw new \InvalidArgumentException('Nothing to change.');
		}

		$set = [];

		foreach ($cols as $c => $v) {
			$set[] = $this->db->quoteName($c) . ' = ' . $this->db->quote((string) $v);
		}

		$this->db->setQuery('UPDATE ' . $this->db->quoteName($this->fbTable('client')) . ' SET ' . implode(', ', $set) . ' WHERE clientid = ' . $id)->execute();

		return ToolResult::json([
			'ok'     => true,
			'before' => $this->clientShape($before),
			'after'  => $this->clientShape($this->fbRow('client', 'clientid', $id)),
		]);
	}
}
