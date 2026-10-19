<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class UpdateSizeTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use SizeSupportTrait;

	public function getName(): string { return 'update_flexbanners_size'; }

	public function getDescription(): string
	{
		return 'Change a FlexBanners size preset. Changing width or height resizes EVERY banner using it on the '
			. 'next page view — the response lists them. max_file_size is stored but not enforced by FlexBanners.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer']] + $this->sizeSchemaProps(),
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
		$before = $this->fbRow('size', 'sizeid', $id);

		if ($before === null) {
			throw new \InvalidArgumentException('No size with id ' . $id . '.');
		}

		$cols = $this->sizeColumns($arguments);

		if ($cols === []) {
			throw new \InvalidArgumentException('Nothing to change.');
		}

		$set = [];

		foreach ($cols as $c => $v) {
			$set[] = $this->db->quoteName($c) . ' = ' . $this->db->quote((string) $v);
		}

		$this->db->setQuery('UPDATE ' . $this->db->quoteName($this->fbTable('size')) . ' SET ' . implode(', ', $set) . ' WHERE sizeid = ' . $id)->execute();

		$banners = array_map('intval', $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName($this->fbTable('')) . ' WHERE sizeid = ' . $id)->loadColumn() ?: []);

		return ToolResult::json([
			'ok'               => true,
			'before'           => $this->sizeShape($before),
			'after'            => $this->sizeShape($this->fbRow('size', 'sizeid', $id)),
			'banners_affected' => $banners,
		]);
	}
}
