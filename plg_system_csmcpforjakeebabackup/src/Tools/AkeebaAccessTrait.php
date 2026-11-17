<?php
/**
 * @package     MCP for Akeeba Backup Core
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Applies Akeeba Backup's own permissions to the Joomla account the AI acts for.
 *
 * Same rules as Akeeba's screens (Dispatcher::checkAccess() and
 * AkeebaBackupACLTrait's view map): core.manage gets into the component, then
 * akeebabackup.backup to take a backup, akeebabackup.download to delete one and
 * akeebabackup.configure to see a profile's settings. A profile is usable only
 * at an access level the account has (ControllerProfileAccessTrait).
 */
trait AkeebaAccessTrait
{
	/** Returns a refusal naming the missing permission, or null when the account may go on. */
	protected function akeebaRefusal(User $actor, string $action = ''): ?ToolResult
	{
		$labels = [
			'core.manage'            => 'Access Administration Interface',
			'akeebabackup.backup'    => 'Backup',
			'akeebabackup.download'  => 'Download',
			'akeebabackup.configure' => 'Configure',
		];

		foreach (array_filter(['core.manage', $action]) as $required) {
			if (!$actor->authorise($required, 'com_akeebabackup')) {
				return ToolResult::error('Refused: the Joomla account this AI app acts for does not have Akeeba Backup\'s "' . $labels[$required] . '" permission (' . $required . '). '
					. 'MCP for J never lets the AI do more than that account could do in Akeeba Backup itself. A Super User can grant it in Akeeba Backup\'s Options, on the Permissions tab.');
			}
		}

		return null;
	}

	/** True when the profile's access level is one the account has. */
	protected function akeebaCanUseProfile(User $actor, int $access): bool
	{
		return \in_array($access, array_map('intval', $actor->getAuthorisedViewLevels()), true);
	}

	/**
	 * True when an engine setting holds a secret: an archive password, a remote
	 * storage password or key, or an OAuth token. Matched on the last part of the
	 * dotted name (engine.postproc.ftp.pass → "pass"). Checked against every
	 * setting in Akeeba Engine's own JSON definitions, which give these the
	 * "password" type, plus the OAuth tokens, private keys and Google Storage
	 * service-account JSON (jsoncreds) they store as text. The same rule covers
	 * the Multiple Databases filter's "password" in the profile's filters.
	 */
	protected function akeebaIsSecretSetting(string $name): bool
	{
		$leaf = strtolower((string) substr((string) strrchr('.' . $name, '.'), 1));

		return \in_array($leaf, ['pass', 'key', 'private', 'privkey', 'apikey', 'applicationkey', 'secretkey'], true)
			|| (bool) preg_match('/password|secret|token|cred/', $leaf);
	}

	/** "••••" plus the last 4 characters, the way MCP for J shows secrets. */
	protected function akeebaMask(string $value): string
	{
		if ($value === '') {
			return '';
		}

		return '••••' . (mb_strlen($value) > 8 ? mb_substr($value, -4) : '');
	}

	/**
	 * Mask the secrets in a profile's stored configuration.
	 *
	 * An encrypted blob (###AES128### / ###CTR128###) can only be read with the
	 * server key file, so it is returned as it is. A plain one is Akeeba's JSON
	 * (or the old INI format), where passwords and tokens sit in clear text.
	 */
	protected function akeebaMaskConfiguration(string $raw): string
	{
		if ($raw === '' || \in_array(substr($raw, 0, 12), ['###AES128###', '###CTR128###'], true)) {
			return $raw;
		}

		$decoded = json_decode($raw, true);

		if (\is_array($decoded)) {
			return (string) json_encode($this->akeebaMaskTree($decoded, ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		// Old INI format: one "name = value" per line, sections in [brackets].
		return (string) preg_replace_callback(
			'/^(\s*([A-Za-z0-9_.]+)\s*=\s*)(.*)$/m',
			fn (array $m): string => $this->akeebaIsSecretSetting($m[2])
				? $m[1] . '"' . $this->akeebaMask(trim($m[3], " \t\"'")) . '"'
				: $m[0],
			$raw
		);
	}

	/**
	 * Mask the secrets in a profile's filters. Akeeba stores them as plain JSON
	 * (never encrypted), and the Multiple Databases filter keeps each extra
	 * database's user and password there. Null when the blob is in a format
	 * this can't read (an old serialized one), so nothing unmasked slips out.
	 */
	protected function akeebaMaskFilters(string $raw): ?string
	{
		if ($raw === '') {
			return '';
		}

		$decoded = json_decode($raw, true);

		return \is_array($decoded)
			? (string) json_encode($this->akeebaMaskTree($decoded, ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			: null;
	}

	/**
	 * A backup tag or engine backup id, checked the way Akeeba's own backup
	 * controller filters them (Joomla's "cmd": letters, digits, _ . -). The
	 * engine builds its temporary and log file names from both, so anything
	 * else is refused rather than passed on. Null when the value is not valid.
	 */
	protected function akeebaCmd(string $value): ?string
	{
		return preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,99}$/', $value) ? $value : null;
	}

	private function akeebaMaskTree(array $tree, string $path): array
	{
		foreach ($tree as $key => $value) {
			$name = $path === '' ? (string) $key : $path . '.' . $key;

			if (\is_array($value)) {
				$tree[$key] = $this->akeebaMaskTree($value, $name);
			} elseif (\is_scalar($value) && $this->akeebaIsSecretSetting($name)) {
				$tree[$key] = $this->akeebaMask((string) $value);
			}
		}

		return $tree;
	}

	/** An exception message without SQL or server paths. */
	protected function akeebaSafeMessage(\Throwable $e): string
	{
		if ($e instanceof \Joomla\Database\Exception\ExecutionFailureException
			|| $e instanceof \Joomla\Database\Exception\ConnectionFailureException
			|| $e instanceof \PDOException || $e instanceof \mysqli_sql_exception) {
			return 'a database error occurred (error ' . (int) $e->getCode() . ')';
		}

		return \defined('JPATH_ROOT') ? str_replace(JPATH_ROOT, '[site root]', $e->getMessage()) : $e->getMessage();
	}
}
