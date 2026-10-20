<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Config;

\defined('_JEXEC') or die;

/**
 * How the flat CPNB settings map onto the dashboard's sections, so the config
 * tools can read and describe them in the vocabulary the admin screen uses.
 * Classification is by pattern rather than a hardcoded list so a key CPNB adds
 * in a later release lands in "other" instead of vanishing.
 */
trait ConfigSectionsTrait
{
	/** Settings that inject executable code into every page. */
	private const CPNB_CODE_KEYS = ['custom_js', 'custom_css'];

	/** @return string section name */
	protected function cpnbSectionOf(string $key): string
	{
		$family = $this->cpnbProFamily($key);

		if ($family !== null) {
			return 'pro: ' . $family;
		}

		return match (true) {
			\in_array($key, ['cookie_name', 'cookie_name_declined', 'cookie_name_cancelled', 'cookie_secure', 'cookie_httponly', 'share_cookies_across_subdomains'], true),
			str_starts_with($key, 'expiration_') => 'consent cookies',
			str_starts_with($key, 'modal') => 'cookies manager',
			str_contains($key, 'menu_items_show_notification_bar') => 'visibility',
			\in_array($key, ['enable_shortcode_functionality', 'shortcode_tag', 'hide_cookies_from_table'], true) => 'shortcode',
			\in_array($key, self::CPNB_CODE_KEYS, true) => 'custom code',
			str_contains($key, 'color') || str_contains($key, 'Color') || str_contains($key, '_class_')
				|| \in_array($key, ['borderWidth', 'fontSize', 'btn_font_size', 'btn_border_radius', 'height', 'center_alignment'], true) => 'styling',
			\in_array($key, ['position', 'show_close_x_icon', 'hide_after_time', 'duration', 'animate_duration', 'limit', 'always_display',
				'body_cover', 'overlay_state', 'show_in_iframes', 'autoAcceptAfterScrolling', 'numOfScrolledPixelsBeforeAutoAccept',
				'reloadPageAfterAccept'], true) || str_starts_with($key, 'enableConfirmationAlerts') => 'banner',
			default => 'other',
		};
	}
}
