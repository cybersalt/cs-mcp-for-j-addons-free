<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class CreateBannerTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use BannerSupportTrait;

	public function getName(): string { return 'create_flexbanner'; }

	public function getDescription(): string
	{
		return 'Create a FlexBanners banner. Required: name, location_id, size_id, and for an image banner '
			. 'image_url (relative to the site root, file must exist) plus link_url. Creates the click-through link '
			. 'row first (FlexBanners stores the URL in #__flexbannerslink and the banner carries linkid). '
			. 'catid defaults to the first public com_flexbanners category and client_id to the first client. '
			. 'Validated against what mod_flexbanners actually requires to show it (size row and category must '
			. 'exist; language must be "*" or the site default), and the response says whether the banner will '
			. 'display and which modules will show it. Written as a targeted INSERT, not through FlexBanners\' '
			. 'save(), which creates stray categories and mis-keys restrictions when called outside its admin form.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => $this->bannerSchemaProps(),
			'required'             => ['name', 'location_id', 'size_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.create');

		$notes = [];

		if (!\array_key_exists('catid', $arguments)) {
			$catid = $this->fbDefaultCategoryId();

			if ($catid === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'No published, public com_flexbanners category exists, and every banner needs a category '
						. '(the module INNER JOINs on it). Create one with create_category {"title": "Uncategorised", '
						. '"extension": "com_flexbanners"}, or pass catid explicitly.',
				], true);
			}

			$arguments['catid'] = $catid;
			$notes[]            = 'catid defaulted to ' . $catid . '.';
		}

		if (!\array_key_exists('client_id', $arguments)) {
			$client = (int) $this->db->setQuery(
				'SELECT clientid FROM ' . $this->db->quoteName($this->fbTable('client')) . ' WHERE state = 1 ORDER BY clientid',
				0,
				1
			)->loadResult();
			$arguments['client_id'] = $client;
			$notes[] = $client > 0
				? 'client_id defaulted to ' . $client . '.'
				: 'No client exists, so client_id is 0. The banner displays, but the FlexBanners admin form requires a client on its next save; create one with create_flexbanners_client.';
		}

		$arguments += ['type' => 'image', 'language' => '*', 'state' => 1, 'new_window' => false];

		if (!\array_key_exists('link_url', $arguments) && !\array_key_exists('link_id', $arguments) && $arguments['type'] !== 'custom_code') {
			throw new \InvalidArgumentException('link_url (or link_id) is required: it is where a click on the banner goes.');
		}

		[$cols, $warnings] = $this->bannerColumns($arguments, null, $actor);

		$db  = $this->db;
		$now = $this->fbNow();

		$db->transactionStart();

		try {
			[$linkid, $linkNotes] = $this->bannerResolveLink($arguments, null, (string) $cols['name'], (int) ($cols['clientid'] ?? 0));
			$notes = array_merge($notes, $linkNotes);

			$alias = $this->fbUniqueAlias(
				trim((string) ($arguments['alias'] ?? '')) !== '' ? (string) $arguments['alias'] : (string) $cols['name'],
				(int) $cols['catid'],
				(string) $cols['language']
			);

			$next = (int) $db->setQuery(
				'SELECT COALESCE(MAX(ordering), 0) + 1 FROM ' . $db->quoteName($this->fbTable('')) . ' WHERE catid = ' . (int) $cols['catid'] . ' AND state >= 0'
			)->loadResult();

			$row = (object) array_merge([
				'clientid'         => 0,
				'linkid'           => (int) ($linkid ?? 0),
				'imageurl'         => '',
				'flash'            => '',
				'cloud_imageurl'   => '',
				'imagealt'         => '',
				'customcode'       => '',
				'restrictbyid'     => 0,
				'frontpage'        => 0,
				'clicks'           => 0,
				'impmade'          => 0,
				'maximpressions'   => 0,
				'maxclicks'        => 0,
				'dailyimpressions' => 0,
				'finished'         => 0,
				'ordering'         => $next,
				'created'          => $now,
				'created_by'       => (int) $actor->id,
				'created_by_alias' => '',
				'modified'         => $now,
				'modified_by'      => (int) $actor->id,
				'version'          => 1,
				'params'           => '{}',
			], $cols, ['alias' => $alias]);

			$db->insertObject($this->fbTable(''), $row, 'id');
			$id = (int) $row->id;

			[$cats, $arts] = $this->bannerRestrictionArgs($arguments);
			$restrictions  = $this->bannerWriteRestrictions($id, $cats, $arts);

			$db->transactionCommit();
		} catch (\Throwable $e) {
			$db->transactionRollback();

			throw $e;
		}

		$saved = $this->fbBanner($id);

		if ($saved === null) {
			throw new \RuntimeException('The INSERT reported id ' . $id . ' but no such banner can be read back.');
		}

		$response = ['ok' => true, 'created' => true] + $this->shapeBanner($saved);

		if ($restrictions !== null) {
			$response['restrictions_written'] = $restrictions;
		}

		if ($notes !== []) {
			$response['notes'] = $notes;
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}
}
