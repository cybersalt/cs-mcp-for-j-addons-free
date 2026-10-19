<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools;

\defined('_JEXEC') or die;

/**
 * Thrown when a write touches a setting behind a capability Web357 sells as
 * CPNB Pro and the installed build is the free one. Tools catch it and return
 * CpnbBootTrait::cpnbProRequiredError() so the refusal names the capability.
 */
final class CpnbProRequiredException extends \RuntimeException
{
	public function __construct(public readonly string $capability, public readonly string $key)
	{
		parent::__construct('"' . $key . '" belongs to ' . $capability . ', a Cookies Policy Notification Bar PRO capability.');
	}
}
