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

final class CreateCategoryTool extends AbstractTool
{
	use CpnbBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'create_cpnb_category'; }

	public function getDescription(): string
	{
		return 'Add a cookie category to Cookies Policy Notification Bar\'s Cookies Manager. Required: id (lower-case '
			. 'letters, digits and hyphens, e.g. "marketing-cookies"; it becomes the key in visitors\' consent '
			. 'cookie and cannot be changed later) and name. Optional: description, default_state (locked / '
			. 'checked / unchecked; default unchecked, i.e. opt-in), enabled (default true), position (1-based; '
			. 'default last). The whole category list is read, the new row inserted, and the list written back '
			. 'through CPNB\'s own settings model, then re-read to confirm. On CPNB PRO, attach scripts to the '
			. 'category with the Pro add-on\'s set_cpnb_blocking_rule.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id', 'name'],
			'properties'           => ['id' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,63}$']]
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

		$id = trim((string) ($arguments['id'] ?? ''));

		if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
			throw new \InvalidArgumentException('id must be lower-case letters, digits and hyphens (e.g. "marketing-cookies").');
		}

		$settings    = $this->cpnbEffective();
		[$rows, $at] = $this->catFind($settings, $id);

		if ($at !== null) {
			throw new \InvalidArgumentException('A category with id "' . $id . '" already exists. Use update_cpnb_category.');
		}

		$row = $this->catApply([
			'cookie_category_id'                 => $id,
			'cookie_category_name'               => '',
			'cookie_category_description'        => '',
			'cookie_category_checked_by_default' => '0',
			'cookie_category_status'             => '1',
		], $arguments + ['enabled' => true]);

		$pos = isset($arguments['position']) ? max(1, min((int) $arguments['position'], \count($rows) + 1)) : \count($rows) + 1;
		array_splice($rows, $pos - 1, 0, [$row]);

		$wasStored = $this->cpnbSource('cookie_categories_group') === 'stored';
		$save      = $this->cpnbSave(['cookie_categories_group' => $this->cpnbKeyed($rows, 'cookie_categories_group')]);

		[$check, $found] = $this->catFind($this->cpnbStoredParams(), $id);

		if ($found === null) {
			throw new \RuntimeException('The save completed but category "' . $id . '" is not in the stored list.');
		}

		return ToolResult::json([
			'ok'       => true,
			'category' => ['position' => $found + 1] + $this->catFromRow($check[$found], []),
			'total'    => \count($check),
			'note'     => $wasStored ? null : 'The category list was previously CPNB\'s bundled defaults; those defaults are now stored explicitly alongside the new category.',
			'save'     => $save,
		]);
	}
}
