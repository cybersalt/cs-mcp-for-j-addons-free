<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class DuplicateConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'duplicate_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'Copy a Condition Set, for when a few modules need nearly the same rules. The copy starts unpublished and '
			. 'attached to nothing, and the original is untouched. Sets running PHP can\'t be copied here.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'set_id' => ['type' => 'integer'],
				'name'   => ['type' => 'string', 'description' => 'Default: the original\'s name; the vendor adds a number.'],
			],
			'required'             => ['set_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions', 'core.create');

		$id = $this->ammId($arguments, 'set_id');
		$this->ammRefuseIfPhp($id, 'copying it');

		$set            = $this->ammSet($id);
		$set->id        = 0;
		$set->alias     = '';
		$set->published = 0;

		if (($arguments['name'] ?? '') !== '') {
			$set->name = $this->ammPlainText('name', (string) $arguments['name'], 100);
		}

		$newId = $this->ammSaveSet($set);

		return ToolResult::json(['ok' => true, 'set' => $this->ammFormatSet($this->ammSet($newId))] + $this->ammEffectNotes());
	}
}
