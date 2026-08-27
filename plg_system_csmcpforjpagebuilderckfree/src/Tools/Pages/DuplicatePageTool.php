<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Duplicate a row in `#__pagebuilderck_pages`, with fresh element ids.
 *
 * The row-level behaviour matches `CKModel::copy()` (`helpers/ckmodel.php:159`):
 * load the row, set `id = 0`, append " - copy" to the title, store. The content
 * behaviour deliberately does NOT match it, and that difference is the entire
 * point of this tool.
 *
 * `CKModel::copy()` writes `htmlcode` across verbatim. Every block in a Page
 * Builder CK page carries a `#id`-scoped `<style>` block inside `div.ckstyle`,
 * and the tabs and accordion addons build `#id_tabs-N` fragment links off the
 * same ids. Copying the markup byte for byte therefore produces two pages whose
 * blocks share one set of CSS rules and one set of anchor targets. Nothing
 * breaks on the copy alone — the damage shows up when both pages are on screen
 * together (a module rendering one page inside another, a search results view,
 * an article with an embedded page) and edits to the copy silently restyle the
 * original. So this runs `pbckRegenerateIds()` over the content first.
 *
 * Why the taken-id pool is seeded from EVERY page rather than just this one:
 * uniqueness within a page is not the requirement. Page Builder CK ids only have
 * to collide in a single RENDERED DOCUMENT to cross-contaminate, and a rendered
 * document routinely contains more than one page — mod_pagebuilderck renders a
 * page into a module position, the content plugin substitutes one into an
 * article, and the readmore block pulls another in. Minting ids that merely
 * avoid the source page would still let the copy collide with some third page
 * that happens to share a template position with it, which is a bug that
 * surfaces months later and looks like a CSS problem. Scanning the whole table
 * costs one pass over content we are already reading, and removes the class of
 * failure entirely.
 */
