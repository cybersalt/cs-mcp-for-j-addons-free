<?php
/**
 * @package     MCP for Maximenu CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckCssTrait;
use Joomla\CMS\User\User;

/**
 * Write a style's custom CSS the way Maximenu's own editor would leave it.
 *
 * Why this is more than an UPDATE: see MaximenuckCssTrait. In short, the
 * editor stores custom CSS raw in `customcss` AND bakes a scoped copy onto the
 * end of `layoutcss`. Writing only `customcss` leaves the old baked rules live
 * and makes the vendor's own bare-|ID| convention silently inert. This tool
 * replaces the baked suffix exactly when it can identify it, and refuses to
 * write rules it can prove will match nothing.
 */
final class WriteStyleCustomCssTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckCssTrait;

	private const TEXT_LIMIT = 65535;

	public function getName(): string { return 'write_maximenuck_style_custom_css'; }

	public function getDescription(): string
	{
		return 'Writes the custom CSS of a Maximenu CK STYLE (#__maximenuck_styles.customcss) — the CSS that '
			. 'actually renders. (A module\'s own `customcss` param is a hidden v8 field that v9+ never outputs; '
			. 'do not write there.) Find the style with list_maximenuck_modules (the module\'s style_id). '
			. 'HOW |ID| WORKS — read this: Maximenu emits the style as layoutcss + customcss with |ID| replaced by '
			. 'the module\'s bare HTML id (e.g. maximenuck125), and its editor ALSO bakes a copy of the custom '
			. 'CSS into the end of layoutcss with |ID| expanded to "div#|ID|.maximenuckh ul.maximenuck". So a bare '
			. '"|ID| li {...}" works only through the baked copy, and "#|ID| li {...}" works only through the raw '
			. 'copy. This tool keeps the baked copy in step (sync_layoutcss, default true) by replacing exactly the '
			. 'suffix the editor wrote — which is what an editor save would produce — so bare |ID| (the editor\'s '
			. 'own convention, same specificity as Maximenu\'s generated rules) is the recommended form. When the '
			. 'baked copy cannot be identified (custom CSS was previously changed outside the editor), only the raw '
			. 'copy is live: then bare |ID| rules would match nothing, and the write is REFUSED unless you pass '
			. 'rewrite_bare_id: true (turns |ID| into #|ID|). Write the WHOLE custom CSS (mode "replace", '
			. 'default) or add to it (mode "append"). Afterwards it deletes the compiled CSS file of every module '
			. 'using this style whose compile mode would otherwise keep serving stale CSS, and cleans the '
			. 'mod_maximenuck cache group. Also: `.maximenuckv`/`.maximenuckh` in style CSS are rewritten to match '
			. 'each module\'s orientation at render time; the columns are utf8 (3-byte) `text` (65,535 bytes), so '
			. 'emoji and oversize CSS are refused. dry_run: true shows the plan without writing. A page cache or '
			. 'CDN in front of the site still needs clearing separately.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'style_id'           => ['type' => 'integer', 'description' => 'The #__maximenuck_styles id.'],
				'css'                => ['type' => 'string', 'description' => 'The CSS. With mode "replace" this is the complete new custom CSS; with "append" it is added after the existing CSS on a new line.'],
				'mode'               => ['type' => 'string', 'enum' => ['replace', 'append'], 'description' => 'Default "replace".'],
				'sync_layoutcss'     => ['type' => 'boolean', 'description' => 'Keep the baked copy at the end of layoutcss in step (default true).'],
				'rewrite_bare_id'    => ['type' => 'boolean', 'description' => 'Rewrite bare |ID| to #|ID| (default false). Only needed when the baked copy cannot be kept in step.'],
				'purge_compiled_css' => ['type' => 'boolean', 'description' => 'Delete stale compiled CSS files of modules using this style (default true).'],
				'dry_run'            => ['type' => 'boolean', 'description' => 'Report what would happen without writing (default false).'],
			],
			'required'             => ['style_id', 'css'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		if (!$this->mxTableExists('styles')) {
			return $this->mxMissingTableError('styles');
		}

		$id    = $this->requirePositiveInt($arguments, 'style_id');
		$style = $this->mxLoadStyle($id);

		if ($style === null) {
			return ToolResult::json(['ok' => false, 'error' => 'No Maximenu style with id ' . $id . '.'], true);
		}

		if (!\array_key_exists('css', $arguments) || !\is_string($arguments['css'])) {
			return ToolResult::json(['ok' => false, 'error' => 'css is required and must be a string (an empty string clears the custom CSS).'], true);
		}

		$mode    = (string) ($arguments['mode'] ?? 'replace');
		$sync    = !isset($arguments['sync_layoutcss']) || (bool) $arguments['sync_layoutcss'];
		$rewrite = !empty($arguments['rewrite_bare_id']);
		$purge   = !isset($arguments['purge_compiled_css']) || (bool) $arguments['purge_compiled_css'];
		$dryRun  = !empty($arguments['dry_run']);

		if (!\in_array($mode, ['replace', 'append'], true)) {
			return ToolResult::json(['ok' => false, 'error' => 'mode must be "replace" or "append".'], true);
		}

		$oldCustom = (string) $style['customcss'];
		$oldLayout = (string) $style['layoutcss'];
		$newCustom = $mode === 'append'
			? ($oldCustom === '' ? $arguments['css'] : rtrim($oldCustom, "\r\n") . "\n" . $arguments['css'])
			: $arguments['css'];

		$rewritten = 0;

		if ($rewrite) {
			$before    = $this->mxIdTokenUsage($newCustom)['bare'];
			$newCustom = $this->mxRewriteBareId($newCustom);
			$rewritten = $before;
		}

		if ($this->mxHasAstral($newCustom)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The CSS contains a 4-byte character (e.g. an emoji). #__maximenuck_styles is utf8 '
					. '(3-byte), so MySQL would reject it or truncate the CSS at that character. Use a CSS escape '
					. '(e.g. content: "\\1F600") instead.',
			], true);
		}

		if (\strlen($newCustom) > self::TEXT_LIMIT) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('The custom CSS would be %d bytes; the column is `text` (65,535 bytes). MySQL truncates silently in non-strict mode. Refusing.', \strlen($newCustom)),
			], true);
		}

		$plan      = $this->mxPlanCustomCss($oldLayout, $oldCustom, $newCustom, $sync);
		$newLayout = $plan['new_layoutcss'];
		$baked     = $newLayout !== null;
		$eff       = $this->mxEffectiveness($newCustom, $baked);

		if ($baked && \strlen($newLayout) > self::TEXT_LIMIT) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('Keeping the baked copy in step would make layoutcss %d bytes, over its 65,535-byte `text` limit. Shorten the custom CSS, or pass sync_layoutcss: false.', \strlen($newLayout)),
			], true);
		}

		if (!$baked && $eff['id_tokens']['bare'] > 0) {
			return ToolResult::json([
				'ok'         => false,
				'error'      => sprintf(
					'Refusing: the CSS uses bare |ID| %d time(s), and those rules would match NOTHING. Bare |ID| only '
						. 'works through the copy Maximenu bakes into the end of layoutcss, and that copy cannot be kept '
						. 'in step here (sync state: %s%s). Either write "#|ID|" instead of "|ID|", or pass '
						. 'rewrite_bare_id: true to have it done for you.',
					$eff['id_tokens']['bare'],
					$plan['sync_state'],
					$sync ? '' : ', sync_layoutcss is false'
				),
				'sync_state' => $plan['sync_state'],
			], true);
		}

		$modules = $this->mxModulesUsingStyle($id);

		$response = [
			'ok'      => true,
			'dry_run' => $dryRun,
			'style'   => ['id' => $id, 'name' => $style['name'], 'state' => $style['state']],
			'mode'    => $mode,
			'customcss' => ['before_bytes' => \strlen($oldCustom), 'after_bytes' => \strlen($newCustom)],
			'layoutcss' => [
				'before_bytes'     => \strlen($oldLayout),
				'after_bytes'      => \strlen($baked ? $newLayout : $oldLayout),
				'baked_copy'       => $baked ? 'replaced' : 'untouched',
				'sync_state'       => $plan['sync_state'],
				'baked_prefix'     => $plan['prefix_label'],
			],
			'bare_id_rewritten' => $rewritten,
			'effectiveness'     => $eff,
			'modules_using_style' => array_map(fn ($m) => [
				'module_id'    => $m['id'],
				'title'        => $m['title'],
				'menu_html_id' => $this->mxMenuHtmlId($m),
				'published'    => $m['published'],
			], $modules),
		];

		$warnings = [];

		if (!\in_array($style['state'], [0, 1], true)) {
			$warnings[] = 'This style\'s state is ' . $style['state'] . '; Maximenu only emits CSS for state 0 or 1, '
				. 'so nothing written here will render until it is restored.';
		}

		if ($modules === []) {
			$warnings[] = 'No mod_maximenuck module uses this style, so the CSS will not render anywhere yet.';
		}

		if ($plan['sync_state'] === 'out_of_sync') {
			$warnings[] = 'layoutcss ends with a baked copy of OLDER custom CSS (the custom CSS was changed outside '
				. 'the editor before). Those old rules are still applied until someone saves this style in '
				. 'Maximenu\'s editor; this tool cannot identify them safely, so it left layoutcss alone.';
		}

		$checkedOut = trim((string) $style['checked_out']);

		if ($checkedOut !== '' && $checkedOut !== '0') {
			$warnings[] = 'The style is checked out by user ' . $checkedOut . ': if that editor session is still '
				. 'open, its next Save posts its own copy of the custom CSS and overwrites this write.';
		}

		if ($dryRun) {
			$response['would_write'] = ['customcss' => $newCustom];
			$response['warnings']    = $warnings;

			return ToolResult::json($response);
		}

		if ($newCustom === $oldCustom && (!$baked || $newLayout === $oldLayout)) {
			$response['changed']  = false;
			$response['warnings'] = $warnings;

			return ToolResult::json($response);
		}

		// Compare-and-swap: only write if nobody changed the row since we read it.
		$q = $this->db->getQuery(true)
			->update($this->db->quoteName('#__maximenuck_styles'))
			->set($this->db->quoteName('customcss') . ' = ' . $this->db->quote($newCustom))
			->where($this->db->quoteName('id') . ' = ' . $id)
			->where($this->db->quoteName('customcss') . ' = ' . $this->db->quote($oldCustom))
			->where($this->db->quoteName('layoutcss') . ' = ' . $this->db->quote($oldLayout));

		if ($baked) {
			$q->set($this->db->quoteName('layoutcss') . ' = ' . $this->db->quote($newLayout));
		}

		$this->db->setQuery($q)->execute();

		$after = $this->mxLoadStyle($id);

		if ($after === null
			|| (string) $after['customcss'] !== $newCustom
			|| (string) $after['layoutcss'] !== ($baked ? $newLayout : $oldLayout)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The write did not land as intended: the row changed between read and write (someone '
					. 'saved the style in the editor?) or the database altered the value. Nothing was overwritten '
					. 'blindly — re-read with get_maximenuck_style and try again.',
				'customcss_after_bytes' => $after === null ? null : \strlen((string) $after['customcss']),
			], true);
		}

		$response['changed']  = true;
		$response['verified'] = 'Re-read from the database: customcss' . ($baked ? ' and layoutcss' : '') . ' match' . ($baked ? '' : 'es') . ' exactly.';

		$purged = [];

		if ($purge) {
			foreach ($modules as $m) {
				$purged[] = $this->mxPurgeCompiledCss($m);
			}
		}

		$response['compiled_css'] = $purged;
		$response['cache_groups_cleaned'] = $this->mxCleanCacheGroups(['mod_maximenuck']);

		$stale = array_filter($modules, function ($m) {
			$info = $this->mxCompiledCssInfo($m);

			return $info['mode'] === '1' && $info['file_exists'];
		});

		$response['takes_effect'] = $stale === []
			? 'on the next page render (a full-page cache or CDN in front of the site may still serve the old page)'
			: 'NOT YET for module(s) ' . implode(', ', array_map(static fn ($m) => $m['id'], $stale))
				. ' — compile mode "1" with the compiled file still present; run clear_maximenuck_compiled_css.';

		if ($cacheWarning = $this->mxCompiledCacheWarning($modules)) {
			$response['cache_warning'] = $cacheWarning;
		}

		$response['warnings'] = $warnings;

		return ToolResult::json($response);
	}
}
