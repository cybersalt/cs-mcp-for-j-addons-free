<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

/**
 * Shared schema + validation for create_acym_campaign / update_acym_campaign.
 * Mirrors the mail columns the vendor's Campaigns\Edition::saveEditEmail()
 * writes.
 */
final class CampaignPayload
{
	public const MAIL_TEXT_FIELDS = ['name', 'subject', 'preheader', 'from_name', 'from_email', 'reply_to_name', 'reply_to_email', 'bcc', 'bounce_email', 'stylesheet', 'headers'];

	public static function schema(): array
	{
		return [
			'name'           => ['type' => 'string', 'description' => 'Internal campaign name. Defaults to the subject.'],
			'subject'        => ['type' => 'string'],
			'preheader'      => ['type' => 'string', 'description' => 'Inbox preview text.'],
			'body_html'      => ['type' => 'string', 'description' => 'Full HTML body. <script> and <input value> are stripped by the vendor.'],
			'stylesheet'     => ['type' => 'string', 'description' => 'Extra CSS inlined at send time.'],
			'from_name'      => ['type' => 'string', 'description' => 'Empty = configuration default.'],
			'from_email'     => ['type' => 'string'],
			'reply_to_name'  => ['type' => 'string'],
			'reply_to_email' => ['type' => 'string'],
			'bcc'            => ['type' => 'string'],
			'tracking'       => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Open/click tracking for this mail.'],
			'list_ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Target lists (replaces the current set on update).'],
			'template_id'    => ['type' => 'integer', 'description' => 'Copy body, stylesheet and editor settings from a template (list_acym_templates). body_html, if given, wins.'],
		];
	}

	/** @return array<string,mixed>|string mail columns, or an error */
	public static function mailFields(array $a): array|string
	{
		$out = [];
		foreach (self::MAIL_TEXT_FIELDS as $k) {
			if (array_key_exists($k, $a)) {
				$out[$k] = (string) $a[$k];
			}
		}
		foreach (['from_email', 'reply_to_email', 'bounce_email'] as $k) {
			if (!empty($out[$k]) && !filter_var($out[$k], FILTER_VALIDATE_EMAIL)) {
				return $k . ' is not a valid email address.';
			}
		}
		if (array_key_exists('body_html', $a)) {
			$out['body'] = (string) $a['body_html'];
		}
		if (array_key_exists('tracking', $a)) {
			$out['tracking'] = (int) $a['tracking'] ? 1 : 0;
		}

		return $out;
	}
}
