<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Clients;

\defined('_JEXEC') or die;

/**
 * Advertiser rows (#__flexbannersclient). Needs FlexbannersBootTrait.
 */
trait ClientSupportTrait
{
	protected function clientSchemaProps(): array
	{
		return [
			'name'          => ['type' => 'string', 'description' => 'Advertiser name.'],
			'contact_name'  => ['type' => 'string'],
			'contact_email' => ['type' => 'string'],
			'user_id'       => ['type' => 'integer', 'description' => 'Joomla user who may see this client\'s banner statistics on the front end (com_flexbanners client view). 0 = none.'],
			'barred'        => ['type' => 'boolean', 'description' => 'Admin flag only; the module does not check it.'],
			'published'     => ['type' => 'boolean', 'description' => 'Unpublished clients are hidden from the admin\'s client pickers.'],
		];
	}

	/** @return array<string,mixed> */
	protected function clientColumns(array $args): array
	{
		$cols = [];

		if (\array_key_exists('name', $args)) {
			$name = trim((string) $args['name']);

			if ($name === '') {
				throw new \InvalidArgumentException('name cannot be empty.');
			}

			$this->fbRequireFits('name', $name);
			$cols['name'] = $name;
		}

		if (\array_key_exists('contact_name', $args)) {
			$v = trim((string) $args['contact_name']);
			$this->fbRequireFits('contact_name', $v);
			$cols['contactname'] = $v;
		}

		if (\array_key_exists('contact_email', $args)) {
			$v = trim((string) $args['contact_email']);

			if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
				throw new \InvalidArgumentException('contact_email "' . $v . '" is not an email address.');
			}

			$this->fbRequireFits('contact_email', $v);
			$cols['contactemail'] = $v;
		}

		if (\array_key_exists('user_id', $args)) {
			$uid = (int) $args['user_id'];

			if ($uid > 0 && (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__users') . ' WHERE id = ' . $uid)->loadResult() === 0) {
				throw new \InvalidArgumentException('user_id ' . $uid . ' is not a Joomla user.');
			}

			$cols['juserid'] = $uid;
		}

		if (\array_key_exists('barred', $args)) {
			$cols['barred'] = !empty($args['barred']) ? 1 : 0;
		}

		if (\array_key_exists('published', $args)) {
			$cols['state'] = !empty($args['published']) ? 1 : 0;
		}

		return $cols;
	}

	protected function clientShape(array $row, ?int $banners = null): array
	{
		$out = [
			'id'            => (int) $row['clientid'],
			'name'          => (string) $row['name'],
			'contact_name'  => (string) ($row['contactname'] ?? ''),
			'contact_email' => (string) ($row['contactemail'] ?? ''),
			'user_id'       => (int) ($row['juserid'] ?? 0),
			'barred'        => (int) $row['barred'] === 1,
			'state'         => $this->fbStateLabel((int) $row['state']),
		];

		if ($banners !== null) {
			$out['banners'] = $banners;
		}

		return $out;
	}
}
