<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools;

\defined('_JEXEC') or die;

/**
 * What each Condition rule type stores, how to check a value before saving it,
 * and how to say it in plain English.
 *
 * The params match what com_conditions/forms/item_rule.xml saves through
 * ItemModel::getConditionFromData(): `selection` plus the type's own options,
 * with list values as strings. The vendor's rule classes read options such as
 * include_children without a default, so every option is always written.
 *
 * This add-on writes only the types Advanced Module Manager Free can run. The
 * others are reported when found but can only be written by the Pro add-on.
 */
trait AmmRuleCatalogTrait
{
	/** Agent values come from RegularLabs\Library\Form\Field\AgentsField::getAgents(). */
	private const AMM_AGENT_OS = ['Windows', '#(Mac OS|Mac_PowerPC|Macintosh)#', '#(Linux|X11)#', 'OpenBSD', 'SunOS', 'QNX', 'BeOS', 'OS/2'];

	private const AMM_AGENT_BROWSER = ['Chrome', 'Firefox', 'MSIE Edge', 'MSIE [0-9]', 'Opera', 'Safari'];

	private const AMM_AGENT_MOBILE = ['mobile', 'Android', '#Android.*Chrome#', 'Blackberry', 'IEMobile', 'iPad', 'iPhone', 'iPod', 'NetFront', 'NokiaBrowser', 'Opera Mini', 'Opera Mobi', 'UC Browser'];

