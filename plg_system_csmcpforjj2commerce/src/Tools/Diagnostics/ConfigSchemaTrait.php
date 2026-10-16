<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

/**
 * The installed `config.xml`, parsed into a settings schema.
 *
 * Read at runtime rather than hardcoded: 6.6.5 declares 174 fields across 15
 * fieldsets and the set moves between releases. Anything a tool reports or
 * accepts is checked against what THIS site's J2Commerce actually defines.
 */
trait ConfigSchemaTrait
{
	/** Field types that carry no setting. */
	private const NON_SETTING_TYPES = ['spacer', 'note', 'rules', 'subform'];

	/**
	 * Fields that are bookkeeping rather than settings: hidden markers the
	 * vendor declares only so a com_config save does not wipe them
	 * (`config.xml:646-660`), plus the vendor's own runtime timestamps.
	 */
	private const BOOKKEEPING_TYPES = ['hidden', 'cronlasthit'];

	/** Field types whose <option> children are the complete set of legal values. */
	private const STATIC_OPTION_TYPES = ['list', 'radio'];

	/** Keys no tool here will write. Secrets, and timestamps owned by vendor code. */
	private const WRITE_REFUSED_KEYS = [
		'queue_key'          => 'It is the cron endpoint secret AND the HMAC key for payment return hashes (src/Library/Plugins/Payment.php:204-218). Changing it invalidates every payment currently in flight. Regenerate it from the J2Commerce Options screen if it must change.',
		'downloadid'         => 'It is the merchant\'s J2Commerce licence for paid-app updates. Enter it in J2Commerce -> Options -> Updates.',
		'cron_last_trigger'  => 'It is a runtime timestamp written by J2Commerce\'s own cron handler.',
		'attachmentfolderpath' => 'Moving the attachment folder requires J2Commerce\'s relocation routine (attachment_relocate), which moves the files; changing the setting alone strands every existing attachment.',
	];

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $configSchemaCache = null;

	/**
	 * @return array<string,array{fieldset:string,type:string,default:?string,options:string[],label:string,multiple:bool}>
	 */
	protected function configSchema(): array
	{
		if (self::$configSchemaCache !== null) {
			return self::$configSchemaCache;
		}

		$file = JPATH_ADMINISTRATOR . '/components/com_j2commerce/config.xml';
		$out  = [];

		if (is_file($file)) {
			$previous = libxml_use_internal_errors(true);
			$xml      = simplexml_load_file($file);
			libxml_use_internal_errors($previous);

			if ($xml !== false) {
				foreach ($xml->xpath('//fieldset') ?: [] as $fieldset) {
					$set = (string) $fieldset['name'];

					foreach ($fieldset->xpath('.//field') ?: [] as $field) {
						$name = (string) $field['name'];

						// Fields nested inside a subform belong to the subform, not to params.
						if ($name === '' || \count($field->xpath('ancestor::field')) > 0) {
							continue;
						}

						$options = [];

						foreach ($field->option ?? [] as $option) {
							$options[] = (string) $option['value'];
						}

						$out[$name] = [
							'fieldset' => $set,
							'type'     => strtolower((string) $field['type']),
							'default'  => isset($field['default']) ? (string) $field['default'] : null,
							'options'  => $options,
							'label'    => (string) $field['label'],
							'multiple' => strtolower((string) $field['multiple']) === 'true',
						];
					}
				}
			}
		}

		self::$configSchemaCache = $out;

		return $out;
	}

	protected function isSettingField(array $def): bool
	{
		return !\in_array($def['type'], self::NON_SETTING_TYPES, true)
			&& !\in_array($def['type'], self::BOOKKEEPING_TYPES, true);
	}
}
