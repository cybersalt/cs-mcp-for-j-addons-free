<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * List rows in `#__pagebuilderck_fonts`. READ ONLY, and deliberately so.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO FONT WRITE TOOL
 * ---------------------------------------------------------------------------
 *
 * Page Builder CK's font handling is the attack surface of CVE-2026-56290: an
 * UNAUTHENTICATED ARBITRARY FILE UPLOAD leading to remote code execution, scored
 * CVSS 10.0, listed on CISA's Known Exploited Vulnerabilities catalogue, with
 * public mass-exploitation tooling in circulation. It is not a theoretical bug
 * and it is not a historical one — it is being used.
 *
 * We are not building write tooling on that code path. Not a font installer, not
 * a font uploader, not a "just update the URL" setter, not a wrapper that calls
 * the vendor's own save method. The reasoning is simple: a write tool here would
 * be a second, automatable entry point into the exact machinery that is being
 * actively exploited, and no convenience it could offer is worth that. A human
 * who needs to add a font can do it in the builder, on a patched install, having
 * thought about it.
 *
 * Reading the table is safe and is genuinely useful — it tells you what a site
 * has, and a populated fonts table on an unpatched install is a strong hint that
 * the upload path has been exercised. That is worth being able to see.
 *
 * `local` distinguishes a font whose files were uploaded into
 * `administrator/components/com_pagebuilderck/fonts/` (helpers/stylescss.php:1728)
 * from one merely referenced by URL. Local fonts are the ones that came through
 * the upload path, so they are called out explicitly.
 */
