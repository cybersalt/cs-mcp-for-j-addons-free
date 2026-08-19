<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class ListFieldTypesTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'list_convertforms_field_types'; }

	public function getDescription(): string
	{
		return 'List every Convert Forms field type this install can actually use, '
			. 'with its per-type options read from the vendor XML, plus the types '
			. 'that are registered but locked (Pro-only on a free install). Call '
			. 'this before add_convertforms_form_field so you pick a type that '
			. 'exists and know which extra properties it accepts.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'type' => [
					'type'        => 'string',
					'description' => 'Return the full option list for just this one type.',
				],
				'include_options' => [
					'type'        => 'boolean',
					'description' => 'Include per-type option names for every type. Default false (verbose).',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		$available = $this->cfAvailableFieldTypes();
		$registry  = $this->cfFieldTypeRegistry();
		$noInput   = $this->cfNoInputFieldTypes();

		// Single-type deep dive.
		if (!empty($arguments['type'])) {
			$type = strtolower(trim((string) $arguments['type']));

			if (!in_array($type, $available, true)) {
				$registered = [];
				foreach ($registry as $types) {
					$registered = array_merge($registered, $types);
				}

				return ToolResult::error(
					'Field type "' . $type . '" is not usable on this install. '
					. (in_array($type, $registered, true)
						? 'It is registered but its PHP class is absent — that means it is a Pro-only type and this is the free edition.'
						: 'It is not a recognised Convert Forms field type.')
					. ' Usable types: ' . implode(', ', $available)
				);
			}

			return ToolResult::json([
				'ok'   => true,
				'type' => $type,
				'group' => $this->groupOf($type, $registry),
				'accepts_input' => !in_array($type, $noInput, true),
				'common_options' => $this->optionNames('field'),
				'type_options'   => $this->optionNames('field/' . $type),
			]);
		}

		$withOptions = !empty($arguments['include_options']);

		$usable = [];
		foreach ($available as $type) {
			$entry = [
				'type'          => $type,
				'group'         => $this->groupOf($type, $registry),
				'accepts_input' => !in_array($type, $noInput, true),
			];
			if ($withOptions) {
				$entry['type_options'] = $this->optionNames('field/' . $type);
			}
			$usable[] = $entry;
		}

		$registered = [];
		foreach ($registry as $types) {
			$registered = array_merge($registered, $types);
		}
		$locked = array_values(array_diff(array_unique($registered), $available));
		sort($locked);

		return ToolResult::json([
			'ok'             => true,
			'edition'        => $this->cfIsPro() ? 'pro' : 'free',
			'count'          => count($usable),
			'usable'         => $usable,
			'locked'         => $locked,
			'common_options' => $this->optionNames('field'),
			'notes'          => [
				'accepts_input=false types (submit, html, heading, divider, spacers, '
					. 'captchas) carry no submitted value and need no "name".',
				'locked types are shown with a padlock in the form builder and cannot '
					. 'be added on this install.',
			],
		]);
	}

	/** Which builder group a type belongs to, per the vendor registry. */
	private function groupOf(string $type, array $registry): ?string
	{
		foreach ($registry as $group => $types) {
			if (in_array($type, $types, true)) {
				return (string) $group;
			}
		}

		return null;
	}

	/**
	 * Option names declared in a vendor field XML
	 * (ConvertForms/xml/field.xml for the common set,
	 * ConvertForms/xml/field/<type>.xml for the per-type extras).
	 *
	 * @return array<int, string>
	 */
	private function optionNames(string $relative): array
	{
		$base = $this->cfAdminBase();
		if ($base === null) {
			return [];
		}

		$file = $base . '/ConvertForms/xml/' . $relative . '.xml';
		if (!is_file($file)) {
			return [];
		}

		$xml = @simplexml_load_file($file);
		if ($xml === false) {
			return [];
		}

		$names = [];
		foreach ($xml->xpath('//field[@name]') ?: [] as $field) {
			$name = (string) $field['name'];
			$type = (string) $field['type'];

			// nr_pro fields are inert upsell placeholders, not real options.
			if ($name === '' || strtolower($type) === 'nr_pro') {
				continue;
			}
			$names[$name] = true;
		}

		return array_keys($names);
	}
}
