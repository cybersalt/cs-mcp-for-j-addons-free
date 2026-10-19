<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class SetBannerStateTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'set_flexbanner_state'; }

	public function getDescription(): string
	{
		return 'Publish, unpublish, archive or trash one or more FlexBanners banners. Publishing also clears '
			. '"finished" (the module hides finished banners even when published). Version-aware: on a site whose '
			. 'mod_flexbanners is 6.x, the module itself re-publishes any unfinished banner whose start date has '
			. 'passed and finishes any banner past its end date or limits, on every page render — the response '
			. 'warns when that will immediately undo what you just did, and clear_start_date avoids it for an '
			. 'unpublish.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'              => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Banner ids.'],
				'state'            => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed']],
				'clear_start_date' => ['type' => 'boolean', 'description' => 'Also clear the start date (stops the 6.x module re-publishing an unpublished banner).'],
			],
			'required'             => ['ids', 'state'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.edit.state');

		$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['ids'] ?? [])))));

		if ($ids === []) {
			throw new \InvalidArgumentException('ids is required.');
		}

		$state    = $this->fbParseState($arguments['state'] ?? '');
		$enforces = $this->fbModuleEnforcesLimits();
		$db       = $this->db;
		$now      = $this->fbNow();
		$results  = [];

		foreach ($ids as $id) {
			$row = $this->fbBanner($id);

			if ($row === null) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'no such banner'];
				continue;
			}

			$set = [
				'state = ' . $state,
				'published = ' . $state,
				'modified = ' . $db->quote($now),
				'modified_by = ' . (int) $actor->id,
			];

			if ($state === 1) {
				$set[] = 'finished = 0';
			}

			if (!empty($arguments['clear_start_date'])) {
				$set[] = 'startdate = NULL';
			}

			if ($state < 0) {
				$set[] = 'ordering = 0';
			}

			$db->setQuery('UPDATE ' . $db->quoteName($this->fbTable('')) . ' SET ' . implode(', ', $set) . ' WHERE id = ' . $id)->execute();

			$after = $this->fbBanner($id);
			$entry = [
				'id'       => $id,
				'ok'       => (int) $after['state'] === $state,
				'name'     => (string) $after['name'],
				'state'    => $this->fbStateLabel((int) $after['state']),
				'finished' => (int) $after['finished'] === 1,
			];

			if ($enforces) {
				if ($state === 0 && $this->fbHasDate($after['startdate']) && (string) $after['startdate'] <= $now && (int) $after['finished'] === 0) {
					$entry['warning'] = 'This site\'s mod_flexbanners (6.x) will re-publish this banner on the next page view, because its start date has passed. Call again with clear_start_date: true.';
				}

				$expired = $this->fbHasDate($after['enddate']) && (string) $after['enddate'] < $now;
				$capped  = ((int) $after['maximpressions'] > 0 && (int) $after['impmade'] >= (int) $after['maximpressions'])
					|| ((int) $after['maxclicks'] > 0 && (int) $after['clicks'] >= (int) $after['maxclicks']);

				if ($state === 1 && ($expired || $capped)) {
					$entry['warning'] = 'This banner is past its end date or limits; mod_flexbanners (6.x) will set it finished and unpublished again on the next page view. Change end_date / max_impressions / max_clicks (or reset its counters) first.';
				}
			}

			$results[] = $entry;
		}

		return ToolResult::json([
			'ok'      => !\in_array(false, array_column($results, 'ok'), true),
			'state'   => $this->fbStateLabel($state),
			'results' => $results,
		]);
	}
}
