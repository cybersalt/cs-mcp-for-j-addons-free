<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class CreateCouponTool extends AbstractTool
{
	use J2commerceBootTrait;
	use CouponSupportTrait;
	use CouponWriteTrait;

	public function getName(): string { return 'create_j2commerce_coupon'; }

	public function getDescription(): string
	{
		return 'Create a J2Commerce coupon. Required: code, value and value_type. value_type is one of '
			. 'fixed_cart (amount off the whole cart), fixed_product (amount off each qualifying item), '
			. 'percentage_cart or percentage_product (percent off). It is REQUIRED here because J2Commerce\'s '
			. 'own table defaults a missing type to "percentage", which its discount maths does not recognise — '
			. 'such a coupon is accepted at checkout and discounts nothing. '
			. 'Codes are stored uppercased and must be unique. Restrictions (product_ids, category_ids, '
			. 'manufacturer_ids, user_ids) are checked to exist, and a product or category list too long for '
			. 'its 255-character column is refused rather than silently cut mid-id. Validity dates are UTC. '
			. 'Coupons are only offered at checkout when the store\'s enable_coupon setting is on — the result '
			. 'says so if it is off. J2Commerce\'s per-group coupon restriction is not offered: 6.6.5 has no '
			. 'column to store it in, so it can never take effect.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => $this->couponSchemaProps(),
			'required' => ['code', 'value', 'value_type'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.editorders');

		if (!$actor->authorise('core.create', 'com_j2commerce')) {
			throw new \RuntimeException('Creating a coupon requires core.create on com_j2commerce.');
		}

		$columns = $this->couponColumns($arguments, null);
		$now     = $this->j2Now();
		$db      = $this->db;

		$next = (int) $db->setQuery('SELECT COALESCE(MAX(ordering), 0) + 1 FROM ' . $db->quoteName($this->j2Table('coupons')))->loadResult();

		// Defaults as CouponTable::check() applies them — except value_type, which is required.
		$row = (object) array_merge([
			'coupon_name'       => (string) ($columns['coupon_code'] ?? ''),
			'enabled'           => 1,
			'ordering'          => $next,
			'max_value'         => '',
			'free_shipping'     => 0,
			'max_uses'          => 0,
			'logged'            => 0,
			'max_customer_uses' => 0,
			'valid_from'        => null,
			'valid_to'          => null,
			'product_category'  => '',
			'products'          => '',
			'min_subtotal'      => '',
			'users'             => '',
			'mycategory'        => '',
			'brand_ids'         => '',
			'access'            => 1,
			'created_on'        => $now,
			'created_by'        => (int) $actor->id,
			'modified_on'       => $now,
			'modified_by'       => (int) $actor->id,
		], $columns);

		$db->insertObject($this->j2Table('coupons'), $row, 'j2commerce_coupon_id');
		$id = (int) $row->j2commerce_coupon_id;

		$saved = $db->setQuery('SELECT * FROM ' . $db->quoteName($this->j2Table('coupons')) . ' WHERE j2commerce_coupon_id = ' . $id)->loadAssoc();

		$response = ['ok' => true, 'created' => true] + $this->shapeCoupon($saved, 0);

		if ((string) ($this->j2RawParams()['enable_coupon'] ?? '0') !== '1') {
			$response['store_warning'] = 'Coupons are switched OFF for this store (enable_coupon), so shoppers cannot '
				. 'enter this code yet. Turn it on with set_j2commerce_config {"enable_coupon": "1"}.';
		}

		return ToolResult::json($response);
	}
}
