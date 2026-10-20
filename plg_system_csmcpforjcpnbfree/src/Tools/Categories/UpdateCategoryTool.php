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

final class UpdateCategoryTool extends AbstractTool
{
	use CpnbBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'update_cpnb_category'; }

	public function getDescription(): string
	{
		return 'Change one Cookies Policy Notification Bar cookie category, identified by id. Only the fields you '
			. 'pass change: name, description, default_state (locked / checked / unchecked), enabled, position '
			. '(1-based, to reorder). The id itself cannot be changed — it is the key in every visitor\'s stored '
			. 'consent and in every blocking rule; create a new category and delete the old one instead. Every '
			. 'other category is preserved and the result is re-read from the database.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id'],
			'properties'           => ['id' => ['type' => 'string']]
				+ $this->catSchemaFields()
				+ ['position' => ['type' => 'integer', 'minimum' => 1]],
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
			throw new \InvalidArgumentException('No cookie category with id "' . $id . '". list_cpnb_categories shows the ids.');
		}

		$fields = array_intersect_key($arguments, array_flip(['name', 'description', 'default_state', 'enabled', 'position']));

		if ($fields === []) {
			throw new \InvalidArgumentException('Pass at least one of name, description, default_state, enabled, position.');
		}

		$before = $this->catFromRow($rows[$at], []);
		$row    = $this->catApply($rows[$at], $fields);

		array_splice($rows, $at, 1);
		$pos = isset($fields['position']) ? max(1, min((int) $fields['position'], \count($rows) + 1)) : $at + 1;
		array_splice($rows, $pos - 1, 0, [$row]);

		$save = $this->cpnbSave(['cookie_categories_group' => $this->cpnbKeyed($rows, 'cookie_categories_group')]);

		[$check, $found] = $this->catFind($this->cpnbStoredParams(), $id);

		if ($found === null || \count($check) !== \count($rows)) {
			throw new \RuntimeException('The save completed but the stored category list does not match what was written.');
		}

		$warnings = [];

		if (\array_key_exists('enabled', $fields) && !$fields['enabled'] && $id === 'required-cookies') {
			$warnings[] = 'You disabled required-cookies. Strictly necessary cookies need no consent; hiding the category does not stop Joomla setting its session cookie.';
		}

		return ToolResult::json([
			'ok'       => true,
			'before'   => $before,
			'after'    => ['position' => $found + 1] + $this->catFromRow($check[$found], $this->catUsage($this->cpnbEffective())),
			'warnings' => $warnings,
			'save'     => $save,
		]);
	}
}
