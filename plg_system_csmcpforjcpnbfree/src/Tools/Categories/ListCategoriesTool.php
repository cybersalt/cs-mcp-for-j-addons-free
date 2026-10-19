<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

final class ListCategoriesTool extends AbstractTool
{
	use CpnbBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'list_cpnb_categories'; }

	public function getDescription(): string
	{
		return 'List the cookie categories Cookies Policy Notification Bar offers visitors in its Cookies Manager '
			. '(e.g. Required, Analytical, Social Media, Targeted Advertising), in display order: id (the key '
			. 'stored in the visitor\'s cpnb_cookiesSettings consent cookie and referenced by blocking rules), '
			. 'name, description, default_state (locked / checked / unchecked), enabled, and how many PRO '
			. 'cookie-blocking rules point at it. source says whether the list is stored or CPNB\'s bundled '
			. 'defaults. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$settings = $this->cpnbEffective();
		$usage    = $this->catUsage($settings);
		$out      = [];

		foreach ($this->cpnbRows($settings, 'cookie_categories_group') as $i => $row) {
			$out[] = ['position' => $i + 1] + $this->catFromRow($row, $usage);
		}

		return ToolResult::json([
			'ok'         => true,
			'source'     => $this->cpnbSource('cookie_categories_group'),
			'count'      => \count($out),
			'categories' => $out,
			'note'       => $out === []
				? 'The list is empty, so the front end falls back to CPNB\'s four hard-coded sample categories.'
				: 'Category ids are immutable here: they are the keys in every visitor\'s stored consent.',
		]);
	}
}
