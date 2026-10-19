<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class UpdateModuleTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use ModuleSupportTrait;

	public function getName(): string { return 'update_flexbanners_module'; }

	public function getDescription(): string
	{
		return 'Change the FlexBanners settings of one mod_flexbanners module: which location it shows '
			. '(location_id), number_of_banners, banner_order, content_specific, load_last, header/footer text, '
			. 'class suffix, caching — plus title and published. Only the keys you pass change; every other module '
			. 'param, the position and the menu assignment are left exactly as they are (this tool rewrites the '
			. 'params JSON directly rather than re-saving the whole module, because a module save rewrites menu '
			. 'assignments from whatever it is given). For position or menu assignment use the core module tools.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'Module id.'],
				'location_id' => ['type' => 'integer', 'description' => 'FlexBanners location this module shows.'],
				'title'       => ['type' => 'string'],
				'published'   => ['type' => 'boolean'],
			] + $this->moduleParamSchema(),
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);
		$this->moduleRequireWrite($actor, false);

		$id     = $this->requirePositiveInt($arguments, 'id');
		$before = $this->moduleRequireFlexbanners($id);

		$checkedOut = (int) $this->db->setQuery('SELECT checked_out FROM ' . $this->db->quoteName('#__modules') . ' WHERE id = ' . $id)->loadResult();

		if ($checkedOut > 0 && $checkedOut !== (int) $actor->id) {
			throw new \RuntimeException('Module ' . $id . ' is checked out to user ' . $checkedOut . ' in the admin. Their next Save would overwrite this change; ask them to close it first.');
		}

		$params = $this->moduleParamsFromArgs($arguments);

		if (\array_key_exists('location_id', $arguments)) {
			$loc = (int) $arguments['location_id'];

			if ($this->fbRow('locations', 'locationid', $loc) === null) {
				throw new \InvalidArgumentException('location_id ' . $loc . ' does not exist.');
			}

			$params['locationid'] = (string) $loc;
		}

		$columns = [];

		if (\array_key_exists('title', $arguments)) {
			$title = trim((string) $arguments['title']);

			if ($title === '') {
				throw new \InvalidArgumentException('title cannot be empty.');
			}

			$columns[] = 'title = ' . $this->db->quote($title);
		}

		if (\array_key_exists('published', $arguments)) {
			if (!$actor->authorise('core.edit.state', 'com_modules')) {
				throw new \RuntimeException('Changing published requires core.edit.state on com_modules.');
			}

			$columns[] = 'published = ' . (!empty($arguments['published']) ? 1 : 0);
		}

		if ($params === [] && $columns === []) {
			throw new \InvalidArgumentException('Nothing to change.');
		}

		$menuBefore = $before['menu_ids'];

		if ($params !== []) {
			$this->moduleWriteParams($id, $params);
		}

		if ($columns !== []) {
			$this->db->setQuery('UPDATE ' . $this->db->quoteName('#__modules') . ' SET ' . implode(', ', $columns) . ' WHERE id = ' . $id)->execute();
		}

		$after = $this->fbModules()[$id];

		$preserved = [];

		foreach ($before['params'] as $k => $v) {
			if (!\array_key_exists($k, $params) && ($after['params'][$k] ?? null) !== $v) {
				$preserved[] = $k;
			}
		}

		return ToolResult::json([
			'ok'      => $preserved === [] && $after['menu_ids'] === $menuBefore,
			'before'  => $this->moduleShape($before),
			'after'   => $this->moduleShape($after),
			'params_changed' => array_keys($params),
			'unexpectedly_changed_params' => $preserved,
		]);
	}
}
