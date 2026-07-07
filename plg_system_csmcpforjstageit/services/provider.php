<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Cybersalt\Plugin\System\Csmcpforjstageit\Extension\Csmcpforjstageit;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Csmcpforjstageit(
					$container->get(DispatcherInterface::class),
					(array) PluginHelper::getPlugin('system', 'csmcpforjstageit')
				);
				$plugin->setApplication(Factory::getApplication());
				$plugin->setDatabase($container->get(DatabaseInterface::class));

				return $plugin;
			}
		);
	}
};
