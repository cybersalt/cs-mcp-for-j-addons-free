<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Sizes;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ListSizesTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use SizeSupportTrait;

	public function getName(): string { return 'list_flexbanners_sizes'; }

	public function getDescription(): string
	{
		return 'List FlexBanners size presets (16 stock IAB sizes plus any added). Every banner must point at an '
			. 'existing size — the module INNER JOINs on it — and the size\'s width/height become the <img> '
			. 'attributes, so an image is stretched to its preset. Shows which banners use each size.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$rows  = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName($this->fbTable('size')) . ' ORDER BY sizeid')->loadAssocList() ?: [];
		$users = [];

		foreach ($this->db->setQuery('SELECT id, sizeid FROM ' . $this->db->quoteName($this->fbTable('')))->loadAssocList() ?: [] as $b) {
			$users[(int) $b['sizeid']][] = (int) $b['id'];
		}

		$out = [];

		foreach ($rows as $r) {
			$out[] = $this->sizeShape($r, $users[(int) $r['sizeid']] ?? []);
		}

		return ToolResult::json([
			'ok'    => true,
			'count' => \count($out),
			'sizes' => $out,
			'note'  => 'max_file_size is not enforced by any FlexBanners code (uploads go through Joomla\'s media manager, with its own limits).',
		]);
	}
}