final class ListFontsTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	/**
	 * Below this, the known actively-exploited font RCEs are unpatched.
	 *
	 * The Light package this add-on was verified against is 3.6.5.
	 */
	private const FONT_RCE_FIXED_IN = '3.6.5';

	public function getName(): string { return 'list_pagebuilderck_fonts'; }

	public function getDescription(): string
	{
		return 'List fonts registered in #__pagebuilderck_fonts. READ ONLY. '
			. 'THERE IS DELIBERATELY NO FONT WRITE TOOL IN THIS ADD-ON, AND THERE WILL NOT BE ONE. '
			. 'Page Builder CK\'s font handling is the attack surface of CVE-2026-56290 — an '
			. 'unauthenticated arbitrary file upload leading to remote code execution, CVSS 10.0, listed '
			. 'on CISA\'s Known Exploited Vulnerabilities catalogue, with public mass-exploitation '
			. 'tooling in circulation. Shipping an automatable write path into that machinery would add '
			. 'a second entry point to a vulnerability that is being actively used against real sites, '
			. 'so this add-on provides no font installer, no font uploader, no URL setter and no wrapper '
			. 'around the vendor\'s own font save. Add fonts by hand, in the builder, on a patched '
			. 'install. '
			. 'Returns for each row: id, name, url, variants, filesize, state, and `local` — whether the '
			. 'font\'s files were uploaded into administrator/components/com_pagebuilderck/fonts/ '
			. '(helpers/stylescss.php:1728) rather than merely referenced by URL. Local fonts are the '
			. 'ones that arrived through the upload path, so they are flagged. '
			. 'Also reports whether the installed version predates ' . self::FONT_RCE_FIXED_IN . ', and '
			. 'lists the font directories actually present on disk so they can be reconciled against the '
			. 'table — an entry on disk with no row, or a row with no entry on disk, is worth a look on '
			. 'any install that was ever unpatched. '
			. 'Filters: search (substring of name), local (true/false), state, limit (default 50, max '
			. '200) and offset.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string', 'description' => 'Case-insensitive substring match on the font name.'],
				'local'  => ['type' => 'boolean', 'description' => 'True for locally hosted fonts only, false for URL-referenced only. Omit for both.'],
				'state'  => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer. Omit for any state.'],
				'limit'  => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset' => ['type' => 'integer', 'description' => 'Rows to skip.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->pbckAdminBase();

		if ($base === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('fonts')) {
			return $this->pbckMissingTableError('fonts');
		}

		$table  = $this->db->quoteName($this->pbckTable('fonts'));
		$limit  = $this->pbckLimit($arguments);
		$offset = $this->pbckOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('name') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		if (\array_key_exists('local', $arguments) && $arguments['local'] !== null) {
			// `local` is an int(11), not a tinyint flag, so anything non-zero
			// counts as local rather than testing for a literal 1.
			$where[] = $arguments['local']
				? $this->db->quoteName('local') . ' <> 0'
				: $this->db->quoteName('local') . ' = 0';
		}

		$state = $this->pbckNormaliseState($arguments['state'] ?? null);

		if ($state !== null) {
			$where[] = $this->db->quoteName('state') . ' = ' . $state;
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql . ' ORDER BY ' . $this->db->quoteName('name') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$fonts      = [];
		$localCount = 0;

		foreach ($rows as $row) {
			$isLocal = (int) ($row['local'] ?? 0) !== 0;

			if ($isLocal) {
				$localCount++;
			}

			$variants = trim((string) ($row['variants'] ?? ''));

			$entry = [
				'id'       => (int) $row['id'],
				'name'     => (string) ($row['name'] ?? ''),
				'url'      => (string) ($row['url'] ?? ''),
				'variants' => $variants === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $variants)))),
				'filesize' => (int) ($row['filesize'] ?? 0),
				'local'    => $isLocal,
				'state'    => $this->pbckStateLabel((int) ($row['state'] ?? 0)),
			];

			if ($isLocal) {
				$entry['local_note'] = 'This font is hosted locally, which means its files were placed '
					. 'under administrator/components/com_pagebuilderck/fonts/ through Page Builder CK\'s '
					. 'font upload. That is the CVE-2026-56290 code path. On an install that has ever run '
					. 'a version below ' . self::FONT_RCE_FIXED_IN . ', the contents of that directory are '
					. 'worth reviewing by hand.';
			}

			$fonts[] = $entry;
		}

		$response = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($fonts),
			'local_fonts_in_page' => $localCount,
			'fonts'   => $fonts,
			'read_only' => true,
			'no_write_tool' => 'This add-on provides NO tool that writes, uploads, renames or deletes a '
				. 'Page Builder CK font, by design. Font handling is the attack surface of '
				. 'CVE-2026-56290 — an unauthenticated arbitrary file upload leading to RCE, CVSS 10.0, '
				. 'on CISA\'s Known Exploited Vulnerabilities list with public mass-exploitation tooling. '
				. 'We do not build automatable write tooling on an actively exploited path. Use the '
				. 'builder, on a patched install.',
			'component' => $this->pbckEditionNotice(),
		];

		$response['font_directory'] = $this->fontDirectoryReport($base);

		$version = $this->pbckVersion();

		if ($version !== null && version_compare($version, self::FONT_RCE_FIXED_IN, '<')) {
			$response['security_warning'] = sprintf(
				'This site runs Page Builder CK %s, which is below %s and therefore carries the known, '
					. 'actively exploited font upload RCE (CVE-2026-56290, CVSS 10.0, CISA KEV). Update '
					. 'before anything else, and treat the site as potentially already compromised — this '
					. 'vulnerability is unauthenticated and there is public mass-exploitation tooling for '
					. 'it. Audit administrator/components/com_pagebuilderck/fonts/ for files that are not '
					. 'fonts.',
				$version,
				self::FONT_RCE_FIXED_IN
			);
		}

		return ToolResult::json($response);
	}

	/**
	 * What is actually on disk under the local font root.
	 *
	 * Reported as a plain directory listing rather than matched against the table
	 * rows: the vendor derives the on-disk directory name from the font name at
	 * upload time, and we are not going to guess that mapping and then assert a
	 * reconciliation we cannot prove. A caller can compare the two lists.
	 *
	 * @return array<string,mixed>
	 */
	private function fontDirectoryReport(string $adminBase): array
	{
		$path = $adminBase . '/fonts';

		if (!is_dir($path)) {
			return [
				'path'   => 'administrator/components/com_pagebuilderck/fonts',
				'exists' => false,
				'note'   => 'The local font directory does not exist, so no font has ever been uploaded '
					. 'to this install through the builder.',
			];
		}

		$entries = @scandir($path);

		if ($entries === false) {
			return [
				'path'   => 'administrator/components/com_pagebuilderck/fonts',
				'exists' => true,
				'note'   => 'The directory exists but could not be read.',
			];
		}

		$dirs  = [];
		$files = [];

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === 'index.html') {
				continue;
			}

			if (is_dir($path . '/' . $entry)) {
				$dirs[] = $entry;
			} else {
				$files[] = $entry;
			}
		}

		$report = [
			'path'        => 'administrator/components/com_pagebuilderck/fonts',
			'exists'      => true,
			'directories' => $dirs,
		];

		// A loose file directly in the font root is not how the vendor stores an
		// uploaded font, and on the CVE path it is exactly what a dropped payload
		// looks like. Say so; do not diagnose it.
		if ($files !== []) {
			$report['loose_files'] = $files;
			$report['loose_files_warning'] = 'There are files sitting directly in the font root rather '
				. 'than inside a per-font directory. Page Builder CK does not store uploaded fonts that '
				. 'way. Given that this directory is the CVE-2026-56290 upload target, inspect these by '
				. 'hand before assuming they are harmless.';
		}

		return $report;
	}
}
