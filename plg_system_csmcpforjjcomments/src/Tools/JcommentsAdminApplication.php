<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

/**
 * An AdministratorApplication for running JComments' notification code from
 * inside an API request. Not a tool; JcommentsBootTrait::jcWithAdminApp()
 * builds it.
 *
 * The one difference from the stock class is getTemplate(). Joomla validates
 * the template against JPATH_THEMES, and inside an API request that constant
 * is api/templates, which does not exist, so the stock getTemplate() always
 * throws "Could not find template atum" (CMSApplication::getTemplate() and
 * isValidTemplate(); found live on Joomla 6.2.0). JComments calls it to look
 * for a template override of its mail layout
 * (site/classes/factory.php getTemplate(): JPATH_SITE/templates/<name>/html/
 * com_jcomments/...). In a real administrator click <name> is the admin
 * template, which has no such folder, so JComments falls back to its own
 * layout. Returning the admin template's name reproduces that exactly.
 */
final class JcommentsAdminApplication extends AdministratorApplication
{
	private ?\stdClass $mcpTemplate = null;

	public function getTemplate($params = false)
	{
		if ($this->mcpTemplate === null) {
			$name = '';

			try {
				$db   = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
				$name = (string) $db->setQuery(
					"SELECT template FROM " . $db->quoteName('#__template_styles') . " WHERE client_id = 1 AND home = '1'",
					0,
					1
				)->loadResult();
			} catch (\Throwable $e) {
				$name = '';
			}

			$this->mcpTemplate = (object) [
				'template'    => preg_replace('/[^a-z0-9_\-]/i', '', $name) ?: 'atum',
				'params'      => new Registry(),
				'inheritable' => 0,
				'parent'      => '',
			];
		}

		return $params ? $this->mcpTemplate : $this->mcpTemplate->template;
	}
}
