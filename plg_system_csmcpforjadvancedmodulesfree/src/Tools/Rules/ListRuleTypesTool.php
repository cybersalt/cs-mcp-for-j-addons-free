<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Rules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class ListRuleTypesTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'list_advancedmodules_rule_types'; }

	public function getDescription(): string
	{
		return 'Every rule type this add-on can write, with the params each takes, plus the types only the Pro edition runs and '
			. 'any the site has switched off. Use it before create_advancedmodules_condition_set or add_advancedmodules_rule. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions');

		$disabled = $this->ammDisabledRuleTypes();
		$types    = array_map(static function (array $row) use ($disabled): array {
			if (\in_array($row['type'], $disabled, true)) {
				$row['switched_off_on_this_site'] = true;
			}

			return $row;
		}, $this->ammCatalog());

		return ToolResult::json([
			'ok'         => true,
			'edition'    => $this->ammEdition(),
			'rule_types' => $types,
			'pro_only'   => array_diff_key($this->ammOtherRuleLabels(), ['other__php' => true]),
			'never_written' => ['other__php' => 'PHP code runs on the server. These tools report such rules but never write them.'],
			'note'       => 'Pro rule types are written by the MCP for Advanced Module Manager Pro add-on, on sites running Advanced Module Manager Pro. The Free edition skips them.',
		]);
	}
}
