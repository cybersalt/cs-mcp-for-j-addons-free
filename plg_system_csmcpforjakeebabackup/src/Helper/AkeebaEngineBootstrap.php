<?php
/**
 * @package     MCP for Akeeba Backup Core
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * One-shot bootstrap of Akeeba Backup's Engine for in-process use.
 *
 * Akeeba ships its Engine as a Composer-packed library under
 * administrator/components/com_akeebabackup/vendor/akeeba/engine. The Engine
 * needs several DEFINEs + an autoloader register + a Platform registration
 * before any model that talks to it (BackupModel, StatisticModel, etc.) will
 * function. The component's own admin code does this through the
 * AkeebaEngineTrait mixin; we can't reuse the trait (it's in Akeeba's
 * namespace), so this helper replicates the same sequence inline.
 *
 * Idempotent — call from every tool that needs the Engine; subsequent calls
 * after the first inside a request are no-ops.
 *
 * Returns the booted com_akeebabackup ComponentInterface for callers that
 * want its MVCFactory (to spin up Akeeba's models). Throws if Akeeba is not
 * installed.
 */
final class AkeebaEngineBootstrap
{
	private static bool $bootstrapped = false;

	public static function isInstalled(): bool
	{
		return is_dir(JPATH_ADMINISTRATOR . '/components/com_akeebabackup')
			&& is_file(JPATH_ADMINISTRATOR . '/components/com_akeebabackup/vendor/autoload.php');
	}

