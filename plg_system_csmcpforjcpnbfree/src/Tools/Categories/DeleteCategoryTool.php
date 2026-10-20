<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

final class DeleteCategoryTool extends AbstractTool
{
	use CpnbBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'delete_cpnb_category'; }

	public function getDescription(): string
	{
		return 'Remove one cookie category from Cookies Policy Notification Bar, by id. Refused when PRO '
			. 'cookie-blocking rules still point at it (they would become scripts nobody can opt in to) unless '
			. 'force: true; refused for the last remaining category, because an empty list makes CPNB fall back '
			. 'to four hard-coded sample categories on the front end rather than showing none. Visitors\' '
			. 'existing consent cookies keep the old id until they next save their choices. Every other category '
			. 'is preserved.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id'],
			'properties'           => [
				'id'    => ['type' => 'string'],
				'force' => ['type' => 'boolean', 'description' => 'Delete even though blocking rules reference it.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$id          = trim((string) ($arguments['id'] ?? ''));
		$settings    = $this->cpnbEffective();
		[$rows, $at] = $this->catFind($settings, $id);

		if ($at === null) {
			throw new \InvalidArgumentException('No cookie category with id "' . $id . '".');
		}

		if (\count($rows) === 1) {
			throw new \InvalidArgumentException('Refusing to delete the last category: with an empty list CPNB shows four built-in sample categories instead of none. Disable it with update_cpnb_category (enabled: false) instead.');
		}

		$usage = $this->catUsage($settings);

		if (($usage[$id] ?? 0) > 0 && empty($arguments['force'])) {
			return ToolResult::json([
				'ok'    => false,
				'error' => $usage[$id] . ' cookie-blocking rule(s) belong to "' . $id . '". Deleting the category leaves those scripts blocked with no way for a visitor to allow them. Move or delete the rules first, or pass force: true.',
			], true);
		}

		$deleted = $this->catFromRow($rows[$at], $usage);
		array_splice($rows, $at, 1);

		$save = $this->cpnbSave(['cookie_categories_group' => $this->cpnbKeyed($rows, 'cookie_categories_group')]);

		[$check, $found] = $this->catFind($this->cpnbStoredParams(), $id);

		if ($found !== null) {
			throw new \RuntimeException('The save completed but category "' . $id . '" is still stored.');
		}

		return ToolResult::json([
			'ok'        => true,
			'deleted'   => $deleted,
			'remaining' => array_map(static fn (array $r): string => (string) ($r['cookie_category_id'] ?? ''), $check),
			'save'      => $save,
		]);
	}
}
