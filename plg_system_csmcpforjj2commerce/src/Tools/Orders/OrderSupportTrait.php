<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders;

\defined('_JEXEC') or die;

/**
 * An order has two identifiers and every caller confuses them.
 *
 *   j2commerce_order_id   INT primary key. What the vendor's models and REST
 *                         API take, and what #__j2commerce_ordertransactions
 *                         joins on.
 *   order_id              VARCHAR order NUMBER shown to the customer
 *                         (`time() . pk` from checkout, `Ymd-HEX` from the REST
 *                         POST). What every OTHER child table joins on.
 *
 * Every order tool accepts either and says which it resolved.
 */
trait OrderSupportTrait
{
	protected function orderSchemaProps(): array
	{
		return [
			'order_pk'     => ['type' => 'integer', 'description' => 'j2commerce_order_id, the integer primary key.'],
			'order_number' => ['type' => 'string', 'description' => 'order_id, the order number the customer sees.'],
		];
	}

	/** @return array the order row, unredacted — callers must redact before returning it. */
	protected function resolveOrder(array $arguments): array
	{
		$db = $this->db;

		if (!empty($arguments['order_pk'])) {
			$where = 'j2commerce_order_id = ' . (int) $arguments['order_pk'];
		} elseif (isset($arguments['order_number']) && trim((string) $arguments['order_number']) !== '') {
			$where = 'order_id = ' . $db->quote(trim((string) $arguments['order_number']));
		} else {
			throw new \InvalidArgumentException('Pass order_pk (the integer id) or order_number (the order number the customer sees).');
		}

		$row = $db->setQuery('SELECT * FROM ' . $db->quoteName($this->j2Table('orders')) . ' WHERE ' . $where)->loadAssoc();

		if (!\is_array($row)) {
			throw new \InvalidArgumentException('No J2Commerce order matches ' . $where . '.');
		}

		return $row;
	}

	protected function statusInfo(int $statusId): array
	{
		$map    = $this->j2StatusMap();
		$status = $map[$statusId] ?? null;

		return [
			'id'           => $statusId,
			'name'         => $status['label'] ?? null,
			'type'         => $status['type'] ?? null,
			'exists'       => $status !== null,
			'holds_stock'  => !\in_array($statusId, self::J2_STOCK_RELEASING_STATUSES, true),
			'counts_as_paid' => $status !== null && \in_array((string) $status['type'], self::J2_FINALIZED_TYPES, true),
		];
	}

	protected function orderSummary(array $o): array
	{
		return [
			'order_pk'       => (int) $o['j2commerce_order_id'],
			'order_number'   => (string) $o['order_id'],
			'invoice'        => trim((string) $o['invoice_prefix'] . ((int) $o['invoice_number'] > 0 ? (string) $o['invoice_number'] : '')),
			'status'         => $this->statusInfo((int) $o['order_state_id']),
			'customer_email' => (string) $o['user_email'],
			'user_id'        => (int) $o['user_id'],
			'total'          => $this->j2Money($o['order_total']),
			'currency'       => (string) $o['currency_code'],
			'payment_method' => (string) $o['orderpayment_type'],
			'created_on'     => (string) $o['created_on'],
			'modified_on'    => (string) $o['modified_on'],
		];
	}
}
