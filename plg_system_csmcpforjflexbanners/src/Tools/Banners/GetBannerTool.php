<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class GetBannerTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use BannerSupportTrait;

	public function getName(): string { return 'get_flexbanner'; }

	public function getDescription(): string
	{
		return 'One FlexBanners banner, fully assembled: location, size, category, client, click-through link '
			. 'URL (resolved from #__flexbannerslink), image file check (exists, bytes, pixel size), dates, '
			. 'impression and click counters and limits, restrictions, plus a display verdict computed the way '
			. 'mod_flexbanners decides it — which modules show it, and if none, exactly why not.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer', 'description' => 'Banner id.']],
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->fbBanner($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No banner with id ' . $id . '.');
		}

		$out = ['ok' => true] + $this->shapeBanner($row);

		$size = $out['size'];

		if (isset($out['image_file']['width'], $size['width']) && $out['image_file']['width'] !== null
			&& ($out['image_file']['width'] !== $size['width'] || $out['image_file']['height'] !== $size['height'])) {
			$out['display_warnings'][] = 'The image is ' . $out['image_file']['width'] . 'x' . $out['image_file']['height']
				. ' but size preset ' . $size['id'] . ' is ' . $size['width'] . 'x' . $size['height']
				. '; the module writes the preset into the <img> width/height, so the image is stretched to it.';
		}

		return ToolResult::json($out);
	}
}