	/**
	 * sel:  what `selection` holds: a lookup kind, 'text', a list of allowed
	 *       values under 'enum' / 'multi', or null when the type has none.
	 * opts: the type's own options as name => [allowed values, default].
	 */
	private const AMM_FREE_RULE_TYPES = [
		'menu__menu_item' => ['label' => 'Menu items', 'sel' => 'menu_ids',
			'opts' => ['include_children' => [['0', '1', '2'], '0']],
			'help' => 'selection: menu item ids. include_children: 0 no, 1 yes, 2 children only.'],
		'menu__home_page' => ['label' => 'Home page', 'sel' => null, 'help' => 'No params. Matches the home page.'],
		'date__date' => ['label' => 'Date', 'sel' => null, 'dates' => true,
			'help' => 'comparison: before, after or between. date for before/after; from and to for between. recurring: 1 to ignore the year.'],
		'content__page_type' => ['label' => 'Joomla content page types', 'sel' => ['archive', 'article', 'categories', 'categoryblog', 'category', 'featured', 'form'], 'multi' => true,
			'help' => 'selection: archive, article, categories, categoryblog, category, featured, form.'],
		'content__category' => ['label' => 'Joomla content categories', 'sel' => 'category_ids',
			'opts' => ['include_children' => [['0', '1', '2'], '0']],
			'lists' => ['page_types' => [['categories', 'articles', 'others'], ['categories', 'articles']]],
			'help' => 'selection: category ids. include_children: 0 no, 1 yes, 2 children only. page_types: any of categories, articles, others.'],
		'content__article__id' => ['label' => 'Articles', 'sel' => 'article_ids', 'help' => 'selection: article ids.'],
		'content__article__featured' => ['label' => 'Article is featured', 'sel' => null, 'help' => 'No params.'],
		'content__article__status' => ['label' => 'Article status', 'sel' => ['1', '0', '2', '-2'], 'multi' => true,
			'help' => 'selection: 1 published, 0 unpublished, 2 archived, -2 trashed.'],
		'content__article__date' => ['label' => 'Article date', 'sel' => ['created', 'modified', 'publish_up', 'publish_down'], 'multi' => false, 'dates' => true,
			'help' => 'selection: created, modified, publish_up or publish_down, plus the date options as date__date.'],
		'content__article__author' => ['label' => 'Article authors', 'sel' => 'author_ids', 'help' => 'selection: user ids, or "current" for the visitor.'],
		'content__article__content_keyword' => ['label' => 'Article content keywords', 'sel' => 'text',
			'opts' => ['case_sensitive' => [['0', '1'], '0']], 'help' => 'selection: keywords, one per line.'],
		'content__article__meta_keyword' => ['label' => 'Article meta keywords', 'sel' => 'text',
			'opts' => ['case_sensitive' => [['0', '1'], '0']], 'help' => 'selection: keywords, one per line.'],
		'visitor__access_level' => ['label' => 'Visitor access levels', 'sel' => 'level_ids',
			'opts' => ['match_all' => [['0', '1'], '0']], 'help' => 'selection: access level ids. match_all: 1 to need all of them.'],
		'visitor__user_group' => ['label' => 'Visitor user groups', 'sel' => 'group_ids',
			'opts' => ['match_all' => [['0', '1'], '0'], 'include_children' => [['0', '1', '2'], '0']],
			'help' => 'selection: user group ids. match_all: 1 to need all of them. include_children: 0 no, 1 yes, 2 children only.'],
		'visitor__language' => ['label' => 'Visitor languages', 'sel' => 'languages', 'help' => 'selection: language tags such as en-GB.'],
		'agent__device' => ['label' => 'Devices', 'sel' => ['desktop', 'tablet', 'mobile'], 'multi' => true, 'help' => 'selection: desktop, tablet, mobile.'],
		'agent__os' => ['label' => 'Operating systems', 'sel' => self::AMM_AGENT_OS, 'multi' => true, 'help' => 'selection: the values listed under allowed_values.'],
		'agent__browser' => ['label' => 'Browsers', 'sel' => self::AMM_AGENT_BROWSER, 'multi' => true, 'help' => 'selection: the values listed under allowed_values.'],
		'agent__browser_mobile' => ['label' => 'Mobile browsers', 'sel' => self::AMM_AGENT_MOBILE, 'multi' => true, 'help' => 'selection: the values listed under allowed_values.'],
		'other__tag' => ['label' => 'Tags', 'sel' => 'tag_ids',
			'opts' => ['match_all' => [['0', '1'], '0'], 'include_children' => [['0', '1', '2'], '0']],
			'help' => 'selection: tag ids. match_all: 1 to need all of them. include_children: 0 no, 1 yes, 2 children only.'],
		'other__component' => ['label' => 'Components', 'sel' => 'components', 'help' => 'selection: component elements such as com_content.'],
		'other__template' => ['label' => 'Templates', 'sel' => 'templates', 'help' => 'selection: a template name, or name--styleid for one style.'],
		'other__url' => ['label' => 'URLs', 'sel' => 'text',
			'opts' => ['case_sensitive' => [['0', '1'], '0'], 'regex' => [['0', '1'], '0']],
			'help' => 'selection: URL parts, one per line. regex: 1 to treat each line as a regular expression.'],
		'other__condition' => ['label' => 'Another Condition Set', 'sel' => 'set_id', 'help' => 'selection: the id of another Condition Set.'],
	];

	/** Everything else the Conditions package can store, for describing rules this add-on will not write. */
	private const AMM_OTHER_RULE_LABELS = [
		'date__season' => 'Seasons', 'date__month' => 'Months', 'date__day' => 'Days', 'date__time' => 'Time',
		'content__article__field' => 'Article field', 'visitor__user' => 'Users', 'visitor__ip' => 'IP addresses',
		'geo__continent' => 'Continents', 'geo__country' => 'Countries', 'geo__region' => 'Regions', 'geo__postal_code' => 'Postal codes',
		'other__php' => 'PHP code',
		'flexicontent__page_type' => 'FLEXIcontent page types', 'flexicontent__tag' => 'FLEXIcontent tags', 'flexicontent__type' => 'FLEXIcontent types',
		'hikashop__page_type' => 'HikaShop page types', 'hikashop__category' => 'HikaShop categories', 'hikashop__item' => 'HikaShop products',
		'zoo__page_type' => 'ZOO page types', 'zoo__category' => 'ZOO categories', 'zoo__item' => 'ZOO items',
	];

