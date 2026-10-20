<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class CreateSizeTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use SizeSupportTrait;

	public function getName(): string { return 'create_flexbanners_size'; }

	public function getDescription(): string
	{
		return 'Create a FlexBanners size preset, e.g. to match ads that are not a stock IAB size. Banners using it '
			. 'render with exactly this width and height.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => $this->sizeSchemaProps(),
			'required'             => ['name', 'width', 'height'],
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

		$cols = $this->sizeColumns($arguments);

		foreach (['name', 'width', 'height'] as $k) {
			if (!isset($cols[$k])) {
				throw new \InvalidArgumentException($k . ' is required.');
			}
		}

		$row = (object) array_merge(['maxfilesize' => 0, 'state' => 1], $cols);
		$this->db->insertObject($this->fbTable('size'), $row, 'sizeid');

		$saved = $this->fbRow('size', 'sizeid', (int) $row->sizeid);

		return ToolResult::json(['ok' => $saved !== null, 'created' => true] + $this->sizeShape($saved));
	}
}
