<?php

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
}
