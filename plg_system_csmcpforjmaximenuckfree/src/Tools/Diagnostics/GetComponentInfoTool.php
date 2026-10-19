<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Joomla\CMS\User\User;

/**
 * Orientation. Call this first on an unfamiliar site.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use MaximenuckBootTrait;

	public function getName(): string { return 'get_maximenuck_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for Maximenu CK on this site — call this first. Reports: the component version; '
			. 'the EDITION, using the vendor\'s own test (the <ckpro> flag in the installed component manifest, '
			. 'which is what Maximenu\'s Light installer reads) plus the Pro system plugin (plg_system_maximenuck: '
			. 'on disk / registered / enabled) as a second signal; the Pro source plugins (group "maximenuck": '
			. 'K2, VirtueMart, HikaShop, ...); the mod_maximenuck version; the three #__maximenuck_* tables with '
			. 'row counts; and a one-line summary of every Maximenu module (id, position, menutype, style id, '
			. 'compiled-CSS mode). What differs by edition: per-item mega-menu settings (columns, column '
			. 'widths, rows, submenu sizes, full width, tabs, icons, module insertion, desktop/mobile toggles) '
			. 'can only be EDITED in Pro — Light\'s menu manager shows them but nags instead of saving — and '
			. 'per-item styles only render while the Pro system plugin is enabled. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$tables = [];

		foreach (['styles', 'menus', 'menubuilder_item'] as $t) {
			$exists = $this->mxTableExists($t);
			$row    = ['table' => '#__maximenuck_' . $t, 'exists' => $exists];

			if ($exists) {
				$row['rows'] = (int) $this->db->setQuery(
					'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__maximenuck_' . $t)
				)->loadResult();
			}

			$tables[] = $row;
		}

		$moduleVersion = null;

		if ($this->mxModuleBase() !== null && is_file($this->mxModuleBase() . '/mod_maximenuck.xml')) {
			$xml = @simplexml_load_file($this->mxModuleBase() . '/mod_maximenuck.xml');

			if ($xml !== false) {
				$moduleVersion = trim((string) ($xml->version ?? '')) ?: null;
			}
		}

		$modules = array_map(function ($m) {
			$s = $this->mxModuleSummary($m);

			return [
				'id'              => $s['id'],
				'title'           => $s['title'],
				'position'        => $s['position'],
				'published'       => $s['published'],
				'source'          => $s['source'],
				'menutype'        => $s['menutype'],
				'style_id'        => $s['style_id'],
				'menu_html_id'    => $s['menu_html_id'],
				'loadcompiledcss' => $s['loadcompiledcss'],
			];
		}, $this->mxModules());

		$isPro = $this->mxVendorIsPro();

		return ToolResult::json([
			'ok'             => true,
			'installed'      => true,
			'component'      => $this->mxEditionNotice(),
			'module'         => [
				'installed' => $this->mxModuleBase() !== null,
				'version'   => $moduleVersion,
			],
			'source_plugins' => $this->mxSourcePlugins(),
			'tables'         => $tables,
			'modules'        => $modules,
			'capabilities'   => [
				'read_everything'               => true,
				'write_style_custom_css'        => true,
				'clear_compiled_css'            => true,
				'write_item_megamenu_settings'  => $isPro
					? 'yes — with the csmcpforjmaximenuckpro add-on'
					: 'no — Maximenu CK Pro feature (Light\'s own UI refuses it); not offered on Light',
			],
		]);
	}
}
