<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * mod_flexbanners module rows. Needs FlexbannersBootTrait alongside it.
 *
 * Creating a module goes through com_modules' own ModuleModel (asset row, menu
 * assignment, cache clean, onExtension* events). Changing the FlexBanners
 * params of an existing module is a targeted rewrite of the params JSON with
 * every other key preserved, because ModuleModel::save() treats its input as
 * a whole form and rewrites menu assignments from whatever it is given.
 */
trait ModuleSupportTrait
{
	/** mod_flexbanners.xml params a tool may set, with how to validate each. */
	protected function moduleParamSchema(): array
	{
		return [
			'number_of_banners' => ['type' => 'integer', 'description' => 'How many banners the module shows per page (numberbanner, 1-20).'],
			'banner_order'      => ['type' => 'string', 'enum' => ['down', 'up', 'random'], 'description' => 'down = as the database returns them (insertion order), up = sorted by id, random = shuffled on every page view.'],
			'content_specific'  => ['type' => 'boolean', 'description' => 'enablecsa: honour per-banner category/article restrictions. Off = restricted banners show everywhere.'],
			'load_last'         => ['type' => 'boolean', 'description' => 'loadlast: render image banners as CSS backgrounds.'],
			'header_text'       => ['type' => 'string', 'description' => 'HTML above the banners.'],
			'footer_text'       => ['type' => 'string', 'description' => 'HTML below the banners.'],
			'moduleclass_sfx'   => ['type' => 'string', 'description' => 'Appended to the flexbannergroup / flexbanneritem / advert class names.'],
			'cache'             => ['type' => 'boolean', 'description' => 'Module caching. While cached, banners neither rotate nor count impressions.'],
		];
	}

	/** Translate tool arguments into mod_flexbanners param keys. */
	protected function moduleParamsFromArgs(array $args): array
	{
		$out = [];

		if (\array_key_exists('number_of_banners', $args)) {
			$n = (int) $args['number_of_banners'];

			if ($n < 1 || $n > 20) {
				throw new \InvalidArgumentException('number_of_banners must be 1-20 (the module form\'s range).');
			}

			$out['numberbanner'] = (string) $n;
		}

		if (\array_key_exists('banner_order', $args)) {
			$o = (string) $args['banner_order'];

			if (!\in_array($o, ['down', 'up', 'random'], true)) {
				throw new \InvalidArgumentException('banner_order must be down, up or random.');
			}

			$out['ordering'] = $o;
		}

		foreach (['content_specific' => 'enablecsa', 'load_last' => 'loadlast', 'cache' => 'cache'] as $arg => $key) {
			if (\array_key_exists($arg, $args)) {
				$out[$key] = !empty($args[$arg]) ? '1' : '0';
			}
		}

		foreach (['header_text', 'footer_text', 'moduleclass_sfx'] as $key) {
			if (\array_key_exists($key, $args)) {
				$out[$key] = (string) $args[$key];
			}
		}

		return $out;
	}

	protected function moduleRequireWrite(User $actor, bool $create): void
	{
		if (!$actor->authorise('core.manage', 'com_modules')) {
			throw new \RuntimeException('Module changes require core.manage on com_modules.');
		}

		$action = $create ? 'core.create' : 'core.edit';

		if (!$actor->authorise($action, 'com_modules')) {
			throw new \RuntimeException('This user is not granted ' . $action . ' on com_modules.');
		}
	}

	/** The single module row, or an error naming the problem. */
	protected function moduleRequireFlexbanners(int $id): array
	{
		$all = $this->fbModules();

		if (!isset($all[$id])) {
			throw new \InvalidArgumentException('Module ' . $id . ' is not a site mod_flexbanners module. list_flexbanners_modules shows them.');
		}

		return $all[$id];
	}

	/** Next ordering value at the end of a position. */
	protected function moduleNextOrdering(string $position): int
	{
		return (int) $this->db->setQuery(
			'SELECT COALESCE(MAX(ordering), 0) + 1 FROM ' . $this->db->quoteName('#__modules')
			. ' WHERE client_id = 0 AND position = ' . $this->db->quote($position) . ' AND published >= 0'
		)->loadResult();
	}

