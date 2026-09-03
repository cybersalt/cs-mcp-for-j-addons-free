<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Show a product's rows across every language satellite table, side by side.
 *
 * The language tables are the least visible part of VirtueMart and the most
 * consequential. Their column set is generated at runtime by
 * `helpers/tableupdater.php:95-201` — typed by substring match on the field
 * name, sized from config keys — so this tool reports the LIVE columns rather
 * than an assumed list.
 */
final class GetProductTranslationTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_product_translation'; }

	public function getDescription(): string
	{
		return 'Show one product\'s rows across every active VirtueMart language, side by side, plus the '
			. 'live column definition of each language satellite table. Requires id. '
			. 'These tables are where a VirtueMart product actually lives. #__virtuemart_products has no '
			. 'product_name column; name, short and long description, metadesc, metakey, customtitle and '
			. 'slug are all in #__virtuemart_products_<langsuffix>, where the suffix is the language tag '
			. 'lowercased with the hyphen turned into an underscore (en-GB becomes en_gb). '
			. 'A missing row here is not "untranslated" — it is INVISIBLE. VirtueMart joins the two tables '
			. 'with an INNER JOIN (helpers/vmtable.php:1065-1068), and on a single-language shop there is '
			. 'not even a fallback pass, because the fallback at :1184 requires langCount > 1. The product '
			. 'simply does not exist for shoppers on that language, silently. '
			. 'The column set is reported from the live schema rather than assumed, because it is '
			. 'GENERATED at install time by helpers/tableupdater.php:95-201: field types are chosen by '
			. 'substring match on the field name (anything containing "name" becomes varchar(dbnamesize, '
			. 'default 400), anything containing "desc" becomes text, and so on) and the widths come from '
			. 'configuration keys a shop can change. Never hardcode them. '
			. 'Adding a Joomla content language does NOT create these tables. The language has to be in '
			. 'VirtueMart\'s own active_languages key, and models/config.php:541-546 only creates tables '
			. 'when that list GROWS — removing a language leaves its table behind with stale content '
			. 'forever.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('products')) {
			return $this->vmMissingTableError('products');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$exists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
		)->loadResult();

		if ($exists === 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . '.',
			], true);
		}

		$tags    = $this->vmActiveLangTags();
		$out     = [];
		$missing = [];

		foreach ($tags as $tag) {
			$suffix = $this->vmLangSuffix($tag);
			$table  = $this->vmLangTable('products', $tag);

			if (!$this->vmLangTableExists('products', $tag)) {
				$out[$tag] = [
					'suffix'       => $suffix,
					'table'        => $table,
					'table_exists' => false,
					'row'          => null,
					'error'        => $tag . ' is in active_languages but its satellite table does not '
						. 'exist. VirtueMart only creates these when the active list grows '
						. '(models/config.php:541-546), so a language added by editing the config blob '
						. 'directly never gets its tables.',
				];
				$missing[] = $tag;

				continue;
			}

			$row = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($table))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
			)->loadAssoc();

			$entry = [
				'suffix'       => $suffix,
				'table'        => $table,
				'table_exists' => true,
				'columns'      => $this->describeColumns($table),
				'row'          => \is_array($row) ? $row : null,
			];

			if (!\is_array($row)) {
				$missing[] = $tag;
				$entry['warning'] = 'No row. This product is invisible to every shopper browsing in '
					. $tag . ' — not hidden, not untranslated, absent. Fix with '
					. 'set_virtuemart_product_translation.';
			} elseif (trim((string) ($row['slug'] ?? '')) === '') {
				$entry['warning'] = 'The row exists but slug is empty, so SEF URLs for this product 404 '
					. 'in ' . $tag . '.';
			}

			$out[$tag] = $entry;
		}

		$response = [
			'ok'                    => true,
			'virtuemart_product_id' => $id,
			'default_language'      => $this->vmDefaultLangTag(),
			'active_languages'      => $tags,
			'translations'          => $out,
		];

		if ($missing !== []) {
			$response['missing_languages'] = $missing;
			$response['warning'] = 'This product has no usable language row for: ' . implode(', ', $missing)
				. '. Because the read join is INNER (helpers/vmtable.php:1065-1068), shoppers on those '
				. 'languages cannot see it at all and nothing anywhere reports the problem.';
		}

		$response['suffix_note'] = 'The suffix is strtolower(strtr($tag, "-", "_")), applied identically '
			. 'in helpers/vmlanguage.php:64 and :156, helpers/tableupdater.php:75 and :206, and '
			. 'helpers/vmtable.php:377-380. It is resolved at runtime here from the shop\'s own '
			. 'active_languages, never hardcoded.';

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * The live column definition, since the language tables are generated.
	 *
	 * @return array<string,string>
	 */
	private function describeColumns(string $table): array
	{
		$columns = $this->db->getTableColumns($table, true) ?: [];
		$out     = [];

		foreach ($columns as $name => $definition) {
			$out[(string) $name] = (string) ($definition->Type ?? '');
		}

		return $out;
	}
}
