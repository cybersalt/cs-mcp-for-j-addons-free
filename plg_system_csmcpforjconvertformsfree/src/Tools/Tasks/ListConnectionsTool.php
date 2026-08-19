<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class ListConnectionsTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'list_convertforms_connections'; }

	public function getDescription(): string
	{
		return 'List the stored Convert Forms connections — the saved credentials '
			. 'that Tasks use to reach third-party services — by id, app and title, '
			. 'plus how many tasks reference each. Credentials themselves are never '
			. 'returned: this tool reports only which connections exist, so you can '
			. 'wire a task to one by id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'app' => ['type' => 'string', 'description' => 'Restrict to connections for one app.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('connections')) {
			return ToolResult::json([
				'ok'          => true,
				'count'       => 0,
				'connections' => [],
				'note'        => '#__convertforms_connections does not exist on this site. '
					. 'Connections arrived with the Tasks engine in Convert Forms 5.0, and the '
					. 'apps that need them (Mailchimp, HubSpot, webhooks, …) are Pro-only. '
					. 'The email app needs no connection.',
			]);
		}

		// `params` is deliberately excluded from the SELECT: it holds third-party
		// API credentials IN PLAIN JSON.
		//
		// ConvertForms\Tasks\ConnectionEncryption exists (in both editions), but is
		// only ever applied on .cnvf export and import — Connections::add() writes
		// json_encode($params) straight to the column. So these really are
		// cleartext secrets at rest, which makes excluding them more important, not
		// less. Returning them would hand every integration credential on the site
		// to whatever model is driving the conversation.
		$q = $this->db->getQuery(true)
			->select(['id', 'app', 'title', 'created'])
			->from($this->db->quoteName($this->cfTableName('connections')))
			->order($this->db->quoteName('app') . ' ASC, ' . $this->db->quoteName('id') . ' ASC');

		if (!empty($arguments['app'])) {
			$q->where($this->db->quoteName('app') . ' = ' . $this->db->quote(strtolower((string) $arguments['app'])));
		}

		$rows  = $this->db->setQuery($q)->loadAssocList() ?: [];
		$usage = $this->usageCounts();

		$connections = [];
		foreach ($rows as $row) {
			$id = (int) $row['id'];

			$connections[] = [
				'id'         => $id,
				'app'        => (string) $row['app'],
				'title'      => (string) $row['title'],
				'created'    => (string) $row['created'],
				'used_by_tasks' => $usage[$id] ?? 0,
			];
		}

		return ToolResult::json([
			'ok'          => true,
			'count'       => count($connections),
			'connections' => $connections,
			'note'        => 'Credentials are intentionally not returned. Create and edit '
				. 'connections in the Convert Forms admin UI; reference one here by id when '
				. 'creating a task.',
		]);
	}

	/** @return array<int, int> connection_id => task count */
	private function usageCounts(): array
	{
		if (!$this->cfTableExists('tasks')) {
			return [];
		}

		$q = $this->db->getQuery(true)
			->select([$this->db->quoteName('connection_id'), 'COUNT(*) AS ' . $this->db->quoteName('total')])
			->from($this->db->quoteName($this->cfTableName('tasks')))
			->where($this->db->quoteName('connection_id') . ' IS NOT NULL')
			->group($this->db->quoteName('connection_id'));

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[(int) $row['connection_id']] = (int) $row['total'];
		}

		return $out;
	}
}