	/**
	 * @throws \RuntimeException if Akeeba Backup is not installed on this site
	 */
	public static function boot(): \Joomla\CMS\Extension\ComponentInterface
	{
		$app = Factory::getApplication();
		$component = $app->bootComponent('com_akeebabackup');

		if (self::$bootstrapped) {
			return $component;
		}

		if (!self::isInstalled()) {
			throw new \RuntimeException(
				'Akeeba Backup is not installed on this site. Install com_akeebabackup '
				. 'from https://www.akeeba.com/download/akeeba-backup.html first.'
			);
		}

		// Composer autoloader — registers Akeeba\Engine\* + every Akeeba namespace
		// the engine pulls in. The component's own bootstrap does the same call;
		// require_once is safe to repeat (the autoloader registers itself once).
		require_once JPATH_ADMINISTRATOR . '/components/com_akeebabackup/vendor/autoload.php';

		// Component's version.php defines AKEEBABACKUP_VERSION/_DATE/_PRO constants.
		// Akeeba's Dispatcher::onBeforeDispatch() does the same include — without
		// it, downstream Engine code that consults these constants (e.g. for
		// user-agent strings, capability checks) sees nothing.
		@include_once JPATH_ADMINISTRATOR . '/components/com_akeebabackup/version.php';

		// Engine constants — these mirror AkeebaEngineTrait::loadAkeebaEngine().
		if (!\defined('AKEEBAENGINE')) {
			\define('AKEEBAENGINE', 1);
		}
		if (!\defined('AKEEBAROOT')) {
			\define('AKEEBAROOT', realpath(
				JPATH_ADMINISTRATOR . '/components/com_akeebabackup/vendor/akeeba/engine/engine'
			));
		}
		if (!\defined('AKEEBA_CACERT_PEM')) {
			$caCertPath = class_exists('\\Composer\\CaBundle\\CaBundle')
				? \Composer\CaBundle\CaBundle::getBundledCaBundlePath()
				: JPATH_LIBRARIES . '/src/Http/Transport/cacert.pem';
			\define('AKEEBA_CACERT_PEM', $caCertPath);
		}

		// Engine-facing constants. Akeeba's Joomla Platform DEFINES these lazily
		// inside one of its boot methods (Platform.php ~L620, defaulting AKEEBA_VERSION
		// to "dev"), but that boot path only fires when the Platform's get_platform_*
		// methods are exercised in a specific order. Engine code under Engine\Core\Domain\*
		// references these constants directly (no leading backslash), so PHP throws
		// "Undefined constant Akeeba\Engine\Core\Domain\AKEEBA_VERSION" the moment a
		// step runs before the Platform's lazy defines. Pre-defining them here from
		// the component's own version.php (loaded above) closes that race.
		if (!\defined('AKEEBA_VERSION')) {
			\define('AKEEBA_VERSION', \defined('AKEEBABACKUP_VERSION') ? AKEEBABACKUP_VERSION : 'dev');
		}
		if (!\defined('AKEEBA_PRO')) {
			\define('AKEEBA_PRO', \defined('AKEEBABACKUP_PRO') ? (bool) AKEEBABACKUP_PRO : false);
		}
		if (!\defined('AKEEBA_DATE')) {
			\define('AKEEBA_DATE', \defined('AKEEBABACKUP_DATE') ? AKEEBABACKUP_DATE : date('Y-m-d'));
		}

		// Ensure a profile is set in the session so Engine setup doesn't crash
		// looking for one. Default = 1 (Default Backup Profile, shipped by Core).
		$profileId = $app->getSession()->get('akeebabackup.profile', null);
		if (\is_null($profileId)) {
			$app->getSession()->set('akeebabackup.profile', 1);
		}

		// Tell the Akeeba Engine where its Joomla platform adapter lives.
		\Akeeba\Engine\Platform::addPlatform(
			'joomla',
			JPATH_ADMINISTRATOR . '/components/com_akeebabackup/platform/Joomla'
		);

		// Encrypted-settings keyfile (Akeeba encrypts profile configurations on
		// disk — without this it can't decrypt them, which startBackup needs).
		\Akeeba\Engine\Factory::getSecureSettings()->setKeyFilename(
			JPATH_ADMINISTRATOR . '/components/com_akeebabackup/serverkey.php'
		);

		// Load the key NOW, which include()s serverkey.php and defines the global
		// AKEEBA_SERVERKEY constant. This is not optional. setKeyFilename() only
		// configures the CURRENT SecureSettings object, and that object is a
		// "temporary" Factory instance: BackupModel::startBackup() calls
		// Factory::nuke() before load_configuration(), the fresh SecureSettings it
		// gets afterwards has the default relative filename "serverkey.php", which
		// does not resolve from the API app's working directory, so the key comes
		// back empty, the AES-encrypted profile silently fails to decrypt, and the
		// backup starts on DEFAULT settings (default output folder, default
		// filters). The next step_akeeba_backup then decrypts correctly, looks for
		// its memory file in the profile's REAL output folder, and fails with
		// "Akeeba Engine detected a problem while saving temporary data".
		// Akeeba's own backend never hits this because its Dispatcher calls
		// loadAkeebaEngineConfiguration() (and so getKey()) before any nuke(); once
		// the constant exists every later SecureSettings instance reads it.
		\Akeeba\Engine\Factory::getSecureSettings()->getKey();

		// Akeeba's own bootstrap sets a push-notifications handler here. We
		// deliberately skip it: push notifications target their own admin UI
		// flow (websocket-like polling); from an MCP context they'd fire into
		// the wrong session anyway. The Engine works fine without one.

		// !!! IMPORTANT !!! per Akeeba's own comment — without this getInstance()
		// call the autoloader doesn't get triggered for some lazy classes and
		// the very next Platform method call fails.
		$DO_NOT_REMOVE = \Akeeba\Engine\Platform::getInstance();
		unset($DO_NOT_REMOVE);

		// Bind Joomla's database driver to the Engine's Joomla platform so all
		// Engine SQL flows through the live DI database, not whatever default
		// the Engine would pick up.
		$dbo = $component->getContainer()->get(DatabaseInterface::class);
		\Akeeba\Engine\Platform\Joomla::setDbDriver($dbo);

		self::$bootstrapped = true;
		return $component;
	}