	/** Lookup kinds whose selection is a list of ids: table, id column, title column, extra WHERE. */
	private const AMM_LOOKUPS = [
		'menu_ids'     => ['#__menu', 'id', 'title', 'client_id = 0 AND id > 1'],
		'category_ids' => ['#__categories', 'id', 'title', "extension = 'com_content'"],
		'article_ids'  => ['#__content', 'id', 'title', ''],
		'level_ids'    => ['#__viewlevels', 'id', 'title', ''],
		'group_ids'    => ['#__usergroups', 'id', 'title', ''],
		'tag_ids'      => ['#__tags', 'id', 'title', 'id > 1'],
		'author_ids'   => ['#__users', 'id', 'name', ''],
	];

	/** The rule types the Free edition runs: the list in the vendor's Free plugin, Helper::getConditionTypes(). */
	protected function ammFreeRuleTypes(): array
	{
		return array_keys(self::AMM_FREE_RULE_TYPES);
	}

	protected function ammOtherRuleLabels(): array
	{
		return self::AMM_OTHER_RULE_LABELS;
	}

	protected function ammRuleLabel(string $type): string
	{
		return self::AMM_FREE_RULE_TYPES[$type]['label'] ?? self::AMM_OTHER_RULE_LABELS[$type] ?? $type;
	}

