<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config;

\defined('_JEXEC') or die;

/**
 * CB's configuration schema, read from CB's OWN configuration form.
 *
 * CB has no config.xml in the Joomla sense. Its Configuration screen is an
 * AhaWow XML view, `administrator/components/com_comprofiler/xmlcb/views/
 * view.com_comprofiler.editconfig.xml`, whose `<param>` elements name every
 * key, its type, its default and (for lists) its options; `xml:cfg_*` types are
 * defined in `xmlcb/models/model.config.xml`. Parsing the installed copy keeps
 * the validation in step with whatever CB version the site runs.
 */
trait CbConfigSchemaTrait
{
	/** Param types that hold no stored value, or are rendered/managed by CB itself. */
	private const CB_UNWRITABLE_PARAM_TYPES = ['custom', 'group', 'icon', 'private', 'httprequest', 'hidden', 'spacer'];

	private static ?array $cbConfigSchemaCache = null;

	/**
	 * @return array<string,array{type:string,default:?string,label:string,tab:string,options:?array}>
	 */
	protected function cbConfigSchema(): array
	{
		if (self::$cbConfigSchemaCache !== null) {
			return self::$cbConfigSchemaCache;
		}

		$base   = JPATH_ADMINISTRATOR . '/components/com_comprofiler/xmlcb';
		$view   = $base . '/views/view.com_comprofiler.editconfig.xml';
		$models = [$base . '/models/model.config.xml', $base . '/models/model.lists.xml'];

		if (!is_file($view)) {
			return self::$cbConfigSchemaCache = [];
		}

		$types = [];

		foreach ($models as $file) {
			if (!is_file($file)) {
				continue;
			}

			$xml = @simplexml_load_file($file);

			if ($xml === false) {
				continue;
			}

			foreach ($xml->xpath('//types/type') ?: [] as $type) {
				$values = [];

				foreach ($type->xpath('.//option') ?: [] as $opt) {
					$values[] = (string) $opt['value'];
				}

				$types[(string) $type['name']] = $values;
			}
		}

		$xml = @simplexml_load_file($view);

		if ($xml === false) {
			return self::$cbConfigSchemaCache = [];
		}

		$out = [];

		foreach ($xml->xpath('//param[@name!=""]') ?: [] as $param) {
			$name = (string) $param['name'];
			$type = (string) $param['type'];

			if ($name === '' || isset($out[$name])) {
				continue;
			}

			$tabNode = $param->xpath('ancestor::tabpanetab[1]');
			$options = null;

			$inline = $param->xpath('./option|./optgroup/option') ?: [];

			if ($inline !== []) {
				$options = array_map(static fn ($o): string => (string) $o['value'], $inline);
			} elseif (str_starts_with($type, 'xml:')) {
				$options = $types[substr($type, 4)] ?? null;
			} elseif ($type === 'yesno') {
				$options = ['0', '1'];
			}

			// A list whose options come from a <data> query is open-ended here.
			if ($param->xpath('./data') && $inline === []) {
				$options = null;
			}

			$out[$name] = [
				'type'    => $type,
				'default' => isset($param['default']) ? (string) $param['default'] : null,
				'label'   => (string) $param['label'],
				'tab'     => $tabNode ? (string) $tabNode[0]['name'] : '',
				'options' => $options,
			];
		}

		return self::$cbConfigSchemaCache = $out;
	}

	/** True when a schema entry is something a caller may write. */
	protected function cbConfigWritable(array $entry): bool
	{
		return !\in_array($entry['type'], self::CB_UNWRITABLE_PARAM_TYPES, true);
	}

	/**
	 * Validate one value against its schema entry; returns the value to store.
	 *
	 * Stored as a string, because that is what CB's form posts and what the
	 * stored blob overwhelmingly holds.
	 */
	protected function cbConfigValidate(string $key, array $entry, mixed $value): string
	{
		if (\is_bool($value)) {
			$value = $value ? '1' : '0';
		}

		if (\is_array($value) || \is_object($value)) {
			throw new \InvalidArgumentException($key . ' must be a scalar value.');
		}

		$value = (string) $value;

		if ($entry['options'] !== null && $entry['options'] !== [] && !\in_array($value, $entry['options'], true)) {
			throw new \InvalidArgumentException(
				$key . ' = "' . $value . '" is not one of the values CB\'s configuration form offers: '
				. implode(', ', $entry['options']) . '.'
			);
		}

		if ($entry['type'] === 'viewaccesslevel') {
			$exists = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__viewlevels') . ' WHERE id = ' . (int) $value
			)->loadResult();

			if (!ctype_digit($value) || $exists === 0) {
				throw new \InvalidArgumentException($key . ' must be the id of an existing Joomla view access level.');
			}
		}

		if ($entry['type'] === 'field') {
			$exists = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__comprofiler_fields') . ' WHERE name = ' . $this->db->quote($value)
			)->loadResult();

			if ($exists === 0 && !\in_array($value, $entry['options'] ?? [], true) && $value !== 'random') {
				throw new \InvalidArgumentException($key . ' must name an existing CB field.');
			}
		}

		return $value;
	}

	/** Closest schema keys to a mistyped one. */
	protected function cbConfigSuggest(string $key): array
	{
		$scores = [];

		foreach (array_keys($this->cbConfigSchema()) as $candidate) {
			$scores[$candidate] = levenshtein(strtolower($key), strtolower($candidate));
		}

		asort($scores);

		return \array_slice(array_keys(array_filter($scores, static fn (int $d): bool => $d <= 4)), 0, 3);
	}
}
