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
}
