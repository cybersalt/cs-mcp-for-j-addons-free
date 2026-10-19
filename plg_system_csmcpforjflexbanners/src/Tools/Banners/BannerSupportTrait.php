<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * Banner-specific shaping and validation, shared by the banner tools.
 *
 * Needs FlexbannersBootTrait alongside it.
 */
trait BannerSupportTrait
{
	/** The schema properties create and update share. */
	protected function bannerSchemaProps(): array
	{
		return [
			'name'           => ['type' => 'string', 'description' => 'Banner name (admin label).'],
			'alias'          => ['type' => 'string', 'description' => 'Optional; derived from name. Made unique within the category, because FlexBanners refuses a duplicate alias on the next admin save.'],
			'location_id'    => ['type' => 'integer', 'description' => 'FlexBanners location (the "group" a mod_flexbanners module shows). See list_flexbanners_locations.'],
			'size_id'        => ['type' => 'integer', 'description' => 'Size preset (#__flexbannerssize). Its width/height become the <img> width/height attributes. See list_flexbanners_sizes.'],
			'catid'          => ['type' => 'integer', 'description' => 'Category id. Defaults to the first published public com_flexbanners category. The front end only needs it to exist and be visible to the viewer.'],
			'client_id'      => ['type' => 'integer', 'description' => 'Advertiser (#__flexbannersclient). Defaults to the first published client. Not used on the front end, but the admin form requires one.'],
			'type'           => ['type' => 'string', 'enum' => ['image', 'cloud_image', 'custom_code', 'flash_html5'], 'description' => 'Render branch. image (default): image_url relative to the site root. cloud_image: an absolute https URL. custom_code: raw HTML, echoed as-is with no click tracking (needs core.admin on com_flexbanners). flash_html5: legacy.'],
			'image_url'      => ['type' => 'string', 'description' => 'For type image: path relative to the site root, e.g. "images/banners/ad.jpg". The file must exist. Absolute URLs are refused (the module would prefix the site base to them) — use type cloud_image.'],
			'cloud_image_url' => ['type' => 'string', 'description' => 'For type cloud_image: absolute http(s) URL of the image.'],
			'custom_code'    => ['type' => 'string', 'description' => 'For type custom_code: the HTML.'],
			'flash_file'     => ['type' => 'string', 'description' => 'For type flash_html5: a file name inside images/banners/.'],
			'image_alt'      => ['type' => 'string', 'description' => 'Alt and title text. No double quotes or angle brackets: the module prints it into an attribute unescaped.'],
			'link_url'       => ['type' => 'string', 'description' => 'Click-through URL. Clicks go to /component/flexbanners/click/<id>, which counts the click and redirects. Creates a link row (or, on update, rewrites this banner\'s own link row; a link shared with other banners is split off rather than changed under them).'],
			'link_id'        => ['type' => 'integer', 'description' => 'Use an existing link row instead of link_url.'],
			'new_window'     => ['type' => 'boolean', 'description' => 'Open the click-through in a new tab (target=_blank).'],
			'language'       => ['type' => 'string', 'description' => '"*" (default) or a content language code. The module only shows "*" or the SITE DEFAULT language, whatever language the page is in.'],
			'state'          => ['description' => 'published (1, default on create), unpublished (0), archived (2), trashed (-2).'],
			'start_date'     => ['type' => 'string', 'description' => 'UTC. Empty string clears. No FlexBanners version hides a published banner before this date; 6.x auto-PUBLISHES an unpublished banner once it passes.'],
			'end_date'       => ['type' => 'string', 'description' => 'UTC. Empty string clears. Enforced only by the 6.x module (5.1.x stores and ignores it).'],
			'max_impressions' => ['type' => 'integer', 'description' => '0 = unlimited. Enforced only by the 6.x module.'],
			'max_clicks'     => ['type' => 'integer', 'description' => '0 = unlimited. Enforced only by the 6.x module.'],
			'restrict_category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Content-specific advertising: show only on these com_content category pages (and their articles). Takes effect only on modules with enablecsa = 1. Pass [] with restrict_article_ids [] to remove the restriction.'],
			'restrict_article_ids'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'As restrict_category_ids, for individual articles. (The FlexBanners admin form cannot save these — it stores article id 0 — so this is the only working way to set them.)'],
			'frontpage'      => ['type' => 'boolean', 'description' => 'With restriction on and enablecsa = 1, show on the home page.'],
		];
	}

	protected function bannerTypeFromArg(string $value): int
	{
		$type = array_search($value, self::FB_TYPES, true);

		if ($type === false) {
			throw new \InvalidArgumentException('type must be one of image, cloud_image, custom_code, flash_html5.');
		}

		return (int) $type;
	}

	/**
	 * Translate tool arguments into column values, validating each one.
	 * Only keys the caller sent are returned (plus derived ones), so an update
	 * never touches a column the caller did not name.
	 *
	 * @return array{0:array<string,mixed>,1:string[]} [columns, warnings]
	 */
	protected function bannerColumns(array $args, ?array $existing, User $actor): array
	{
		$cols     = [];
		$warnings = [];
		$db       = $this->db;

		if (\array_key_exists('name', $args)) {
			$name = trim((string) $args['name']);

			if ($name === '') {
				throw new \InvalidArgumentException('name cannot be empty.');
			}

			$this->fbRequireFits('name', $name);
			$cols['name'] = $name;
		}

		if (\array_key_exists('location_id', $args)) {
			$loc = (int) $args['location_id'];

			if ($this->fbRow('locations', 'locationid', $loc) === null) {
				throw new \InvalidArgumentException('location_id ' . $loc . ' does not exist. list_flexbanners_locations shows the locations.');
			}

			$cols['locationid'] = $loc;
		}

		if (\array_key_exists('size_id', $args)) {
			$size = (int) $args['size_id'];

			if ($this->fbRow('size', 'sizeid', $size) === null) {
				throw new \InvalidArgumentException('size_id ' . $size . ' does not exist. The module INNER JOINs on the size table, so the banner would never show. list_flexbanners_sizes shows the presets.');
			}

			$cols['sizeid'] = $size;
		}

		if (\array_key_exists('catid', $args)) {
			$catid = (int) $args['catid'];
			$cat   = $this->fbCategories([$catid])[$catid] ?? null;

			if ($cat === null) {
				throw new \InvalidArgumentException('catid ' . $catid . ' is not a category. The module INNER JOINs on #__categories, so the banner would never show.');
			}

			if ($cat['extension'] !== 'com_flexbanners') {
				$warnings[] = 'catid ' . $catid . ' is a ' . $cat['extension'] . ' category. It renders, but the FlexBanners admin form only offers com_flexbanners categories and will move the banner on its next save.';
			}

			if (!\in_array($cat['access'], $this->fbGuestViewLevels(), true)) {
				$warnings[] = 'category ' . $catid . ' is not visible to anonymous visitors (access ' . $cat['access'] . '), so neither is this banner.';
			}

			$cols['catid'] = $catid;
		}

		if (\array_key_exists('client_id', $args)) {
			$client = (int) $args['client_id'];

			if ($client !== 0 && $this->fbRow('client', 'clientid', $client) === null) {
				throw new \InvalidArgumentException('client_id ' . $client . ' does not exist. list_flexbanners_clients shows the clients.');
			}

			$cols['clientid'] = $client;
		}

		if (\array_key_exists('type', $args)) {
			$cols['type'] = $this->bannerTypeFromArg((string) $args['type']);
		}

		if (\array_key_exists('image_url', $args)) {
			$url = trim((string) $args['image_url']);

			if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
				throw new \InvalidArgumentException('image_url must be relative to the site root (e.g. images/banners/ad.jpg). mod_flexbanners prefixes the site base URL, so an absolute URL renders broken. For a remote image use type "cloud_image" with cloud_image_url.');
			}

			$this->fbRequireAttributeSafe('image_url', $url);
			$url = ltrim($url, '/');
			$this->fbRequireFits('image_url', $url);

			if ($url !== '') {
				$info = $this->fbImageFileInfo($url);

				if (!$info['exists']) {
					throw new \InvalidArgumentException('image_url "' . $url . '" does not exist under the site root. Upload the file first (it renders as a broken image otherwise).');
				}
			}

			$cols['imageurl'] = $url;
		}

		if (\array_key_exists('cloud_image_url', $args)) {
			$url = trim((string) $args['cloud_image_url']);

			if ($url !== '' && !preg_match('#^https?://[^/\s]+#i', $url)) {
				throw new \InvalidArgumentException('cloud_image_url must be an absolute http(s) URL.');
			}

			$this->fbRequireAttributeSafe('cloud_image_url', $url);
			$this->fbRequireFits('cloud_image_url', $url);
			$cols['cloud_imageurl'] = $url;
		}

		if (\array_key_exists('custom_code', $args)) {
			if (!$actor->authorise('core.admin', 'com_flexbanners')) {
				throw new \RuntimeException('custom_code is raw HTML printed on every page the banner shows on; writing it requires core.admin on com_flexbanners.');
			}

			$cols['customcode'] = (string) $args['custom_code'];
		}

		if (\array_key_exists('flash_file', $args)) {
			$file = basename(trim((string) $args['flash_file']));

			if ($file !== '' && !is_file(JPATH_ROOT . '/images/banners/' . $file)) {
				throw new \InvalidArgumentException('flash_file "' . $file . '" is not in images/banners/.');
			}

			$this->fbRequireAttributeSafe('flash_file', $file);
			$cols['flash'] = $file;
		}

		if (\array_key_exists('image_alt', $args)) {
			$alt = trim((string) $args['image_alt']);
			$this->fbRequireAttributeSafe('image_alt', $alt);
			$this->fbRequireFits('image_alt', $alt);
			$cols['imagealt'] = $alt;
		}

		if (\array_key_exists('new_window', $args)) {
			$cols['newwin'] = !empty($args['new_window']) ? 1 : 0;
		}

		if (\array_key_exists('language', $args)) {
			$cols['language'] = $this->fbValidateLanguage(trim((string) $args['language']) ?: '*');

			if ($cols['language'] !== '*' && $cols['language'] !== $this->fbSiteLanguage()) {
				$warnings[] = 'language ' . $cols['language'] . ' is not the site default (' . $this->fbSiteLanguage() . '). mod_flexbanners compares with the site default, not the page language, so this banner will not show.';
			}
		}

		if (\array_key_exists('state', $args)) {
			$cols['state']     = $this->fbParseState($args['state']);
			$cols['published'] = $cols['state'];
		}

		if (\array_key_exists('start_date', $args)) {
			$cols['startdate'] = $this->fbDate($args['start_date'], 'start_date');
		}

		if (\array_key_exists('end_date', $args)) {
			$cols['enddate'] = $this->fbDate($args['end_date'], 'end_date');
		}

		foreach (['max_impressions' => 'maximpressions', 'max_clicks' => 'maxclicks'] as $arg => $col) {
			if (\array_key_exists($arg, $args)) {
				$v = (int) $args[$arg];

				if ($v < 0) {
					throw new \InvalidArgumentException($arg . ' cannot be negative (0 = unlimited).');
				}

				$cols[$col] = $v;
			}
		}

		if (\array_key_exists('frontpage', $args)) {
			$cols['frontpage'] = !empty($args['frontpage']) ? 1 : 0;
		}

		// Cross-field checks against the merged result.
		$merged = array_merge($existing ?? [], $cols);

		if ($this->fbHasDate($merged['startdate'] ?? null) && $this->fbHasDate($merged['enddate'] ?? null)
			&& (string) $merged['enddate'] < (string) $merged['startdate']) {
			throw new \InvalidArgumentException('end_date is before start_date (FlexbannerTable::check() refuses that too).');
		}

		$type = (int) ($merged['type'] ?? 0);

		if ($type === 0 && trim((string) ($merged['imageurl'] ?? '')) === '' && ($existing === null || \array_key_exists('imageurl', $cols) || \array_key_exists('type', $cols))) {
			throw new \InvalidArgumentException('An image banner needs image_url.');
		}

		if ($type === 3 && trim((string) ($merged['cloud_imageurl'] ?? '')) === '') {
			throw new \InvalidArgumentException('A cloud_image banner needs cloud_image_url.');
		}

		if ($type === 2 && trim((string) ($merged['customcode'] ?? '')) === '') {
			throw new \InvalidArgumentException('A custom_code banner needs custom_code.');
		}

		if ($type === 1) {
			$warnings[] = 'flash_html5 is a legacy branch; its HTML5 path references undefined variables in mod_flexbanners.';
		}

		if ($this->fbModuleEnforcesLimits() && ($merged['state'] ?? null) !== null && (int) $merged['state'] === 0
			&& $this->fbHasDate($merged['startdate'] ?? null) && (string) $merged['startdate'] <= $this->fbNow()) {
			$warnings[] = 'This site\'s FlexBanners module (6.x) re-publishes every unfinished banner whose start date has passed, on every page render. This banner will be published again on the next page view unless start_date is cleared.';
		}

		return [$cols, $warnings];
	}

	/**
	 * Ensure the link for this banner: an explicit link_id, or a link_url that
	 * becomes a link row. Returns [linkid|null (unchanged), notes].
	 */
	protected function bannerResolveLink(array $args, ?array $existing, string $bannerName, int $clientId): array
	{
		$db    = $this->db;
		$notes = [];

		if (\array_key_exists('link_id', $args) && \array_key_exists('link_url', $args)) {
			throw new \InvalidArgumentException('Pass link_url OR link_id, not both.');
		}

		if (\array_key_exists('link_id', $args)) {
			$id = (int) $args['link_id'];

			if ($this->fbRow('link', 'linkid', $id) === null) {
				throw new \InvalidArgumentException('link_id ' . $id . ' does not exist. list_flexbanners_links shows them.');
			}

			return [$id, $notes];
		}

		if (!\array_key_exists('link_url', $args)) {
			return [null, $notes];
		}

		$url = $this->fbValidateLinkUrl((string) $args['link_url']);

		// Update in place when this banner is the only user of its current link.
		if ($existing !== null && (int) ($existing['linkid'] ?? 0) > 0) {
			$current = (int) $existing['linkid'];
			$link    = $this->fbRow('link', 'linkid', $current);

			if ($link !== null) {
				$users = $this->fbCount('', 'linkid = ' . $current);

				if ($users <= 1) {
					$db->setQuery(
						$db->getQuery(true)
							->update($db->quoteName($this->fbTable('link')))
							->set($db->quoteName('linkurl') . ' = ' . $db->quote($url))
							->where($db->quoteName('linkid') . ' = ' . $current)
					)->execute();

					$notes[] = 'Updated link ' . $current . ' in place (no other banner uses it).';

					return [$current, $notes];
				}

				$notes[] = 'Link ' . $current . ' is shared by ' . $users . ' banners, so a new link row was created for this one instead of changing theirs.';
			}
		}

		$row = (object) [
			'name'     => mb_substr($bannerName, 0, 255),
			'linkurl'  => $url,
			'clientid' => $clientId,
			'state'    => 1,
		];

		$db->insertObject($this->fbTable('link'), $row, 'linkid');
		$notes[] = 'Created link ' . (int) $row->linkid . '.';

		return [(int) $row->linkid, $notes];
	}

	/** Replace a banner's restriction rows. Pass null for "leave unchanged". */
	protected function bannerWriteRestrictions(int $bannerId, ?array $categoryIds, ?array $articleIds): ?array
	{
		if ($categoryIds === null && $articleIds === null) {
			return null;
		}

		$db = $this->db;

		$current = $this->bannerRestrictions($bannerId);
		$cats    = $categoryIds ?? $current['category_ids'];
		$arts    = $articleIds ?? $current['article_ids'];

		$cats = array_values(array_unique(array_filter(array_map('intval', $cats))));
		$arts = array_values(array_unique(array_filter(array_map('intval', $arts))));

		if ($cats !== []) {
			$found = array_map('intval', $db->setQuery(
				$db->getQuery(true)->select('id')->from($db->quoteName('#__categories'))
					->whereIn($db->quoteName('id'), $cats)
					->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
			)->loadColumn() ?: []);

			if (($missing = array_diff($cats, $found)) !== []) {
				throw new \InvalidArgumentException('restrict_category_ids: not com_content categories: ' . implode(', ', $missing) . '. The module matches the category of the article or category page being viewed.');
			}
		}

		if ($arts !== []) {
			$found = array_map('intval', $db->setQuery(
				$db->getQuery(true)->select('id')->from($db->quoteName('#__content'))->whereIn($db->quoteName('id'), $arts)
			)->loadColumn() ?: []);

			if (($missing = array_diff($arts, $found)) !== []) {
				throw new \InvalidArgumentException('restrict_article_ids: no such articles: ' . implode(', ', $missing) . '.');
			}
		}

		$db->setQuery('DELETE FROM ' . $db->quoteName($this->fbTable('in')) . ' WHERE bannerid = ' . $bannerId)->execute();

		foreach ($cats as $c) {
			$db->setQuery('INSERT INTO ' . $db->quoteName($this->fbTable('in')) . ' (bannerid, categoryid) VALUES (' . $bannerId . ', ' . $c . ')')->execute();
		}

		foreach ($arts as $a) {
			$db->setQuery('INSERT INTO ' . $db->quoteName($this->fbTable('in')) . ' (bannerid, contentid) VALUES (' . $bannerId . ', ' . $a . ')')->execute();
		}

		$restrict = ($cats !== [] || $arts !== []) ? 1 : 0;
		$db->setQuery('UPDATE ' . $db->quoteName($this->fbTable('')) . ' SET restrictbyid = ' . $restrict . ' WHERE id = ' . $bannerId)->execute();

		return ['category_ids' => $cats, 'article_ids' => $arts];
	}

	/** @return array{category_ids:int[],article_ids:int[]} */
	protected function bannerRestrictions(int $bannerId): array
	{
		$rows = $this->db->setQuery(
			'SELECT categoryid, contentid FROM ' . $this->db->quoteName($this->fbTable('in')) . ' WHERE bannerid = ' . $bannerId
		)->loadAssocList() ?: [];

		$cats = [];
		$arts = [];

		foreach ($rows as $r) {
			if ((int) $r['categoryid'] > 0) {
				$cats[] = (int) $r['categoryid'];
			}

			if ((int) $r['contentid'] > 0) {
				$arts[] = (int) $r['contentid'];
			}
		}

		return ['category_ids' => $cats, 'article_ids' => $arts];
	}

	/** Restriction args from the request, null when absent. */
	protected function bannerRestrictionArgs(array $args): array
	{
		$cats = \array_key_exists('restrict_category_ids', $args) ? (array) $args['restrict_category_ids'] : null;
		$arts = \array_key_exists('restrict_article_ids', $args) ? (array) $args['restrict_article_ids'] : null;

		return [$cats, $arts];
	}

	/** A full, readable banner with its lookups resolved and its display verdict. */
	protected function shapeBanner(array $row, bool $withAudit = true): array
	{
		$sizes      = $this->fbLookup('size', 'sizeid');
		$links      = $this->fbLookup('link', 'linkid');
		$locations  = $this->fbLookup('locations', 'locationid');
		$clients    = $this->fbLookup('client', 'clientid');
		$categories = $this->fbCategories([(int) $row['catid']]);

		$type     = (int) $row['type'];
		$sizeid   = (int) $row['sizeid'];
		$linkid   = (int) $row['linkid'];
		$locid    = (int) $row['locationid'];
		$clientid = (int) $row['clientid'];
		$catid    = (int) $row['catid'];

		$out = [
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'alias'         => (string) $row['alias'],
			'state'         => $this->fbStateLabel((int) $row['state']),
			'state_value'   => (int) $row['state'],
			'finished'      => (int) $row['finished'] === 1,
			'type'          => self::FB_TYPES[$type] ?? (string) $type,
			'location'      => ['id' => $locid, 'name' => isset($locations[$locid]) ? (string) $locations[$locid]['name'] : null],
			'size'          => isset($sizes[$sizeid])
				? ['id' => $sizeid, 'name' => (string) $sizes[$sizeid]['name'], 'width' => (int) $sizes[$sizeid]['width'], 'height' => (int) $sizes[$sizeid]['height']]
				: ['id' => $sizeid, 'missing' => true],
			'category'      => isset($categories[$catid])
				? ['id' => $catid, 'title' => $categories[$catid]['title'], 'extension' => $categories[$catid]['extension'], 'access' => $categories[$catid]['access']]
				: ['id' => $catid, 'missing' => true],
			'client'        => ['id' => $clientid, 'name' => isset($clients[$clientid]) ? (string) $clients[$clientid]['name'] : null],
			'image_url'     => $row['imageurl'],
			'cloud_image_url' => $row['cloud_imageurl'],
			'flash_file'    => $row['flash'],
			'image_alt'     => $row['imagealt'],
			'link'          => ['id' => $linkid, 'url' => isset($links[$linkid]) ? (string) $links[$linkid]['linkurl'] : null],
			'click_url'     => 'index.php?option=com_flexbanners&task=click&id=' . (int) $row['id'],
			'new_window'    => (int) $row['newwin'] === 1,
			'language'      => (string) $row['language'],
			'start_date'    => $this->fbHasDate($row['startdate']) ? $row['startdate'] : null,
			'end_date'      => $this->fbHasDate($row['enddate']) ? $row['enddate'] : null,
			'impressions'   => (int) $row['impmade'],
			'clicks'        => (int) $row['clicks'],
			'max_impressions' => (int) $row['maximpressions'],
			'max_clicks'    => (int) $row['maxclicks'],
			'restricted'    => (int) $row['restrictbyid'] === 1,
			'frontpage'     => (int) $row['frontpage'] === 1,
			'created_at'    => $row['created'],
			'modified_at'   => $row['modified'],
		];

		if ($type === 2) {
			$out['custom_code'] = $row['customcode'];
		}

		if ($type === 0 && (string) $row['imageurl'] !== '') {
			$out['image_file'] = $this->fbImageFileInfo((string) $row['imageurl']);
		}

		if ((int) $row['restrictbyid'] === 1) {
			$out['restrictions'] = $this->bannerRestrictions((int) $row['id']);
		}

		if ($withAudit) {
			$audit = $this->fbDisplayAudit($row, $sizes, $categories, $links, $this->fbModules(), $this->fbModuleEnforcesLimits());
			$out['displays']          = $audit['problems'] === [];
			$out['shown_by_modules']  = $audit['modules'];
			$out['display_problems']  = $audit['problems'];
			$out['display_warnings']  = $audit['warnings'];
		}

		return $out;
	}
}
