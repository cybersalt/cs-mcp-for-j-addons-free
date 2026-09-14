<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Installations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

/**
 * Update an installation row in place. Deliberately bypasses InstallationModel's
 * save path, because that path (InstallationModel.php:60) unconditionally does
 *
 *     $table->email_hash = hash('sha256', strtolower(trim($table->email)));
 *
 * which is the wrong behaviour for a hand-repair op. AccessCheckHelper checks
 * `email` (Step 7, resolves to user) and `email_hash` (Step 3, matches the
 * hash the client sends) independently — so operators repair an installation
 * by changing `email` while leaving `email_hash` alone. Recomputing the hash
 * would silently break Step 3 with `email_mismatch`, a worse failure than the
 * `not_a_member` it was trying to fix.
 *
 * If the operator really does want the hash refreshed (e.g. after the client
 * reconfigures their plugin to send a new email), pass recompute_email_hash=true
 * — that makes the desync a deliberate, visible choice rather than a hidden
 * side effect.
 */
final class UpdateReleaseManagerInstallationTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'update_release_manager_installation'; }

	public function getDescription(): string
	{
		return 'Update an installation row. Provide installation_id or id, plus any of '
			. 'email, status (active/inactive/suspended/unlinked), notify_owner (0/1). '
			. 'By default email_hash is preserved when email changes — the intended '
			. 'hand-repair path is to re-point an installation at a different Cybersalt '
			. 'account by changing email alone. Pass recompute_email_hash:true only if '
			. 'the client has reconfigured their plugin to send the hash of the new '
			. 'address. Returns both email and email_hash so caller can see when they '
			. 'disagree.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'installation_id'       => ['type' => 'string'],
				'id'                    => ['type' => 'integer'],
				'email'                 => ['type' => 'string'],
				'status'                => ['type' => 'string', 'enum' => ['active', 'inactive', 'suspended', 'unlinked']],
				'notify_owner'          => ['type' => 'integer', 'enum' => [0, 1]],
				'recompute_email_hash'  => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		$row = $this->loadInstallation($arguments);
		if ($row === null) {
			return ToolResult::error('Installation not found. Provide installation_id or id.');
		}

		$updates = [];
		$fieldsChanged = [];

		if (array_key_exists('email', $arguments)) {
			$newEmail = trim((string) $arguments['email']);
			if ($newEmail === '') {
				return ToolResult::error('email cannot be empty. Use delete_release_manager_installation to remove a row.');
			}
			if ($newEmail !== (string) $row['email']) {
				$updates[$this->db->quoteName('email')] = $this->db->quote($newEmail);
				$fieldsChanged[] = 'email';

				if (!empty($arguments['recompute_email_hash'])) {
					$updates[$this->db->quoteName('email_hash')] = $this->db->quote(hash('sha256', strtolower($newEmail)));
					$fieldsChanged[] = 'email_hash';
				}
			}
		}

		if (array_key_exists('status', $arguments) && (string) $arguments['status'] !== (string) $row['status']) {
			$updates[$this->db->quoteName('status')] = $this->db->quote((string) $arguments['status']);
			$fieldsChanged[] = 'status';
		}

		if (array_key_exists('notify_owner', $arguments) && (int) $arguments['notify_owner'] !== (int) $row['notify_owner']) {
			$updates[$this->db->quoteName('notify_owner')] = (int) $arguments['notify_owner'];
			$fieldsChanged[] = 'notify_owner';
		}

		if (!$updates) {
			return ToolResult::json([
				'ok'             => true,
				'id'             => (int) $row['id'],
				'installation_id' => (string) $row['installation_id'],
				'fields_changed' => [],
				'email'          => (string) $row['email'],
				'email_hash'     => (string) $row['email_hash'],
				'note'           => 'No changes — supplied values already match stored row.',
			]);
		}

		$updates[$this->db->quoteName('modified')] = $this->db->quote(gmdate('Y-m-d H:i:s'));

		$assignments = [];
		foreach ($updates as $col => $val) {
			$assignments[] = $col . ' = ' . $val;
		}

		$q = $this->db->getQuery(true)
			->update($this->db->quoteName('#__csrm_installations'))
			->set($assignments)
			->where($this->db->quoteName('id') . ' = ' . (int) $row['id']);

		$this->db->setQuery($q)->execute();

		$fresh = $this->loadInstallation(['id' => (int) $row['id']]);

		return ToolResult::json([
			'ok'              => true,
			'id'              => (int) $fresh['id'],
			'installation_id' => (string) $fresh['installation_id'],
			'fields_changed'  => $fieldsChanged,
			'email'           => (string) $fresh['email'],
			'email_hash'      => (string) $fresh['email_hash'],
			'email_hash_matches_email' => hash_equals(
				hash('sha256', strtolower((string) $fresh['email'])),
				(string) $fresh['email_hash']
			),
			'status'          => (string) $fresh['status'],
			'notify_owner'    => (int) $fresh['notify_owner'],
		]);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function loadInstallation(array $arguments): ?array
	{
		$q = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__csrm_installations'));

		if (!empty($arguments['installation_id'])) {
			$q->where($this->db->quoteName('installation_id') . ' = ' . $this->db->quote((string) $arguments['installation_id']));
		} elseif (!empty($arguments['id'])) {
			$q->where($this->db->quoteName('id') . ' = ' . (int) $arguments['id']);
		} else {
			return null;
		}

		$row = $this->db->setQuery($q)->loadAssoc();
		return $row ?: null;
	}
}
