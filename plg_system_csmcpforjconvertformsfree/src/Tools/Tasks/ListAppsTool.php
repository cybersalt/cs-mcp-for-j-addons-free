<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

final class ListAppsTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'list_convertforms_apps'; }

	public function getDescription(): string
	{
		return 'List the Convert Forms "apps" available on this site — the '
			. 'integrations a Task can run when a form is submitted (email '
			. 'notifications, mailing-list subscribe, and so on) — with the action '
			. 'names each one accepts and whether it needs a stored connection. '
			. 'Call this before create_convertforms_task so you use an app and '
			. 'action that exist here: the free edition ships only a couple of apps '
			. 'and the rest are Pro.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('tasks')) {
			return ToolResult::error(
				'#__convertforms_tasks does not exist. The Tasks engine arrived in '
				. 'Convert Forms 5.0 — this site is on ' . ($this->cfVersion() ?? 'an older release') . '.'
			);
		}

		$installed = $this->installedApps();
		$apps      = [];

		foreach ($installed as $element => $enabled) {
			$entry = [
				'app'      => $element,
				'enabled'  => $enabled,
				'actions'  => $this->actionsFor($element),
				'triggers' => $this->supportedTriggers($element),
			];

			if (!$enabled) {
				$entry['warning'] = 'Plugin convertformsapps/' . $element . ' is installed but disabled; '
					. 'tasks using it will not run until it is enabled.';
			}

			$apps[] = $entry;
		}

		return ToolResult::json([
			'ok'      => true,
			'edition' => $this->cfIsPro() ? 'pro' : 'free',
			'count'   => count($apps),
			'apps'    => $apps,
			'notes'   => [
				'The only trigger Convert Forms currently fires is "onNewSubmission" — '
					. 'App::$supportedTriggers has the edit and delete triggers commented out.',
				$this->cfIsPro()
					? 'Pro allows multiple tasks per app per form.'
					: 'On the free edition each app is limited to ONE task per form '
						. '(ConvertForms\Tasks\LimitAppUsage). Adding a second task for the same '
						. 'app on the same form will not run.',
			],
		]);
	}

	/**
	 * Apps installed as convertformsapps plugins, element => enabled.
	 *
	 * Read from #__extensions rather than the vendor's Apps::getList(), which
	 * also injects greyed-out placeholder tiles for Pro apps that are not
	 * installed at all — useful for an upsell UI, misleading for a tool caller.
	 *
	 * @return array<string, bool>
	 */
	private function installedApps(): array
	{
		$q = $this->db->getQuery(true)
			->select([$this->db->quoteName('element'), $this->db->quoteName('enabled')])
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('convertformsapps'))
			->order($this->db->quoteName('element') . ' ASC');

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$element       = (string) $row['element'];
			$out[$element] = (bool) $row['enabled'] && PluginHelper::isEnabled('convertformsapps', $element);
		}

		return $out;
	}

	/**
	 * Action names an app exposes. The vendor derives these from method names:
	 * ConvertForms\Tasks\App::getActions() scans for methods prefixed "action"
	 * and lowercases the remainder (actionCreateArticle -> "createarticle").
	 * Mirror that by reflecting the plugin class when it can be loaded.
	 *
	 * @return array<int, string>
	 */
	private function actionsFor(string $element): array
	{
		$app = $this->appInstance($element);

		if ($app !== null && method_exists($app, 'getActions')) {
			try {
				$actions = $app->getActions();
				if (is_array($actions) && $actions !== []) {
					return array_values(array_map('strval', array_keys($actions) === range(0, count($actions) - 1) ? $actions : array_keys($actions)));
				}
			} catch (\Throwable $e) {
				// Fall through to reflection.
			}
		}

		if ($app === null) {
			return [];
		}

		$actions = [];
		foreach (get_class_methods($app) as $method) {
			if (stripos($method, 'action') === 0 && strlen($method) > 6) {
				$actions[] = strtolower(substr($method, 6));
			}
		}

		return array_values(array_unique($actions));
	}

	/**
	 * Triggers the app declares. $supportedTriggers is protected, so read it
	 * reflectively rather than guessing.
	 *
	 * @return array<int, string>
	 */
	private function supportedTriggers(string $element): array
	{
		$app = $this->appInstance($element);
		if ($app === null) {
			return ['onNewSubmission'];
		}

		try {
			$property = new \ReflectionProperty($app, 'supportedTriggers');
			$property->setAccessible(true);
			$value = $property->getValue($app);

			return is_array($value) ? array_values(array_map('strval', $value)) : ['onNewSubmission'];
		} catch (\Throwable $e) {
			return ['onNewSubmission'];
		}
	}

	/** Instantiate an app through the vendor factory, or null when unavailable. */
	private function appInstance(string $element): ?object
	{
		if (!class_exists('\ConvertForms\Tasks\Apps')) {
			return null;
		}

		try {
			if (method_exists('\ConvertForms\Tasks\Apps', 'exists')
				&& !\ConvertForms\Tasks\Apps::exists($element)) {
				return null;
			}

			$app = \ConvertForms\Tasks\Apps::getApp($element);

			return is_object($app) ? $app : null;
		} catch (\Throwable $e) {
			return null;
		}
	}
}
