<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Support;

\defined('_JEXEC') or die;

/**
 * Holds back, or lets through and repairs, every email Community Builder tries
 * to send while one of our tools is running.
 *
 * WHERE IT HOOKS. Every CB notification — welcome, pending, rejection, ban,
 * unban, moderator alerts — ends in `comprofilerMail()`
 * (`libraries/CBLib/CB/Legacy/LegacyComprofilerFunctions.php:331`), which fires
 * CB's own `onBeforeSendMail` event with the mailer, the recipient, the body and
 * an `&$error` by reference, and then refuses to send if `$error` is set
 * (`:450`). That is a vendor-designed veto point, so this is not a monkey-patch:
 * we register a listener on CB's plugin handler exactly as a CB plugin would.
 *
 * WHAT IT DOES.
 *   - mode 'block' (the default for every tool): records the message and sets
 *     `$error`, so CB does not send it. The tool reports what WOULD have gone out.
 *   - mode 'send': records it and lets it through.
 * In both modes it first rewrites `/api/` out of the links. Under Joomla's API
 * application `Uri::base()` and `Uri::root()` end in `/api/` (JPATH_BASE is the
 * api folder — a constant), CB builds `[SITEURL]` and the `[CONFIRM]` link from
 * `Uri::base()` (`CBframework::getCfg('live_site')`, `:275-286`), so without
 * this every link in a mail sent from an MCP call would point into the REST API.
 *
 * The listener is inert whenever `$active` is false, so it is harmless for the
 * rest of the request after the tool that armed it has finished.
 */
final class CbMailGuard
{
	public static bool $active = false;

	/** 'block' or 'send'. */
	public static string $mode = 'block';

	/** @var array<int,array<string,mixed>> */
	public static array $log = [];

	/** Site root without trailing slash, e.g. https://example.com or https://example.com/sub */
	public static string $siteBase = '';

	/** @var string[] cleartext values to mask in the returned record (never in the email). */
	private static array $secrets = [];

	public static function arm(string $mode, string $siteBase): void
	{
		self::$active   = true;
		self::$mode     = $mode === 'send' ? 'send' : 'block';
		self::$log      = [];
		self::$secrets  = [];
		self::$siteBase = rtrim($siteBase, '/');
	}

	/** @return array<int,array<string,mixed>> */
	public static function disarm(): array
	{
		self::$active  = false;
		$log           = self::$log;
		self::$log     = [];
		self::$secrets = [];

		return $log;
	}

	/**
	 * CB `onBeforeSendMail` listener. Signature mirrors the trigger's argument list
	 * (`LegacyComprofilerFunctions.php:337`); everything arrives by reference.
	 */
	public static function listener(
		&$mail, &$from, &$fromname, &$recipient, &$subject, &$body, &$mode, &$cc, &$bcc,
		&$attachment, &$replyto, &$replytoname, &$properties, &$error
	) {
		if (!self::$active) {
			return null;
		}

		$rewritten = 0;
		$body      = self::rewrite((string) $body, $rewritten);

		if (\is_object($mail) && property_exists($mail, 'Body')) {
			$extra      = 0;
			$mail->Body = self::rewrite((string) $mail->Body, $extra);
		}

		$subject = self::rewrite((string) $subject, $rewritten);

		// The record we return must not hand out the confirmation code (a bearer
		// credential for the account, UserTable::checkActivationCode()) or a
		// cleartext password CB generated and put in [DETAILS] / [PASSWORD].
		$record = self::maskCodes((string) $body);

		foreach (self::$secrets as $secret) {
			$record = str_replace($secret, '[password redacted]', $record);
		}

		preg_match_all('#https?://[^\s"\'<>]+#i', $record, $m);

		$links = array_values(array_unique(array_map(
			static fn (string $l): string => rtrim($l, '.,;:)!]'),
			$m[0] ?? []
		)));

		$entry = [
			'to'                 => self::addresses($recipient),
			'cc'                 => self::addresses($cc),
			'bcc'                => self::addresses($bcc),
			'from'               => trim((string) $fromname . ' <' . ((string) $from !== '' ? (string) $from : 'site default sender') . '>'),
			'subject'            => (string) $subject,
			'html'               => (bool) $mode,
			'body'               => mb_substr(strip_tags(str_replace(['<br />', '<br/>', '<br>'], "\n", $record)), 0, 4000),
			'links'              => $links,
			'api_links_repaired' => $rewritten,
			'sent'               => self::$mode === 'send',
		];

		if (self::$mode !== 'send') {
			$error          = 'Held back by the MCP add-on for Community Builder: send_email was not true.';
			$entry['note']  = 'Not sent. Community Builder built this message and our listener on its own '
				. 'onBeforeSendMail event vetoed it.';
		}

		self::$log[] = $entry;

		return null;
	}

