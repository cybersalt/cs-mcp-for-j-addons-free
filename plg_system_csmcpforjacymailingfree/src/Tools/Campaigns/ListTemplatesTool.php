<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Mails of type `template` (and, on request, the other non-campaign mail types:
 * welcome, unsubscribe, notification, override...).
 */
final class ListTemplatesTool extends AbstractTool
{
	use AcymailingBootTrait;

	private const TYPES = ['template', 'welcome', 'unsubscribe', 'notification', 'override', 'automation', 'followup', 'mailbox_action', 'scenario'];

	public function getName(): string { return 'list_acym_templates'; }

	public function getDescription(): string
	{
		return 'List AcyMailing email templates (mail type template) for use as create_acym_campaign\'s '
			. 'template_id: id, name, subject, whether it is a drag-and-drop template, thumbnail, and body size. '
			. 'Pass type to list other non-campaign mails instead: welcome / unsubscribe (attached to lists), '
			. 'notification (system emails such as acy_confirm), override (Joomla core email overrides), '
			. 'automation, followup. Set include_body: true for the HTML.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'type'         => ['type' => 'string', 'enum' => self::TYPES],
				'search'       => ['type' => 'string'],
				'include_body' => ['type' => 'boolean'],
				'limit'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset'       => ['type' => 'integer', 'minimum' => 0],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$type = \in_array($arguments['type'] ?? '', self::TYPES, true) ? $arguments['type'] : 'template';
		$rows = $this->db->setQuery(
			'SELECT id, name, subject, type, drag_editor, thumbnail, creation_date, language, parent_id, body FROM '
			. $this->acyQn($this->acyTable('mail')) . ' WHERE type = ' . $this->db->quote($type) . ' ORDER BY id'
		)->loadAssocList() ?: [];

		$search = strtolower(trim((string) ($arguments['search'] ?? '')));
		$out    = [];
		foreach ($rows as $r) {
			$r = $this->acyDecodeMail($r);
			if ($search !== '' && !str_contains(strtolower($r['name'] . ' ' . $r['subject']), $search)) {
				continue;
			}
			$r['body_bytes'] = \strlen((string) $r['body']);
			if (empty($arguments['include_body'])) {
				unset($r['body']);
			}
			$r['id']          = (int) $r['id'];
			$r['drag_editor'] = (int) $r['drag_editor'];
			$out[]            = $r;
		}

		$total = count($out);

		return ToolResult::json([
			'type'      => $type,
			'total'     => $total,
			'templates' => array_slice($out, $this->acyOffset($arguments), $this->acyLimit($arguments)),
		]);
	}
}
