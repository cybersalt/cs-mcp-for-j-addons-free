<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Links;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class CreateLinkTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'create_flexbanners_link'; }

	public function getDescription(): string
	{
		return 'Create a reusable FlexBanners click-through link. Usually unnecessary — create_flexbanner and '
			. 'update_flexbanner take link_url and manage the link row themselves — but useful when several banners '
			. 'should share one destination (pass the returned id as link_id).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'name'      => ['type' => 'string'],
				'url'       => ['type' => 'string', 'description' => 'http(s) URL, or a site-relative index.php?… link.'],
				'client_id' => ['type' => 'integer'],
			],
			'required'             => ['name', 'url'],
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

		$name = trim((string) ($arguments['name'] ?? ''));

		if ($name === '') {
			throw new \InvalidArgumentException('name is required.');
		}

		$this->fbRequireFits('name', $name);
		$url    = $this->fbValidateLinkUrl((string) ($arguments['url'] ?? ''));
		$client = (int) ($arguments['client_id'] ?? 0);

		if ($client !== 0 && $this->fbRow('client', 'clientid', $client) === null) {
			throw new \InvalidArgumentException('client_id ' . $client . ' does not exist.');
		}

		$row = (object) ['name' => $name, 'linkurl' => $url, 'clientid' => $client, 'state' => 1];
		$this->db->insertObject($this->fbTable('link'), $row, 'linkid');

		$saved = $this->fbRow('link', 'linkid', (int) $row->linkid);

		return ToolResult::json(['ok' => $saved !== null, 'created' => true, 'id' => (int) $row->linkid, 'name' => $saved['name'] ?? null, 'url' => $saved['linkurl'] ?? null]);
	}
}