final class DuplicatePageTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	/** Rows per batch when harvesting ids, so a big table is not held in memory at once. */
	private const SCAN_BATCH = 25;

	/** `title` is varchar(255). */
	private const TITLE_LIMIT = 255;

	public function getName(): string { return 'duplicate_pagebuilderck_page'; }

	public function getDescription(): string
	{
		return 'Duplicate a Page Builder CK page. The new row copies every column of the source except '
			. 'id, and the title gains a " - copy" suffix, matching Page Builder CK\'s own copy action. '
			. 'Pass `title` to name the copy yourself instead. '
			. 'UNLIKE the vendor\'s copy action, this REGENERATES every editor-generated element id in '
			. 'the duplicated content. Page Builder CK scopes all block CSS to #id and builds tab and '
			. 'accordion anchors from the same ids, so a byte-for-byte content copy leaves two pages '
			. 'sharing one set of style rules — editing the copy then silently restyles the original '
			. 'wherever both appear in the same rendered document (a page rendered into a module '
			. 'position, an article with an embedded page, a readmore block). The new ids are checked '
			. 'against the ids in use across ALL pages in the table, not just the source page, because '
			. 'collision only has to happen within one rendered document to cause the same damage. '
			. 'Hand-written anchors and any id not in the builder\'s ID<epoch>/row_ID<epoch>/'
			. 'block_ID<epoch> format are left alone — rewriting those would break whatever links to them. '
			. 'The copy is created with checked_out empty, created and modified set to now, created_by '
			. 'set to you, and hits reset to 0. Everything else, including state, is inherited from the '
			. 'source: if the source is published, so is the copy, immediately and at the same access '
			. 'level. Set state explicitly afterwards if that is not what you want. '
			. 'The copy is refused if the regenerated content has fatal structural problems, with one '
			. 'documented exception described in the response: a source page that fails the '
			. 'parse/serialise round-trip check is still duplicated, because this operation copies bytes '
			. 'and regenerates ids by string replacement — it never re-serialises, so that particular '
			. 'hazard does not apply to it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'    => ['type' => 'integer', 'description' => 'Id of the page to duplicate. Required.'],
				'title' => ['type' => 'string', 'description' => 'Title for the copy. Defaults to the source title with " - copy" appended, matching the vendor.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$source = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->pbckTable('pages')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($source)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No Page Builder CK page with id ' . $id . '. Nothing was created.',
			], true);
		}

		$title = $this->copyTitle($arguments, (string) $source['title']);

		if ($title instanceof ToolResult) {
			return $title;
		}

		// --- content ---------------------------------------------------------
		$stored = (string) ($source['htmlcode'] ?? '');
		$taken  = $this->allIdsInUse();
		$before = $this->pbckCollectIds($stored);

		// Regeneration is pure string work — pbckCollectIds is a regex and
		// pbckRegenerateIds is a str_replace. The document is never parsed and
		// never re-serialised, which is why a source that fails the round-trip
		// check can still be copied faithfully.
		$htmlcode = $this->pbckRegenerateIds($stored, $taken);

		$after     = $this->pbckCollectIds($htmlcode);
		$unchanged = array_values(array_intersect($before, $after));

		// requireLossless = false for the reason given above: this write path
		// never parses, so the round-trip property does not apply to it. Asking
		// pbckValidate not to raise it is safer than raising it and filtering it
		// back out by matching on the message text.
		$problems = $this->pbckValidate($htmlcode, $this->pbckEnabledAddonTypes(), false);
		$blocking = array_values(array_filter(
			$problems,
			fn (array $p): bool => ($p['severity'] ?? '') === 'fatal'
		));

		if ($blocking !== []) {
			return ToolResult::json([
				'ok'       => false,
				'error'    => 'Refusing to duplicate page ' . $id . ': its content has fatal structural '
					. 'problems, and copying it would put a second broken page on the site rather than '
					. 'one. Nothing was created.',
				'problems' => $blocking,
				'resolution' => 'Fix the source page first — get_pagebuilderck_page reports the same '
					. 'problems against the original.',
			], true);
		}

		// --- the copy --------------------------------------------------------
		$now  = Factory::getDate()->toSql();
		$data = $source;

		unset($data['id']);

		$data['title']       = $title;
		$data['htmlcode']    = $htmlcode;
		$data['created']     = $now;
		$data['modified']    = $now;
		$data['created_by']  = (int) $actor->id;
		$data['hits']        = 0;
		// varchar(10); '' is free. A copy inherits nothing of the source's lock.
		$data['checked_out'] = '';

		$object = new \stdClass();

		foreach ($data as $column => $value) {
			$object->{$column} = $value;
		}

		$this->db->insertObject($this->pbckTable('pages'), $object, 'id');

		$newId = (int) $this->db->insertid();

		if ($newId <= 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The INSERT reported no new id, so the copy cannot be confirmed as created. '
					. 'Check #__pagebuilderck_pages before retrying — retrying blind risks a duplicate '
					. 'duplicate.',
			], true);
		}

		$response = [
			'ok'             => true,
			'id'             => $newId,
			'source_id'      => $id,
			'title'          => $title,
			'state'          => $this->pbckStateLabel((int) $data['state']),
			'access'         => (int) $data['access'],
			'created'        => $now,
			'created_by'     => (int) $actor->id,
			'htmlcode_bytes' => \strlen($htmlcode),
			'ids_rewritten'  => \count($before) - \count($unchanged),
			'ids_in_source'  => \count($before),
			'route'          => 'index.php?option=com_pagebuilderck&view=page&id=' . $newId,
		];

		$response['id_note'] = 'Element ids were regenerated so the copy does not share #id-scoped CSS or '
			. 'tab anchors with page ' . $id . '. Candidate ids were checked against every id in use '
			. 'across all ' . \count($taken) . ' distinct ids found in the whole pages table, not just '
			. 'the source page, because two pages only have to appear in the SAME RENDERED DOCUMENT for '
			. 'a shared id to cross-apply styling.';

		if ($unchanged !== []) {
			$response['ids_left_alone'] = $unchanged;
			$response['ids_left_alone_note'] = 'These ids are not in the builder\'s '
				. 'ID<epoch>/row_ID<epoch>/block_ID<epoch> format, so they are hand-written anchors or '
				. 'markup pasted in from elsewhere. They were left unchanged on purpose — rewriting them '
				. 'would break whatever links to them — but they are now duplicated between page ' . $id
				. ' and page ' . $newId . '. If either page has #id CSS or in-page links riding on them, '
				. 'change them by hand.';
		}

		// Asked directly rather than inferred from the problem list, since we
		// suppressed that check above. The caller still needs telling, because
		// the copy inherits the limitation.
		if (!$this->pbckIsLossless($stored)) {
			$response['round_trip_note'] = 'The source page does not survive a parse/serialise '
				. 'round-trip byte for byte, which is normally a fatal refusal for a content write. It '
				. 'was allowed here because duplication never re-serialises: the content was copied byte '
				. 'for byte and the ids replaced by string substitution, so the copy is exactly as '
				. 'faithful as the original. Be aware that update_pagebuilderck_page will refuse to write '
				. 'content to page ' . $newId . ' for the same reason it would refuse page ' . $id . '.';
		}

		$warnings = array_values(array_filter($problems, static fn (array $p): bool => ($p['severity'] ?? '') === 'warning'));

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
			$response['warnings_note'] = 'Inherited from the source page; they did not block the copy. A '
				. 'block whose addon plugin is disabled still renders — Page Builder CK fails open and '
				. 'emits the inner markup as static HTML with no error — but its interactive behaviour is '
				. 'gone.';
		}

		$response['style_ids'] = $this->pbckGetPageStyleIds($htmlcode);
		$response['style_ids_note'] = 'Style association travels with the content, in '
			. 'div.pagebuilderckparams[data-styles], because there is no styles column on this table. The '
			. 'copy therefore uses the same #__pagebuilderck_styles rows as the source — those are shared, '
			. 'not duplicated, so editing one of those styles still affects both pages.';

		$clobberNotice = $this->pbckEditorClobberNotice(['state', 'access', 'created_by']);

		if ($clobberNotice !== null) {
			$response['editor_clobber'] = $clobberNotice;
		}

		$hazards = $this->pbckNumericHazards([
			'title'      => (string) $data['title'],
			'catid'      => (string) $data['catid'],
			'categories' => (string) $data['categories'],
		]);

		if ($hazards !== []) {
			$response['numeric_hazards'] = $hazards;
			$response['numeric_hazards_note'] = 'This INSERT stored the values as given. The hazard is '
				. 'the next save through Page Builder CK itself, whose CKFof::dbStore() casts '
				. 'numeric-looking strings to int on UPDATE.';
		}

		$response['inheritance_note'] = 'state, access, featured, catid, categories and params were all '
			. 'inherited from page ' . $id . '. The copy is '
			. $this->pbckStateLabel((int) $data['state'])
			. ' right now. Page Builder CK pages have no alias and no router of their own, so the copy is '
			. 'not reachable from the front end until a menu item points at '
			. 'index.php?option=com_pagebuilderck&view=page&id=' . $newId . '.';

		$response['component'] = $this->pbckEditionNotice();

		return ToolResult::json($response);
	}

	/**
	 * Every id in use anywhere in `#__pagebuilderck_pages`.
	 *
	 * Read in batches: a site with a few hundred substantial pages holds tens of
	 * megabytes of `htmlcode`, and there is no reason to have all of it resident
	 * at once when each row is discarded as soon as its ids are extracted.
	 *
	 * @return array<int,string>
	 */
	private function allIdsInUse(): array
	{
		$taken  = [];
		$offset = 0;

		while (true) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('htmlcode'))
					->from($this->db->quoteName($this->pbckTable('pages')))
					->order($this->db->quoteName('id') . ' ASC'),
				$offset,
				self::SCAN_BATCH
			)->loadColumn() ?: [];

			if ($rows === []) {
				break;
			}

			foreach ($rows as $html) {
				foreach ($this->pbckCollectIds((string) $html) as $foundId) {
					$taken[$foundId] = true;
				}
			}

			unset($rows);

			$offset += self::SCAN_BATCH;
		}

		// array_keys() hands back ints for any numeric-string key, and pbckNewId
		// compares strictly — so a hand-written id of "123" would stop being
		// recognised as taken. Force them all back to strings.
		return array_map('strval', array_keys($taken));
	}

	/**
	 * Title for the copy, matching `CKModel::copy()`'s " - copy" suffix.
	 *
	 * The stem is trimmed rather than the whole title refused when the suffix
	 * would overflow varchar(255): losing a few characters of a name is a far
	 * smaller harm than refusing to duplicate a page, and MySQL would truncate at
	 * an arbitrary byte anyway in non-strict mode.
	 *
	 * @return string|ToolResult
	 */
	private function copyTitle(array $arguments, string $sourceTitle): string|ToolResult
	{
		if (\array_key_exists('title', $arguments)) {
			$given = trim((string) $arguments['title']);

			if ($given === '') {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'title was supplied but is empty. Omit it to get the vendor\'s '
						. '" - copy" suffix instead.',
				], true);
			}

			if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $given) === 1) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'title contains a character outside the Basic Multilingual Plane (an emoji '
						. 'or similar). #__pagebuilderck_pages is DEFAULT CHARSET=utf8, MySQL\'s 3-byte '
						. 'utf8mb3, which cannot store it. Refusing; nothing was created.',
				], true);
			}

			if (\strlen($given) > self::TITLE_LIMIT) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'title is ' . \strlen($given) . ' bytes, over the ' . self::TITLE_LIMIT
						. '-byte limit of the column. MySQL truncates silently in non-strict mode. '
						. 'Refusing; nothing was created.',
				], true);
			}

			return $given;
		}

		$suffix = ' - copy';
		$stem   = $sourceTitle;

		if (\strlen($stem) + \strlen($suffix) > self::TITLE_LIMIT) {
			$stem = rtrim(substr($stem, 0, self::TITLE_LIMIT - \strlen($suffix)));
		}

		return $stem . $suffix;
	}

}
