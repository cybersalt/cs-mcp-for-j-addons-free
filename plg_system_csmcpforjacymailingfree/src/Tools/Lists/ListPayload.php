<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists;

\defined('_JEXEC') or die;

/**
 * Shared argument schema + validation for create_acym_list / update_acym_list.
 */
final class ListPayload
{
	public static function schema(bool $withId = false): array
	{
		$s = [
			'name'         => ['type' => 'string', 'maxLength' => 255],
			'display_name' => ['type' => 'string', 'maxLength' => 255],
			'description'  => ['type' => 'string'],
			'color'        => ['type' => 'string', 'description' => 'Hex colour, e.g. #3366ff.'],
			'active'       => ['type' => 'integer', 'enum' => [0, 1]],
			'visible'      => ['type' => 'integer', 'enum' => [0, 1]],
			'tracking'     => ['type' => 'integer', 'enum' => [0, 1]],
			'access'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Joomla group ids that see the list on the front end; [] = everyone.'],
		];

		return $withId ? ['id' => ['type' => 'integer', 'minimum' => 1]] + $s : $s;
	}

	/**
	 * @return array<string,mixed>|string payload, or an error message
	 */
	public static function build(array $a): array|string
	{
		$out = [];
		foreach (['name', 'display_name', 'description'] as $k) {
			if (array_key_exists($k, $a)) {
				$out[$k] = (string) $a[$k];
			}
		}
		if (array_key_exists('color', $a)) {
			$c = trim((string) $a['color']);
			if ($c !== '' && !preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $c)) {
				return 'color must be a hex colour like #3366ff.';
			}
			$out['color'] = $c;
		}
		foreach (['active', 'visible', 'tracking'] as $k) {
			if (array_key_exists($k, $a)) {
				$out[$k] = (int) $a[$k] ? 1 : 0;
			}
		}
		if (array_key_exists('access', $a)) {
			$ids = array_values(array_filter(array_map('intval', (array) $a['access']), static fn (int $v): bool => $v > 0));
			// Same storage shape the vendor's Lists edit controller writes: ',1,2,'.
			$out['access'] = $ids === [] ? '' : ',' . implode(',', $ids) . ',';
		}

		return $out;
	}
}