	/**
	 * Turn an Engine status array into an unambiguous outcome for MCP callers.
	 *
	 * `done` means "stop calling step_akeeba_backup", which is true both when the
	 * backup finished AND when it failed; `succeeded` says which. The matching
	 * #__akeebabackup_backups row is looked up by its engine backupid so callers
	 * do not have to guess which record belongs to this run.
	 *
	 * @return array{state: string, done: bool, succeeded: bool, errored: bool, record: ?array, next_action: string}
	 */
	public static function describeOutcome(array $status, string $backupId): array
	{
		$errored  = !empty($status['Error']);
		$finished = !$errored && isset($status['HasRun']) && (int) $status['HasRun'] === 1;
		$state    = $errored ? 'failed' : ($finished ? 'complete' : 'running');

		$record = null;

		if ($backupId !== '') {
			try {
				$db  = Factory::getContainer()->get(DatabaseInterface::class);
				$row = $db->setQuery(
					$db->getQuery(true)
						->select($db->quoteName(['id', 'status', 'profile_id', 'archivename', 'absolute_path', 'total_size']))
						->from($db->quoteName('#__akeebabackup_backups'))
						->where($db->quoteName('backupid') . ' = ' . $db->quote($backupId))
						->order($db->quoteName('id') . ' DESC'),
					0,
					1
				)->loadAssoc();

				if ($row) {
					$record = [
						'id'            => (int) $row['id'],
						'status'        => (string) $row['status'],
						'profile_id'    => (int) $row['profile_id'],
						'archivename'   => (string) $row['archivename'],
						'absolute_path' => (string) $row['absolute_path'],
						'total_size'    => (int) $row['total_size'],
					];
				}
			} catch (\Throwable $e) {
				$record = null;
			}
		}

		$next = match ($state) {
			'running'  => 'Call step_akeeba_backup again with the same backup_id and tag.',
			'complete' => 'Backup finished. Do not call step_akeeba_backup again.',
			default    => 'Backup FAILED: ' . $status['Error'] . ' Do not call step_akeeba_backup again; '
				. 'start a new backup once the cause is fixed'
				. ($record ? ', and delete the failed record ' . $record['id'] . ' with delete_akeeba_backup if it is not wanted.' : '.'),
		};

		return [
			'state'       => $state,
			'done'        => $state !== 'running',
			'succeeded'   => $state === 'complete',
			'errored'     => $errored,
			'record'      => $record,
			'next_action' => $next,
		];
	}

	/**
	 * Load a profile's configuration into the Engine exactly as Akeeba's own
	 * loadAkeebaEngineConfiguration() does, and refuse rather than run on
	 * defaults when an encrypted profile cannot be decrypted.
	 *
	 * Returns the effective output directory (with Akeeba's [SITEROOT]-style
	 * variables still in place) so callers can report where the archive goes.
	 *
	 * @throws \RuntimeException when the profile is encrypted and no key is available
	 */
	public static function loadProfileConfiguration(int $profileId): string
	{
		$db  = Factory::getContainer()->get(DatabaseInterface::class);
		$raw = (string) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('configuration'))
				->from($db->quoteName('#__akeebabackup_profiles'))
				->where($db->quoteName('id') . ' = ' . $profileId)
		)->loadResult();

		$encrypted = \in_array(substr($raw, 0, 12), ['###AES128###', '###CTR128###'], true);

		if ($encrypted) {
			// Akeeba's load_configuration() does not throw when decryption fails
			// (decryptionException is off by default): it quietly loads defaults.
			// So decrypt once ourselves and check the result is a real profile.
			$secure = \Akeeba\Engine\Factory::getSecureSettings();
			$plain  = (string) $secure->getKey() === '' ? '' : (string) $secure->decryptSettings($raw);

			if (strpos($plain, '"akeeba"') === false && strpos($plain, '[akeeba]') === false) {
				throw new \RuntimeException(
					'Akeeba Backup profile ' . $profileId . ' is encrypted and could not be decrypted '
					. '(key file administrator/components/com_akeebabackup/serverkey.php missing or not '
					. 'matching). Refusing to back up on default settings. Nothing was started.'
				);
			}
		}

		\Akeeba\Engine\Platform::getInstance()->load_configuration($profileId);

		return (string) \Akeeba\Engine\Factory::getConfiguration()->get('akeeba.basic.output_directory', '');
	}
}