	/**
	 * CB `onBeforeSendEmailMSG` listener (`cbNotification.php:476`). Fires before
	 * the message reaches comprofilerMail(), with the recipient UserTable — whose
	 * `password` holds the CLEARTEXT password at the moment CB mails a generated
	 * one (UserTable::approveUser() / confirmUser() with emailpass on). We note
	 * it only so listener() can mask it in the record we return.
	 */
	public static function beforeMessageListener($notification = null, &$toUser = null, ...$rest)
	{
		if (!self::$active || !\is_object($toUser) || !isset($toUser->password)) {
			return null;
		}

		$pw = (string) $toUser->password;

		// A stored hash is not a secret worth masking and would never appear in a body.
		if ($pw !== '' && preg_match('/^\$(2[aby]|argon2)/', $pw) !== 1 && \strlen($pw) >= 4) {
			self::$secrets[] = $pw;
		}

		return null;
	}

	/**
	 * CB `onAfterSendEmailMSG` listener (`cbNotification.php:528`): records what
	 * the mailer actually returned for the message just logged, so "sent" in a
	 * response is the mailer's answer rather than our intention.
	 */
	public static function afterListener($result, $notification = null, ...$rest)
	{
		if (!self::$active || self::$log === []) {
			return null;
		}

		$i = \count(self::$log) - 1;

		self::$log[$i]['mailer_accepted'] = (bool) $result;

		if (!$result && \is_object($notification) && property_exists($notification, 'errorMSG') && $notification->errorMSG) {
			self::$log[$i]['mailer_error'] = mb_substr(strip_tags((string) $notification->errorMSG), 0, 500);
		}

		return null;
	}

	/** Replace `<siteBase>/api/...` and a bare `<siteBase>/api` with the real site URL. */
	public static function rewrite(string $text, int &$count): string
	{
		if (self::$siteBase === '' || $text === '') {
			return $text;
		}

		$pattern = '#' . preg_quote(self::$siteBase, '#') . '/api(/|(?=[\s"\'<>?&\#)\]]|$))#i';

		return (string) preg_replace_callback(
			$pattern,
			static function (array $m) use (&$count): string {
				$count++;

				return self::$siteBase . ($m[1] === '/' ? '/' : '');
			},
			$text
		);
	}

	/** Mask `confirmcode=reg<32 hex><8 hex>` so the code itself never appears in a tool response. */
	public static function maskCodes(string $text): string
	{
		return (string) preg_replace('/(confirmcode=)(reg)[0-9a-f]{20,}/i', '$1$2…[redacted]', $text);
	}

	/** @return string[] */
	private static function addresses(mixed $value): array
	{
		if ($value === null || $value === '' || $value === []) {
			return [];
		}

		$out = [];

		foreach ((array) $value as $k => $v) {
			if (\is_array($v)) {
				$v = implode(' ', $v);
			}

			$out[] = \is_string($k) && str_contains($k, '@') ? $k : (string) $v;
		}

		return array_values(array_filter($out, static fn (string $a): bool => $a !== ''));
	}
}
