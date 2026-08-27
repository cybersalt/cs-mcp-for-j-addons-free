<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\User\User;

/**
 * One row from `#__pagebuilderck_styles`, both columns.
 *
 * The two columns are not peers, and treating them as if they were is the main
 * way people damage a style:
 *
 *   htmlcode   The authored source. This is what the style editor shows and
 *              what a human edits.
 *   stylecode  DERIVED. `administrator/models/style.php:88` runs
 *              `$data['stylecode'] = $this->getStylesFromHtml($data['htmlcode'])`
 *              on EVERY save, so whatever is in this column is thrown away and
 *              rebuilt from htmlcode each time. Writing to it directly survives
 *              only until the next save of that style.
 *
 * That asymmetry is why this add-on ships no stylecode setter and never will —
 * it would be a write that looks like it worked and then silently reverts.
 *
 * stylecode is nevertheless the column that matters at render time: the front
 * end injects stylecode, not htmlcode (site/models/page.php:934-936). So a
 * style whose htmlcode looks right but whose stylecode is empty does nothing at
 * all, and that is worth knowing about.
 */
final class GetStyleTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	private const TEXT_LIMIT = 65535;

	private const WARN_AT = 58981; // 90% of 65,535

	public function getName(): string { return 'get_pagebuilderck_style'; }

	public function getDescription(): string
	{
		return 'Get one Page Builder CK style from #__pagebuilderck_styles by id, including both its htmlcode '
			. 'and its stylecode. '
			. 'The two are NOT independent. htmlcode is the authored source. stylecode is DERIVED from it: the '
			. 'vendor\'s save path runs getStylesFromHtml($data[\'htmlcode\']) into $data[\'stylecode\'] on every '
			. 'single save (administrator/models/style.php:88), harvesting the <style> tags found inside '
			. '.ckstyle, .ckstyleresponsive and .ckcolumnwidth wrappers. Anything written straight into '
			. 'stylecode therefore lasts only until the next save of that style, which is why this add-on '
			. 'deliberately provides no stylecode setter. To change what a style does, change htmlcode. '
			. 'stylecode is nonetheless the column the front end injects (site/models/page.php:934-936), so a '
			. 'style with content in htmlcode and nothing in stylecode is inert. '
			. 'Also reports the byte size of both columns against the 65,535-byte limit of their `text` '
			. 'columns, warning from 90% up, because MySQL truncates silently in non-strict mode. '
			. '|URIROOT| tokens are expanded to the live site root on the way out, matching what the vendor '
			. 'models do on read; pass raw: true to see the stored bytes instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'  => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_styles.'],
				'raw' => ['type' => 'boolean', 'description' => 'Return the stored bytes with |URIROOT| left tokenised, rather than expanded to the live site root.'],
			],
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('styles')) {
			return $this->pbckMissingTableError('styles');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->pbckTable('styles')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('No row with id %d in %s.', $id, $this->pbckTable('styles')),
			], true);
		}

		$raw       = (bool) ($arguments['raw'] ?? false);
		$htmlcode  = (string) ($row['htmlcode'] ?? '');
		$stylecode = (string) ($row['stylecode'] ?? '');
		$htmlBytes = \strlen($htmlcode);
		$cssBytes  = \strlen($stylecode);
		$state     = (int) ($row['state'] ?? 0);

		$result = [
			'ok'    => true,
			'style' => [
				'id'        => (int) $row['id'],
				'title'     => (string) ($row['title'] ?? ''),
				'state'     => $this->pbckStateLabel($state),
				'ordering'  => (int) ($row['ordering'] ?? 0),
				'htmlcode'  => $raw ? $htmlcode : $this->pbckExpandRoot($htmlcode),
				'stylecode' => $raw ? $stylecode : $this->pbckExpandRoot($stylecode),
			],
			'sizes' => [
				'htmlcode_bytes'  => $htmlBytes,
				'stylecode_bytes' => $cssBytes,
				'column_limit'    => self::TEXT_LIMIT,
				'note'            => 'Both columns are MySQL `text`, not longtext '
					. '(administrator/sql/install.mysql.utf8.sql:53,55).',
			],
			'stylecode_is_derived' => 'stylecode is regenerated from htmlcode on every save by '
				. 'getStylesFromHtml() (administrator/models/style.php:88). It is not independently '
				. 'authoritative and there is deliberately no tool to set it — such a write would be '
				. 'overwritten the next time anyone saved this style, without any warning. Edit htmlcode '
				. 'instead and let the vendor derive stylecode.',
			'uriroot' => $raw
				? 'Returned as stored: internal URLs are tokenised as |URIROOT|.'
				: '|URIROOT| has been expanded to the live site root for readability. The stored value keeps '
					. 'the token; that is what makes the style survive a domain or subdirectory move.',
		];

		if (($checkedOut = $this->pbckCheckedOutBy($row)) !== null) {
			$result['style']['checked_out_by'] = $checkedOut;
			$result['checked_out'] = sprintf(
				'This style is checked out to user %d. Page Builder CK never releases a checkout '
					. 'automatically, so a stale one has to be cleared by hand before the style can be edited '
					. 'in its own editor.',
				$checkedOut
			);
		}

		$warnings = [];

		foreach (['htmlcode' => $htmlBytes, 'stylecode' => $cssBytes] as $column => $bytes) {
			if ($bytes < self::WARN_AT) {
				continue;
			}

			$warnings[] = sprintf(
				'%s is %d bytes, %s%% of the %d-byte limit of its `text` column. MySQL truncates silently in '
					. 'non-strict mode, so any further growth risks cutting this stylesheet off mid-rule with '
					. 'no error in any log. Split it across two style rows.',
				$column,
				$bytes,
				number_format($bytes / self::TEXT_LIMIT * 100, 1),
				self::TEXT_LIMIT
			);
		}

		if ($htmlBytes > 0 && $cssBytes === 0) {
			$warnings[] = 'htmlcode has content but stylecode is empty, so this style injects nothing on the '
				. 'front end — it is inert. getStylesFromHtml() only harvests <style> tags wrapped in '
				. '.ckstyle, .ckstyleresponsive or .ckcolumnwidth; CSS written anywhere else in htmlcode is '
				. 'ignored. Re-saving the style in the vendor\'s own editor rebuilds stylecode.';
		}

		if ($state === -2) {
			$warnings[] = 'This style is trashed. loadStyles() filters on `state > -1` '
				. '(site/models/page.php:931), so a page that still lists this id in its data-styles will '
				. 'simply not get its CSS — with no error and nothing in the log.';
		} elseif ($state !== 1) {
			$warnings[] = sprintf(
				'This style is %s, but that does NOT switch it off. loadStyles() filters on `state > -1` '
					. '(site/models/page.php:931), so any state except trashed still renders on every page '
					. 'that lists it. Trash it, or remove its id from those pages, to actually stop it.',
				$this->pbckStateLabel($state)
			);
		}

		if (str_contains($stylecode, '.PBCKID')) {
			$result['pbckid_placeholder'] = '.PBCKID in stylecode is a placeholder. The front-end model '
				. 'rewrites it to the per-render wrapper class it generates for the page '
				. '(site/models/page.php:120 and :935), which is how one style row can be scoped to just the '
				. 'page that opted into it. Leave the literal text in place.';
		}

		if ($warnings !== []) {
			$result['warnings'] = $warnings;
		}

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}
}
