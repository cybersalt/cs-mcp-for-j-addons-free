<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ResetBannerCountersTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'reset_flexbanner_counters'; }

	public function getDescription(): string
	{
		return 'Zero the impression and/or click counters of one or more FlexBanners banners. These counters are '
			. 'the ONLY statistics FlexBanners keeps — there is no per-day log — so the old values are returned '
			. 'in the response and are otherwise gone. Also clears "finished" so a banner that hit its limit can '
			. 'show again. dry_run reports what would change.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'         => ['type' => 'array', 'items' => ['type' => 'integer']],
				'impressions' => ['type' => 'boolean', 'description' => 'Reset impressions (default true).'],
				'clicks'      => ['type' => 'boolean', 'description' => 'Reset clicks (default true).'],
				'dry_run'     => ['type' => 'boolean'],
			],
			'required'             => ['ids'],
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

		$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['ids'] ?? [])))));

		if ($ids === []) {
			throw new \InvalidArgumentException('ids is required.');
		}

		$imp = !\array_key_exists('impressions', $arguments) || !empty($arguments['impressions']);
		$clk = !\array_key_exists('clicks', $arguments) || !empty($arguments['clicks']);

		if (!$imp && !$clk) {
			throw new \InvalidArgumentException('Nothing to reset: impressions and clicks are both false.');
		}

		$db  = $this->db;
		$out = [];

		foreach ($ids as $id) {
			$row = $this->fbBanner($id);

			if ($row === null) {
				$out[] = ['id' => $id, 'ok' => false, 'error' => 'no such banner'];
				continue;
			}

			$entry = [
				'id'                 => $id,
				'name'               => (string) $row['name'],
				'before_impressions' => (int) $row['impmade'],
				'before_clicks'      => (int) $row['clicks'],
			];

			if (empty($arguments['dry_run'])) {
				$set = ['finished = 0', 'modified = ' . $db->quote($this->fbNow()), 'modified_by = ' . (int) $actor->id];

				if ($imp) {
					$set[] = 'impmade = 0';
					$set[] = 'dailyimpressions = 0';
				}

				if ($clk) {
					$set[] = 'clicks = 0';
				}

				$db->setQuery('UPDATE ' . $db->quoteName($this->fbTable('')) . ' SET ' . implode(', ', $set) . ' WHERE id = ' . $id)->execute();
				$after = $this->fbBanner($id);
				$entry['after_impressions'] = (int) $after['impmade'];
				$entry['after_clicks']      = (int) $after['clicks'];
				$entry['ok']                = (!$imp || (int) $after['impmade'] === 0) && (!$clk || (int) $after['clicks'] === 0);
			} else {
				$entry['ok'] = true;
			}

			$out[] = $entry;
		}

		return ToolResult::json(['ok' => true, 'dry_run' => !empty($arguments['dry_run']), 'results' => $out]);
	}
}
