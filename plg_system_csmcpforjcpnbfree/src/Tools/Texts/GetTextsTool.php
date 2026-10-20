<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Texts;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

/**
 * Per-language settings. Every text — and a few per-language switches such as
 * which buttons show and where "More info" points — is stored as
 * `<name>_<xx_XX>`. The front end reads the VISITOR's language tag and, when no
 * stored value exists for it, falls back to the plugin's language-file default
 * (not to another language's stored text).
 */
final class GetTextsTool extends AbstractTool
{
	use CpnbBootTrait;

	public function getName(): string { return 'get_cpnb_texts'; }

	public function getDescription(): string
	{
		return 'Read Cookies Policy Notification Bar\'s per-language settings for one language: the banner '
			. 'message (header_message), button labels (button_text, decline_btn_text, cancel_btn_text, '
			. 'settings_btn_text, button_more_text), which buttons show (ok_btn, decline_btn, cancel_btn, '
			. 'settings_btn, more_info_btn — "1"/"0"), where "More info" goes (more_info_btn_type: custom_text, '
			. 'link or menu_item; button_more_link; cpnb_modal_menu_item; link_target), the Cookies Manager '
			. 'labels, and confirmation/shortcode texts. Default language: the site\'s default front-end language. '
			. 'Each value says whether it is stored or will fall back to the language-file default on the live '
			. 'site. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'language' => ['type' => 'string', 'description' => 'Tag like en-GB or en_GB. Default: the site default language.'],
				'keys'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these base names (e.g. header_message, button_text).'],
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

		$default = $this->cpnbDefaultLanguageTag();
		$tag     = str_replace('-', '_', trim((string) ($arguments['language'] ?? '')) ?: $default);
		$active  = $this->cpnbActiveLanguageTags();
		$stored  = $this->cpnbStoredParams();
		$eff     = $this->cpnbEffective();
		$only    = array_map('strval', (array) ($arguments['keys'] ?? []));
		$texts   = [];
		$missing = 0;

		foreach ($this->cpnbLocalizedBaseKeys() as $base) {
			if ($only !== [] && !\in_array($base, $only, true)) {
				continue;
			}

			$key = $base . '_' . $tag;

			if (\array_key_exists($key, $stored)) {
				$texts[$base] = ['value' => $stored[$key], 'source' => 'stored'];
			} else {
				$missing++;
				$texts[$base] = [
					'value'  => $eff[$key] ?? null,
					'source' => isset($eff[$key]) ? 'not stored — the dashboard pre-fills this from CPNB\'s bundled defaults, but the LIVE site shows the plugin\'s language-file default until it is saved' : 'not stored — the live site shows the plugin\'s language-file default',
				];
			}
		}

		return ToolResult::json([
			'ok'               => true,
			'language'         => $tag,
			'is_site_default'  => $tag === $default,
			'is_active'        => \in_array($tag, $active, true),
			'active_languages' => $active,
			'not_stored_count' => $missing,
			'texts'            => $texts,
			'write_with'       => $tag === $default
				? 'set_cpnb_texts'
				: 'set_cpnb_language_texts (Cybersalt Pro add-on; texts in additional languages are a CPNB PRO capability)',
		]);
	}
}