	/**
	 * Clone a mod_flexbanners module through com_modules' ModuleModel.
	 *
	 * @return array{id:int,warning:?string}
	 */
	protected function moduleClone(array $source, string $title, string $position, array $paramOverrides, int $published, ?int $ordering): array
	{
		$raw = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName('#__modules') . ' WHERE id = ' . (int) $source['id']
		)->loadAssoc();

		$params = json_decode((string) ($raw['params'] ?? ''), true);
		$params = \is_array($params) ? $params : [];
		$params = array_merge($params, $paramOverrides);

		$menuIds = $source['menu_ids'];

		switch ($source['assignment']) {
			case 'all':
				$assignment = 0;
				$assigned   = [];
				break;
			case 'only':
				$assignment = 1;
				$assigned   = array_map('abs', $menuIds);
				break;
			case 'all_except':
				$assignment = -1;
				$assigned   = array_map('abs', $menuIds);
				break;
			default:
				$assignment = '-';
				$assigned   = [];
		}

		$data = [
			'id'           => 0,
			'title'        => $title,
			'note'         => (string) ($raw['note'] ?? ''),
			'content'      => '',
			'position'     => $position,
			'module'       => 'mod_flexbanners',
			'access'       => (int) $raw['access'],
			'showtitle'    => (int) $raw['showtitle'],
			'params'       => json_encode((object) $params),
			'client_id'    => 0,
			'language'     => (string) $raw['language'],
			'published'    => $published,
			'ordering'     => $ordering ?? $this->moduleNextOrdering($position),
			'publish_up'   => $raw['publish_up'],
			'publish_down' => $raw['publish_down'],
			'assignment'   => $assignment,
			'assigned'     => $assigned,
		];

		$model  = $this->getModel('com_modules', 'Module');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			throw new \RuntimeException('com_modules rejected the cloned module: ' . ($result['error'] ?: 'unknown error'));
		}

		return ['id' => $result['id'], 'warning' => !$result['ok'] && $result['error'] !== '' ? $result['error'] : null];
	}

	/** Rewrite named keys in a module's params JSON, preserving every other key. */
	protected function moduleWriteParams(int $id, array $changes): array
	{
		$raw    = (string) $this->db->setQuery('SELECT params FROM ' . $this->db->quoteName('#__modules') . ' WHERE id = ' . $id)->loadResult();
		$params = json_decode($raw === '' ? '{}' : $raw, true);
		$params = \is_array($params) ? $params : [];
		$before = $params;

		foreach ($changes as $k => $v) {
			$params[$k] = $v;
		}

		$this->db->setQuery(
			'UPDATE ' . $this->db->quoteName('#__modules') . ' SET params = ' . $this->db->quote(json_encode((object) $params)) . ' WHERE id = ' . $id
		)->execute();

		return [$before, $params];
	}

	/** Module rows after the change, for the response. */
	protected function moduleShape(array $m): array
	{
		$locations = $this->fbLookup('locations', 'locationid');

		return [
			'id'                => $m['id'],
			'title'             => $m['title'],
			'position'          => $m['position'],
			'published'         => $m['published'] === 1,
			'ordering'          => $m['ordering'],
			'location'          => ['id' => $m['locationid'], 'name' => isset($locations[$m['locationid']]) ? (string) $locations[$m['locationid']]['name'] : null],
			'number_of_banners' => $m['numberbanner'],
			'banner_order'      => match (true) {
				\in_array($m['banner_order'], ['down', 'up', 'random'], true) => $m['banner_order'],
				$m['banner_order'] === ''                                      => 'down (not set; the default)',
				default                                                        => $m['banner_order'] . ' (not a valid choice; behaves as "down")',
			},
			'content_specific'  => $m['enablecsa'] === 1,
			'cache'             => $m['cache'] === 1,
			'menu_assignment'   => $m['assignment'],
			'menu_ids'          => $m['menu_ids'],
			'render_problems'   => $this->fbModuleRenderProblems($m),
		];
	}
}
