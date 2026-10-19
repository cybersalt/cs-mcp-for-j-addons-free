<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Support;

\defined('_JEXEC') or die;

/**
 * Request-wide state shared by every Community Builder tool.
 *
 * This lives in a class rather than in the boot trait on purpose: a trait's
 * static properties are copied into EVERY class that uses it, so thirty-odd
 * tool classes would each keep their own "booted" flag and each register their
 * own mail listener. CB itself boots once per request (`CBLib::createApplication()`
 * memoises in a static), so our record of that boot has to be single too.
 */
final class CbRuntime
{
	/** True once a boot has been attempted in this request (successful or not). */
	public static bool $bootAttempted = false;

	/** Why the boot failed, when it did. */
	public static ?string $bootError = null;

	/**
	 * CBLib's client name — 'administrator' when we booted CB ourselves. If some
	 * other extension booted CB earlier in the same request it keeps whatever
	 * client that extension booted it with, and this records it.
	 */
	public static ?string $client = null;

	/** True when CB was already booted by someone else before our first call. */
	public static bool $preBooted = false;

	/** True once the mail guard listener is registered with CB's plugin handler. */
	public static bool $mailGuardRegistered = false;
}