	/** The writable types as the rule-types tool shows them. */
	protected function ammCatalog(): array
	{
		$out = [];

		foreach (self::AMM_FREE_RULE_TYPES as $type => $spec) {
			$row = ['type' => $type, 'label' => $spec['label'], 'help' => $spec['help']];

			if (\is_array($spec['sel'] ?? null)) {
				$row['allowed_values'] = $spec['sel'];
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Turn one caller-supplied rule into what the vendor stores, or refuse.
	 *
	 * @param  array  $rule       ['type' => string, 'exclude' => bool|int, 'params' => array]
	 * @param  int    $selfSetId  The set being saved, so it can't be made to include itself.
	 *
	 * @throws \RuntimeException
	 */
	protected function ammBuildRule(array $rule, int $selfSetId = 0): object
	{
		$type = trim((string) ($rule['type'] ?? ''));

		if ($type === '') {
			throw new \RuntimeException('Nothing was saved. Every rule needs a type; list_advancedmodules_rule_types has them.');
		}

		if ($type === 'other__php') {
			throw new \RuntimeException('Nothing was saved. PHP rules run code on the server, so these tools never write them, on any edition or for any user. Add one in the Joomla administrator if you need it.');
		}

		if (!isset(self::AMM_FREE_RULE_TYPES[$type])) {
			if (isset(self::AMM_OTHER_RULE_LABELS[$type])) {
				throw new \RuntimeException('Nothing was saved. "' . $this->ammRuleLabel($type) . '" (' . $type . ') is an Advanced Module Manager Pro rule type. Advanced Module Manager Free ignores it, so this add-on does not write it. The MCP for Advanced Module Manager Pro add-on does, on sites running Advanced Module Manager Pro.');
			}

			throw new \RuntimeException('Nothing was saved. Unknown rule type "' . $type . '"; list_advancedmodules_rule_types has the types.');
		}

		$spec   = self::AMM_FREE_RULE_TYPES[$type];
		$given  = (array) ($rule['params'] ?? []);
		$params = [];

		$allowed = array_merge(
			$spec['sel'] !== null ? ['selection'] : [],
			array_keys($spec['opts'] ?? []),
			array_keys($spec['lists'] ?? []),
			!empty($spec['dates']) ? ['comparison', 'date', 'from', 'to', 'recurring'] : []
		);

		if ($unknown = array_diff(array_keys($given), $allowed)) {
			throw new \RuntimeException('Nothing was saved. ' . $type . ' has no param named ' . implode(', ', $unknown) . '. ' . $spec['help']);
		}

		if ($spec['sel'] !== null) {
			$params['selection'] = $this->ammCheckSelection($type, $spec, $given['selection'] ?? null, $selfSetId);
		}

		foreach ($spec['opts'] ?? [] as $name => [$values, $default]) {
			$value = (string) ($given[$name] ?? $default);

			if (\is_bool($given[$name] ?? null)) {
				$value = $given[$name] ? '1' : '0';
			}

			if (!\in_array($value, $values, true)) {
				throw new \RuntimeException('Nothing was saved. ' . $type . ' ' . $name . ' must be one of ' . implode(', ', $values) . '.');
			}

			$params[$name] = $value;
		}

		foreach ($spec['lists'] ?? [] as $name => [$values, $default]) {
			$list = array_values(array_map('strval', (array) ($given[$name] ?? $default)));

			if ($list === [] || array_diff($list, $values)) {
				throw new \RuntimeException('Nothing was saved. ' . $type . ' ' . $name . ' must be one or more of ' . implode(', ', $values) . '.');
			}

			$params[$name] = $list;
		}

		if (!empty($spec['dates'])) {
			$params += $this->ammCheckDates($type, $given);
		}

		if (($params['regex'] ?? '0') === '1') {
			$this->ammCheckRegexLines((string) $params['selection']);
		}

		return (object) [
			'type'    => $type,
			'exclude' => $this->ammBool($rule['exclude'] ?? false, 'exclude') ? 1 : 0,
			'params'  => (object) $params,
		];
	}

	/** @throws \RuntimeException */
	private function ammCheckSelection(string $type, array $spec, mixed $value, int $selfSetId): mixed
	{
		$kind = $spec['sel'];
		$bad  = static fn(string $why) => new \RuntimeException('Nothing was saved. ' . $type . ' selection ' . $why . ' ' . $spec['help']);

		if (\is_array($kind)) {
			if (empty($spec['multi'])) {
				$single = (string) (\is_array($value) ? ($value[0] ?? '') : $value);

				if (!\in_array($single, $kind, true)) {
					throw $bad('must be one of ' . implode(', ', $kind) . '.');
				}

				return $single;
			}

			$list = array_values(array_unique(array_map('strval', (array) $value)));

			if ($list === [] || array_diff($list, $kind)) {
				throw $bad('must be one or more of ' . implode(', ', $kind) . '.');
			}

			return $list;
		}

		if ($kind === 'text') {
			$text = trim((string) (\is_array($value) ? implode("\n", $value) : $value));

			if ($text === '' || \strlen($text) > 5000) {
				throw $bad('must be between 1 and 5000 characters.');
			}

			return $text;
		}

		if ($kind === 'set_id') {
			$setId = (int) (\is_array($value) ? ($value[0] ?? 0) : $value);

			if ($setId <= 0) {
				throw $bad('must be the id of another Condition Set.');
			}

			$this->ammCheckNestedSet($setId, $selfSetId);

			return (string) $setId;
		}

		$list = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string) $v), (array) $value), static fn($v) => $v !== '')));

		if ($list === [] || \count($list) > 2000) {
			throw $bad('must list between 1 and 2000 values.');
		}

		switch ($kind) {
			case 'languages':
				foreach ($list as $tag) {
					if (!preg_match('/^[a-z]{2,3}-[A-Z]{2,3}$/', $tag)) {
						throw $bad('holds "' . $tag . '", which is not a language tag.');
					}
				}

				return $list;

			case 'components':
				foreach ($list as $element) {
					if (!preg_match('/^com_[a-z0-9_]+$/', $element)) {
						throw $bad('holds "' . $element . '", which is not a component element.');
					}
				}

				return $list;

			case 'templates':
				foreach ($list as $template) {
					if (!preg_match('/^[A-Za-z0-9_.\-]+(--\d+)?$/', $template)) {
						throw $bad('holds "' . $template . '", which is not a template name.');
					}
				}

				return $list;
		}

		$extra = [];

		if ($kind === 'menu_ids') {
			// The vendor's own picker can also store a whole menu by its menutype.
			$extra = array_values(array_filter($list, static fn($v) => !ctype_digit($v)));

			if ($extra) {
				$known = $this->db->setQuery(
					'SELECT ' . $this->db->quoteName('menutype') . ' FROM ' . $this->db->quoteName('#__menu_types')
				)->loadColumn() ?: [];

				if ($unknown = array_diff($extra, $known)) {
					throw $bad('names no menu or menu item called: ' . implode(', ', $unknown) . '.');
				}
			}
		}

		if ($kind === 'author_ids') {
			$extra = array_values(array_intersect($list, ['current']));
		}

		$ids = array_values(array_diff($list, $extra));

		foreach ($ids as $id) {
			if (!ctype_digit($id)) {
				throw $bad('holds "' . $id . '", which is not an id.');
			}
		}

		$found = $this->ammLookup($kind, array_map('intval', $ids));

		if ($missing = array_diff(array_map('intval', $ids), array_keys($found))) {
			throw $bad('holds ids that do not exist: ' . implode(', ', $missing) . '.');
		}

		return $list;
	}

	/**
	 * URL rules run against a URL the visitor controls, once per line and URL form,
	 * on every page. So each line has to compile, and a pattern that can backtrack
	 * catastrophically, like (a+)+, is refused rather than handed to visitors.
	 *
	 * @throws \RuntimeException
	 */
	private function ammCheckRegexLines(string $text): void
	{
		$lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn($l) => $l !== ''));

		if (\count($lines) > 50) {
			throw new \RuntimeException('Nothing was saved. other__url takes at most 50 regular expressions.');
		}

		foreach ($lines as $line) {
			if (\strlen($line) > 500) {
				throw new \RuntimeException('Nothing was saved. A regular expression in other__url is longer than 500 characters.');
			}

			if (preg_match('/\([^()]*[+*}][^()]*\)\s*[+*{]/', $line)) {
				throw new \RuntimeException('Nothing was saved. "' . $line . '" repeats a repeat, which can take a visitor\'s request minutes to check. Rewrite it without a quantifier on a group that already has one.');
			}

			if (@preg_match('#' . str_replace('#', '\#', $line) . '#si', '') === false) {
				throw new \RuntimeException('Nothing was saved. "' . $line . '" is not a valid regular expression.');
			}
		}
	}

	/** @throws \RuntimeException */
	private function ammCheckDates(string $type, array $given): array
	{
		$comparison = (string) ($given['comparison'] ?? 'after');

		if (!\in_array($comparison, ['before', 'after', 'between'], true)) {
			throw new \RuntimeException('Nothing was saved. ' . $type . ' comparison must be before, after or between.');
		}

		$date = function (string $name) use ($type, $given): string {
			$value = trim((string) ($given[$name] ?? ''));

			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}(:\d{2})?)?$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || strtotime($value) === false) {
				throw new \RuntimeException('Nothing was saved. ' . $type . ' ' . $name . ' must be a date like 2026-12-24 or 2026-12-24 18:00:00.');
			}

			return $value;
		};

		$out = [
			'comparison' => $comparison,
			'recurring'  => $this->ammBool($given['recurring'] ?? false, 'recurring') ? '1' : '0',
		];

		if ($comparison === 'between') {
			$out['from'] = $date('from');
			$out['to']   = $date('to');

			if ($out['recurring'] === '0' && strtotime($out['from']) > strtotime($out['to'])) {
				throw new \RuntimeException('Nothing was saved. ' . $type . ' from is after to, so the rule could never match.');
			}
		} else {
			$out['date'] = $date('date');
		}

		return $out;
	}

	/** Titles for ids of one lookup kind, keyed by id. */
	protected function ammLookup(string $kind, array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === [] || !isset(self::AMM_LOOKUPS[$kind])) {
			return [];
		}

		[$table, $idCol, $titleCol, $where] = self::AMM_LOOKUPS[$kind];
		$db = $this->db;

		return $db->setQuery(
			'SELECT ' . $db->quoteName($idCol) . ', ' . $db->quoteName($titleCol) . ' FROM ' . $db->quoteName($table)
			. ' WHERE ' . $db->quoteName($idCol) . ' IN (' . implode(',', $ids) . ')' . ($where !== '' ? ' AND ' . $where : '')
		)->loadAssocList($idCol, $titleCol) ?: [];
	}

	/** Plain English for one stored rule. PHP rules are described, never quoted. */
	protected function ammRuleSummary(object $rule): string
	{
		$type   = (string) ($rule->type ?? '');
		$params = (array) ($rule->params ?? []);
		$label  = $this->ammRuleLabel($type);
		$not    = !empty($rule->exclude);

		if ($type === 'other__php') {
			return ($not ? 'NOT ' : '') . 'PHP code decides (' . $this->ammPhpFacts($params)['lines'] . ' lines, not shown or run by these tools)';
		}

		$selection = $params['selection'] ?? null;
		$kind      = self::AMM_FREE_RULE_TYPES[$type]['sel'] ?? null;
		$values    = array_map('strval', \is_array($selection) ? $selection : ($selection === null || $selection === '' ? [] : [$selection]));

		if (\is_string($kind) && isset(self::AMM_LOOKUPS[$kind])) {
			$titles = $this->ammLookup($kind, array_filter($values, 'ctype_digit'));
			$values = array_map(
				static fn($v) => isset($titles[(int) $v]) ? $titles[(int) $v] . ' (' . $v . ')' : (ctype_digit($v) ? 'missing id ' . $v : $v),
				$values
			);
		}

		$text = $label;

		if ($values !== []) {
			$text .= ': ' . implode(', ', \array_slice($values, 0, 25)) . (\count($values) > 25 ? ', and ' . (\count($values) - 25) . ' more' : '');
		}

		if (isset($params['comparison'])) {
			$text .= ' ' . $params['comparison'] . ' ' . ($params['comparison'] === 'between'
				? ($params['from'] ?? '') . ' and ' . ($params['to'] ?? '')
				: ($params['date'] ?? $params['time'] ?? ''));
		}

		if (($params['include_children'] ?? '0') === '1') {
			$text .= ', with children';
		} elseif (($params['include_children'] ?? '0') === '2') {
			$text .= ', children only';
		}

		return ($not ? 'NOT ' : '') . $text;
	}

	/** Line count and fingerprint of a PHP rule, so it can be recognised without being shown. */
	protected function ammPhpFacts(array $params): array
	{
		$code = (string) ($params['selection'] ?? '');

		return ['lines' => substr_count($code, "\n") + ($code === '' ? 0 : 1), 'sha256' => hash('sha256', $code)];
	}

	/** A set's rules as one sentence. */
	protected function ammSetSummary(object $set): string
	{
		$parts = [];

		foreach ($set->groups as $group) {
			$rules = array_map(fn($r) => $this->ammRuleSummary($r), $group->rules);

			if ($rules !== []) {
				$parts[] = \count($rules) > 1
					? '(' . implode($group->match_all ? ' AND ' : ' OR ', $rules) . ')'
					: $rules[0];
			}
		}

		if ($parts === []) {
			return 'No rules, so it shows on every page.';
		}

		return 'Shows when ' . implode($set->match_all ? ' AND ' : ' OR ', $parts) . '.';
	}

	/**
	 * Fingerprint of what a set's rules mean. The vendor's own hash treats 5 and
	 * "5", or a different selection order, as different rules; this does not, so
	 * rules saved by different screens still compare as identical.
	 */
	protected function ammRulesFingerprint(object $set): string
	{
		$groups = [];

		foreach ($set->groups as $group) {
			$rules = [];

			foreach ($group->rules as $rule) {
				$params = (array) ($rule->params ?? []);

				foreach ($params as $key => $value) {
					if (\is_array($value) || \is_object($value)) {
						$list = array_map('strval', array_values((array) $value));
						sort($list, SORT_STRING);
						$params[$key] = $list;
					} else {
						$params[$key] = (string) $value;
					}
				}

				ksort($params);
				$rules[] = json_encode([(string) $rule->type, (int) !empty($rule->exclude), $params]);
			}

			sort($rules, SORT_STRING);
			$groups[] = json_encode([(int) $group->match_all, $rules]);
		}

		sort($groups, SORT_STRING);

		return md5(json_encode([(int) $set->match_all, $groups]));
	}
}
