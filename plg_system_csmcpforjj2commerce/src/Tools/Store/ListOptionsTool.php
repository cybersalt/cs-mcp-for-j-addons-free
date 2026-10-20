<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListOptionsTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_options'; }

	public function getDescription(): string
	{
		return 'List the J2Commerce product option catalogue — the shared definitions (Size, Colour, Engraving '
			. 'text…) that products attach and variable products generate variants from — with each option\'s '
			. 'type (select, radio, checkbox, text, date, file and so on), its values, and how many products '
			. 'use it. A product\'s own price and weight modifiers per value are per product: get_j2commerce_product '
			. 'shows them. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => ['search' => ['type' => 'string']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewproducts');

		$db    = $this->db;
		$where = '';

		if (($s = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where = ' WHERE o.option_name LIKE ' . $this->j2Like($s) . ' OR o.option_unique_name LIKE ' . $this->j2Like($s);
		}

		$options = $db->setQuery(
			'SELECT o.*, (SELECT COUNT(DISTINCT po.product_id) FROM ' . $db->quoteName($this->j2Table('product_options')) . ' po'
			. ' WHERE po.option_id = o.j2commerce_option_id) AS products'
			. ' FROM ' . $db->quoteName($this->j2Table('options')) . ' o' . $where . ' ORDER BY o.ordering, o.option_name'
		)->loadAssocList() ?: [];

		$values = [];

		if ($options !== []) {
			$ids = implode(',', array_map(static fn (array $o): int => (int) $o['j2commerce_option_id'], $options));

			foreach ($db->setQuery(
				'SELECT j2commerce_optionvalue_id, option_id, optionvalue_name FROM ' . $db->quoteName($this->j2Table('optionvalues'))
				. ' WHERE option_id IN (' . $ids . ') ORDER BY ordering'
			)->loadAssocList() ?: [] as $v) {
				$values[(int) $v['option_id']][] = ['optionvalue_id' => (int) $v['j2commerce_optionvalue_id'], 'name' => (string) $v['optionvalue_name']];
			}
		}

		$out = array_map(static fn (array $o): array => [
			'option_id'   => (int) $o['j2commerce_option_id'],
			'name'        => (string) $o['option_name'],
			'unique_name' => (string) $o['option_unique_name'],
			'type'        => (string) $o['type'],
			'enabled'     => (int) $o['enabled'] === 1,
			'products'    => (int) $o['products'],
			'values'      => $values[(int) $o['j2commerce_option_id']] ?? [],
		], $options);

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'options' => $out]);
	}
}
