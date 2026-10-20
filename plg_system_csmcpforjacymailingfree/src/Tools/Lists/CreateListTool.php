<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a list through ListClass::save(), so onAcymBeforeListCreate /
 * onAcymAfterListCreate fire for any AcyMailing add-on that listens.
 */
final class CreateListTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'create_acym_list'; }

	public function getDescription(): string
	{
		return 'Create an AcyMailing list (type standard) through the vendor\'s ListClass::save(). Fields: name '
			. '(required), display_name (shown to subscribers), description, color (#rrggbb), active (default 1), '
			. 'visible (shown on subscription forms/profile, default 1), tracking (default 1), access (array of '
			. 'Joomla group ids allowed to see it on the front end; empty = everyone). The vendor strips HTML tags '
			. 'from every field. Sends nothing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['name'],
			'properties' => ListPayload::schema(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$payload = ListPayload::build($arguments);
		if (\is_string($payload)) {
			return ToolResult::error($payload);
		}
		if (trim((string) ($payload['name'] ?? '')) === '') {
			return ToolResult::error('name is required.');
		}

		$list = (object) array_merge(['active' => 1, 'visible' => 1, 'tracking' => 1, 'type' => 'standard', 'description' => ''], $payload);
		if (empty($list->color)) {
			$list->color = '#3366ff';
		}

		$before = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('list')))->loadResult();
		$class  = new \AcyMailing\Classes\ListClass();
		$id     = $class->save($list);
		$msgs   = $this->acyDrainMessages();

		if (empty($id)) {
			return ToolResult::error('ListClass::save() failed: ' . implode('; ', array_merge($class->errors, $msgs)));
		}

		$after = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('list')))->loadResult();
		$row   = $this->db->setQuery('SELECT * FROM ' . $this->acyQn($this->acyTable('list')) . ' WHERE id = ' . (int) $id)->loadAssoc();

		return ToolResult::json([
			'ok'              => true,
			'id'              => (int) $id,
			'rows_added'      => $after - $before,
			'list'            => $row,
			'vendor_messages' => $msgs,
		]);
	}
}
