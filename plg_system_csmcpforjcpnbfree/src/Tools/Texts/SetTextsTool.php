<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Texts;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbProRequiredException;
use Joomla\CMS\User\User;

/**
 * Write the per-language settings for the site's DEFAULT language. Other
 * languages are CPNB PRO's "multilingual texts" and live in the Pro add-on.
 */
final class SetTextsTool extends AbstractTool
{
	use CpnbBootTrait;

	public function getName(): string { return 'set_cpnb_texts'; }

	public function getDescription(): string
	{
		return 'Set Cookies Policy Notification Bar texts for the site\'s default front-end language. Pass texts: '
			. '{base_name: value} with names from get_cpnb_texts, e.g. {"header_message": "We use cookies to …", '
			. '"button_text": "Accept all", "decline_btn_text": "Reject", "settings_btn": "1", '
			. '"more_info_btn_type": "link", "button_more_link": "/privacy-policy"}. Only the names you pass '
			. 'change; the value is stored under <name>_<site default tag>, which is what the bar shows visitors '
			. 'in that language. HTML is allowed in header_message and custom_text. Texts for any other language '
			. 'are a CPNB PRO capability (multilingual texts) and are written with the Pro add-on\'s '
			. 'set_cpnb_language_texts. Follow with check_cpnb_frontend.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['texts'],
			'properties'           => [
				'texts'    => ['type' => 'object', 'description' => 'base_name => value'],
				'language' => ['type' => 'string', 'description' => 'Optional; must be the site default language if given.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$default = $this->cpnbDefaultLanguageTag();
		$tag     = str_replace('-', '_', trim((string) ($arguments['language'] ?? '')) ?: $default);

		if ($tag !== $default) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'This tool writes the site\'s default language (' . $default . ') only. Texts for ' . $tag
					. ' are CPNB PRO\'s "multilingual texts" capability; use set_cpnb_language_texts from the Cybersalt '
					. 'Pro add-on for CPNB' . ($this->cpnbIsPro() ? '.' : ', which also requires CPNB PRO itself.'),
			], true);
		}

		$texts = $arguments['texts'] ?? null;

		if (!\is_array($texts) || $texts === []) {
			throw new \InvalidArgumentException('texts must be a non-empty object of base_name => value.');
		}

		$allowed = $this->cpnbLocalizedBaseKeys();
		$changes = [];

		foreach ($texts as $base => $value) {
			$base = (string) $base;

			if (!\in_array($base, $allowed, true)) {
				throw new \InvalidArgumentException('"' . $base . '" is not a CPNB per-language setting. Valid names: ' . implode(', ', $allowed) . '.');
			}

			if (\is_array($value)) {
				throw new \InvalidArgumentException('"' . $base . '" must be a string.');
			}

			$changes[$base . '_' . $tag] = $value;
		}

		$stored = $this->cpnbStoredParams();
		$before = [];

		foreach (array_keys($changes) as $key) {
			$before[$key] = $stored[$key] ?? null;
		}

		try {
			$save = $this->cpnbSave($changes);
		} catch (CpnbProRequiredException $e) {
			return $this->cpnbProRequiredError($e->capability);
		}

		$after = $this->cpnbStoredParams();
		$diff  = [];

		foreach ($changes as $key => $value) {
			$diff[$key] = ['before' => $before[$key], 'after' => $after[$key] ?? null];
		}

		return ToolResult::json([
			'ok'       => true,
			'language' => $tag,
			'changes'  => $diff,
			'save'     => $save,
			'next'     => 'check_cpnb_frontend shows the rendered message and buttons.',
		]);
	}
}
