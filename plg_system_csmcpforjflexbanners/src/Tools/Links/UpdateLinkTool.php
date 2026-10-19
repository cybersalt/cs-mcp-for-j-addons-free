<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Links;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class UpdateLinkTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'update_flexbanners_link'; }

	public function getDescription(): string
	{
		return 'Change a FlexBanners link\'s URL or name. This changes the destination of EVERY banner using the '
			. 'link — the response lists them. To change one banner only, use update_flexbanner with link_url.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => ['type' => 'integer'],
				'url'  => ['type' => 'string'],
				'name' => ['type' => 'string'],
			],
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
		$before = $this->fbRow('link', 'linkid', $id);

		if ($before === null) {
			throw new \InvalidArgumentException('No link with id ' . $id . '.');
		}

		$set = [];

		if (\array_key_exists('url', $arguments)) {
			$set[] = 'linkurl = ' . $this->db->quote($this->fbValidateLinkUrl((string) $arguments['url']));
		}

		if (\array_key_exists('name', $arguments)) {
			$name = trim((string) $arguments['name']);

			if ($name === '') {
				throw new \InvalidArgumentException('name cannot be empty.');
			}

			$this->fbRequireFits('name', $name);
			$set[] = 'name = ' . $this->db->quote($name);
		}

		if ($set === []) {
			throw new \InvalidArgumentException('Nothing to change: pass url and/or name.');
		}

		$this->db->setQuery('UPDATE ' . $this->db->quoteName($this->fbTable('link')) . ' SET ' . implode(', ', $set) . ' WHERE linkid = ' . $id)->execute();

		$after   = $this->fbRow('link', 'linkid', $id);
		$banners = array_map('intval', $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName($this->fbTable('')) . ' WHERE linkid = ' . $id)->loadColumn() ?: []);

		return ToolResult::json([
			'ok'               => true,
			'id'               => $id,
			'before'           => ['name' => $before['name'], 'url' => $before['linkurl']],
			'after'            => ['name' => $after['name'], 'url' => $after['linkurl']],
			'banners_affected' => $banners,
		]);
	}
}
