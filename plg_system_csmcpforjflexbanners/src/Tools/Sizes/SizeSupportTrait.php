<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes;

\defined('_JEXEC') or die;

/**
 * Size presets (#__flexbannerssize). Needs FlexbannersBootTrait.
 */
trait SizeSupportTrait
{
	protected function sizeSchemaProps(): array
	{
		return [
			'name'          => ['type' => 'string'],
			'width'         => ['type' => 'integer', 'description' => 'Pixels. Written into every <img width> of banners using this size.'],
			'height'        => ['type' => 'integer', 'description' => 'Pixels. Written into every <img height>.'],
			'max_file_size' => ['type' => 'integer', 'description' => 'Bytes. Stored and shown in the admin, but no FlexBanners 5.1.10/6.0.05 code enforces it.'],
			'published'     => ['type' => 'boolean', 'description' => 'Unpublished sizes are hidden from the admin pickers; the module ignores it.'],
		];
	}

	protected function sizeColumns(array $args): array
	{
		$cols = [];

		if (\array_key_exists('name', $args)) {
			$name = trim((string) $args['name']);

			if ($name === '') {
				throw new \InvalidArgumentException('name cannot be empty.');
			}

			$this->fbRequireFits('name', $name);
			$cols['name'] = $name;
		}

		foreach (['width' => 'width', 'height' => 'height', 'max_file_size' => 'maxfilesize'] as $arg => $col) {
			if (\array_key_exists($arg, $args)) {
				$v = (int) $args[$arg];

				if ($v < 0 || ($col !== 'maxfilesize' && $v === 0)) {
					throw new \InvalidArgumentException($arg . ' must be a positive integer.');
				}

				$cols[$col] = $v;
			}
		}

		if (\array_key_exists('published', $args)) {
			$cols['state'] = !empty($args['published']) ? 1 : 0;
		}

		return $cols;
	}

	protected function sizeShape(array $row, ?array $bannerIds = null): array
	{
		$out = [
			'id'            => (int) $row['sizeid'],
			'name'          => (string) $row['name'],
			'width'         => (int) $row['width'],
			'height'        => (int) $row['height'],
			'max_file_size' => (int) $row['maxfilesize'],
			'state'         => $this->fbStateLabel((int) $row['state']),
		];

		if ($bannerIds !== null) {
			$out['banner_ids'] = $bannerIds;
		}

		return $out;
	}
}
