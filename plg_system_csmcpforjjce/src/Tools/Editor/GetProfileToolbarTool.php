<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\User\User;

/**
 * The toolbar layout, decoded and validated.
 *
 * `rows` is a two-level string: `;` separates toolbar rows, `,` separates
 * buttons within a row, and the literal token `spacer` is a group divider that
 * belongs to the row rather than separating rows. `plugins` is a parallel,
 * independent comma list of which editor plugins are enabled. The two are not
 * kept in sync by JCE and disagreeing is not an error anywhere in its code —
 * `getRows()` simply drops tokens it cannot resolve (`models/profile.php:294-301`)
 * and the button vanishes.
 *
 * This tool exists mainly to make that disagreement visible before someone
 * spends an afternoon wondering why a button will not appear.
 */
final class GetProfileToolbarTool extends AbstractTool
{
	use JceBootTrait;

	public function getName(): string { return 'get_jce_profile_toolbar'; }

	public function getDescription(): string
	{
		return 'Return a JCE profile\'s toolbar layout and enabled plugin list, decoded and cross-checked '
			. 'against the installed button catalogue. '
			. 'ENCODING: the `rows` column is `;`-separated toolbar rows, each a `,`-separated list of '
			. 'button tokens, with the literal token `spacer` acting as a group divider WITHIN a row. '
			. 'The response gives the parsed grid, the raw string, and a per-button classification: '
			. 'command (a built-in toolbar action such as bold or undo, from helpers/commands.json), '
			. 'core plugin (helpers/plugins.json), Pro plugin (pro.json), third-party (an installed '
			. 'Joomla plugin in the `jce` group whose element starts with editor_), separator, or '
			. 'UNKNOWN. '
			. 'WHY UNKNOWN MATTERS: JceModelProfile::getRows() silently drops any token it cannot '
			. 'resolve or that is not active. A typo, or a plugin that has since been uninstalled, '
			. 'produces a missing button and no error, no warning and no log entry. This is the only '
			. 'place that will tell you. '
			. 'It also reports the two ways `rows` and `plugins` disagree. A button in `rows` whose '
			. 'plugin is not in `plugins` renders nothing. A plugin in `plugins` with no entry in `rows` '
			. 'is enabled but has no button — which is CORRECT for the row-0 plugins (source, '
			. 'textpattern, contextmenu, browser, media, preview) and a mistake for anything that '
			. 'declares an icon, so the two cases are distinguished rather than lumped together. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		if (!$this->jceTableExists()) {
			return $this->jceMissingTableError();
		}

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jceProfileRow($id);

		if ($row === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$catalog = $this->jcePluginCatalog();
		$grid    = $this->jceParseRows($row['rows'] ?? '');
		$plugins = $this->jceSplitList($row['plugins'] ?? '');

		$decodedRows = [];
		$unknown     = [];
		$notEnabled  = [];

		foreach ($grid as $index => $buttons) {
			$decoded = [];

			foreach ($buttons as $token) {
				$entry = $catalog[$token] ?? null;

				$button = [
					'token' => $token,
					'kind'  => $entry === null ? 'unknown' : (string) $entry['kind'],
					'source' => $entry === null ? null : (string) $entry['source'],
				];

				if ($entry === null) {
					$unknown[] = $token;
					$button['problem'] = 'No command, core plugin, Pro plugin or installed third-party '
						. 'jce plugin has this name. getRows() drops it silently and the button never '
						. 'appears.';
				} elseif ((string) $entry['kind'] === 'plugin' && !\in_array($token, $plugins, true)) {
					$notEnabled[] = $token;
					$button['problem'] = 'This plugin is not listed in the `plugins` column, so the '
						. 'button renders nothing. `rows` and `plugins` are independent — a button needs '
						. 'to be in both.';
				}

				if ($entry !== null && ($entry['source'] ?? '') === 'pro' && !$this->jceIsPro()) {
					$button['inert'] = 'Pro-only plugin on a site without the Pro plugin enabled. It '
						. 'produces no button and no error.';
				}

				$decoded[] = $button;
			}

			$decodedRows[] = [
				'row'     => $index + 1,
				'buttons' => $decoded,
			];
		}

		$noButton = [];

		foreach (array_unique($plugins) as $plugin) {
			$appears = false;

			foreach ($grid as $buttons) {
				if (\in_array($plugin, $buttons, true)) {
					$appears = true;

					break;
				}
			}

			if ($appears) {
				continue;
			}

			$entry   = $catalog[$plugin] ?? null;
			$hasIcon = $entry !== null && trim((string) ($entry['icon'] ?? '')) !== '';

			$noButton[] = [
				'plugin'   => $plugin,
				'known'    => $entry !== null,
				'expected' => $entry !== null && !$hasIcon,
				'note'     => $entry === null
					? 'Unknown plugin name — not in any catalogue and not installed.'
					: ($hasIcon
						? 'This plugin declares a toolbar icon but has no entry in `rows`, so it is '
							. 'enabled with no way to reach it. Probably an oversight.'
						: 'This plugin declares no icon (row 0). Having no entry in `rows` is correct — '
							. 'it works through the context menu, paste handling or the source view.'),
			];
		}

		return ToolResult::json([
			'ok'         => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'published'  => (int) $row['published'] === 1,
			'toolbar'    => [
				'row_count' => \count($grid),
				'rows'      => $decodedRows,
				'grid'      => $grid,
				'raw'       => (string) ($row['rows'] ?? ''),
			],
			'plugins'    => [
				'enabled' => $plugins,
				'count'   => \count($plugins),
				'raw'     => (string) ($row['plugins'] ?? ''),
			],
			'problems'   => [
				'unknown_tokens'        => array_values(array_unique($unknown)),
				'buttons_not_enabled'   => array_values(array_unique($notEnabled)),
				'plugins_without_button' => $noButton,
			],
			'encoding_note' => 'To write this back, use set_jce_profile_toolbar with `rows` as an array '
				. 'of arrays of tokens. Do not hand-assemble the string: JCE sanitises `rows` with '
				. 'preg_replace(\'#[^\\w,;]+#\', \'\', $v) and `plugins` with '
				. 'preg_replace(\'#[^\\w_,]+#\', \'\', $v) on every save (models/profile.php:643-646), '
				. 'which strips spaces, pipes and hyphens without saying so.',
			'component'  => $this->jceEditionNotice(),
		]);
	}
}
