<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Fetch a real front-end page as a first-time visitor and report what CPNB
 * actually served, compared with what is configured. A saved setting is only a
 * claim; this is the proof.
 */
final class CheckFrontendTool extends AbstractTool
{
	use CpnbBootTrait;

	public function getName(): string { return 'check_cpnb_frontend'; }

	public function getDescription(): string
	{
		return 'Fetch a page of this site over HTTP as a brand-new visitor (no cookies) and report what Cookies '
			. 'Policy Notification Bar actually rendered: whether the bar\'s config and script were emitted at all, '
			. 'the position, message and button texts the visitor would see, and the cookie categories offered — '
			. 'each compared with the stored settings for the site\'s default language, with any mismatch listed. '
			. 'Use after set_cpnb_config / set_cpnb_texts / category changes: a save that reports success is a '
			. 'claim, the rendered page is the proof. Optional: path (relative to the site root, default the home '
			. 'page). Same-site only. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'path'    => ['type' => 'string', 'description' => 'Relative path or query on this site, e.g. "" (home), "about-us", "index.php?option=com_content&view=article&id=1". No scheme or host.'],
				'timeout' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 60, 'description' => 'Seconds. Default 20.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$path = ltrim(trim((string) ($arguments['path'] ?? '')), '/');

		if (preg_match('#^[a-z][a-z0-9+.-]*:|^//|\\\\#i', $path) === 1) {
			throw new \InvalidArgumentException('path must be relative to this site (no scheme, host or backslashes).');
		}

		$url  = $this->siteRoot() . $path;
		$http = HttpFactory::getHttp();

		try {
			$response = $http->get($url, ['User-Agent' => 'Mozilla/5.0 (cs-mcp-for-j check_cpnb_frontend)', 'Cache-Control' => 'no-cache'], (int) ($arguments['timeout'] ?? 20));
		} catch (\Throwable $e) {
			return ToolResult::json(['ok' => false, 'url' => $url, 'error' => 'Fetch failed: ' . $e->getMessage()], true);
		}

		$status = (int) $response->code;
		$html   = (string) $response->body;

		$config     = null;
		$categories = null;
		$source     = null;

		if (preg_match_all('#<script[^>]*class="joomla-script-options[^"]*"[^>]*>(.*?)</script>#s', $html, $mm)) {
			foreach ($mm[1] as $json) {
				$opts = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true) ?? json_decode(trim($json), true);

				if (\is_array($opts) && isset($opts['cpnb_config'])) {
					$config     = $opts['cpnb_config'];
					$categories = $opts['cpnb_cookiesCategories'] ?? null;
					$source     = 'joomla-script-options';
					break;
				}
			}
		}

		if ($config === null && preg_match('#var cpnb_config = (\{.*?\});\s*\n#s', $html, $m) === 1) {
			$config = json_decode($m[1], true);
			$source = 'inline var cpnb_config';

			if (preg_match('#var cpnb_cookiesCategories = (\{.*?\});\s*\n#s', $html, $c) === 1) {
				$categories = json_decode($c[1], true);
			}
		}

		$scriptLoaded = str_contains($html, 'cookies-policy-notification-bar');

		$result = [
			'ok'             => true,
			'url'            => $url,
			'http_status'    => $status,
			'bar_rendered'   => \is_array($config),
			'script_loaded'  => $scriptLoaded,
			'config_source'  => $source,
		];

		if (!\is_array($config)) {
			$result['why_it_might_be_missing'] = [
				'the system plugin is disabled, or its access level is not Public',
				'this page\'s menu item is excluded (exclude_menu_items_show_notification_bar) or not in the include list',
				'GeoIP targeting hides it for the server\'s own IP country (this check is fetched FROM the server)',
				'a full-page cache is serving a copy rendered before CPNB was configured',
				'the site is offline, or the page errored (see http_status)',
			];

			return ToolResult::json($result);
		}

		$stored  = $this->cpnbStoredParams();
		$lang    = $this->cpnbDefaultLanguageTag();
		$checks  = [];
		$compare = function (string $what, mixed $expected, mixed $actual) use (&$checks): void {
			$checks[] = [
				'check'    => $what,
				'expected' => $expected,
				'rendered' => $actual,
				'match'    => $expected === null || (string) $expected === (string) $actual,
			];
		};

		$compare('position', $stored['position'] ?? null, $config['w357_position'] ?? null);

		if (isset($stored['header_message_' . $lang])) {
			$compare('message (' . $lang . ')', preg_replace("/\r\n|\r|\n/", '<br/>', (string) $stored['header_message_' . $lang]), $config['w357_message'] ?? null);
		}

		foreach (['button_text' => 'w357_buttonText', 'decline_btn_text' => 'w357_buttonDeclineText', 'settings_btn_text' => 'w357_buttonSettingsText'] as $key => $rendered) {
			if (isset($stored[$key . '_' . $lang]) && \array_key_exists($rendered, $config)) {
				$compare($key . ' (' . $lang . ')', $stored[$key . '_' . $lang], $config[$rendered]);
			}
		}

		$renderedCats = [];

		foreach ((array) $categories as $cat) {
			if (\is_array($cat) && isset($cat['cookie_category_id'])) {
				$renderedCats[] = (string) $cat['cookie_category_id'];
			}
		}

		$storedCats = array_map(static fn (array $c): string => (string) ($c['cookie_category_id'] ?? ''), $this->cpnbRows($stored, 'cookie_categories_group'));

		if (\array_key_exists('cookie_categories_group', $stored)) {
			$checks[] = [
				'check'    => 'cookie categories',
				'expected' => $storedCats,
				'rendered' => $renderedCats,
				'match'    => $storedCats === $renderedCats || ($storedCats === [] && $renderedCats !== []),
				'note'     => $storedCats === [] ? 'Stored list is empty, so CPNB rendered its built-in sample categories.' : null,
			];
		}

		$mismatches = array_values(array_filter($checks, static fn (array $c): bool => !$c['match']));

		$result['language_compared'] = $lang;
		$result['rendered'] = [
			'position'   => $config['w357_position'] ?? null,
			'message'    => $config['w357_message'] ?? null,
			'buttons'    => array_filter([
				'accept'   => ($config['w357_display_ok_btn'] ?? '') ? ($config['w357_buttonText'] ?? null) : null,
				'decline'  => ($config['w357_display_decline_btn'] ?? '') ? ($config['w357_buttonDeclineText'] ?? null) : null,
				'cancel'   => ($config['w357_display_cancel_btn'] ?? '') ? ($config['w357_buttonCancelText'] ?? null) : null,
				'settings' => ($config['w357_display_settings_btn'] ?? '') ? ($config['w357_buttonSettingsText'] ?? null) : null,
			], static fn ($v) => $v !== null),
			'categories' => $renderedCats,
		];
		$result['checks']     = $checks;
		$result['all_match']  = $mismatches === [];
		$result['mismatches'] = $mismatches;

		if ($mismatches !== []) {
			$result['hint'] = 'A mismatch right after a save usually means a page cache (Joomla, LiteSpeed, a CDN) is serving an older copy, or the page is in a different language than ' . $lang . '.';
		}

		return ToolResult::json($result);
	}

	/**
	 * The public site root. Under the API application Uri::root() ends in
	 * /api/, which is not where visitors are.
	 */
	private function siteRoot(): string
	{
		$live = trim((string) Factory::getApplication()->get('live_site', ''));
		$root = $live !== '' ? rtrim($live, '/') . '/' : Uri::root();
		$root = (string) preg_replace('#/api/?$#', '/', $root);

		return rtrim($root, '/') . '/';
	}
}
