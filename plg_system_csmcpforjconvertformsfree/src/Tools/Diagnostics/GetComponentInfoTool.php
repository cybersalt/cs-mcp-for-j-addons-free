<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

final class GetComponentInfoTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'get_convertforms_component_info'; }

	public function getDescription(): string
	{
		return 'Convert Forms install report: version, edition (free/pro), which '
			. 'database tables exist, row counts, whether legacy campaigns are '
			. 'active, and which convertformsapps / convertformstools plugins are '
			. 'installed and enabled. Call this first when you are unsure what this '
			. 'site can do — the free edition ships a strict subset of the app '
			. 'integrations and field types, and several tools degrade accordingly.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		$tables = [];
		foreach (['', 'conversions', 'submission_meta', 'tasks', 'tasks_history', 'connections', 'campaigns'] as $suffix) {
			$key          = $suffix === '' ? 'convertforms' : 'convertforms_' . $suffix;
			$exists       = $suffix === '' ? true : $this->cfTableExists($suffix);
			$tables[$key] = ['exists' => $exists, 'rows' => $exists ? $this->countRows($suffix) : null];
		}

		$available = $this->cfAvailableFieldTypes();
		$registry  = $this->cfFieldTypeRegistry();
		$registered = [];
		foreach ($registry as $types) {
			$registered = array_merge($registered, $types);
		}
		$lockedTypes = array_values(array_diff(array_unique($registered), $available));
		sort($lockedTypes);

		return ToolResult::json([
			'ok'                      => true,
			'installed'               => true,
			'version'                 => $this->cfVersion(),
			'edition'                 => $this->cfIsPro() ? 'pro' : 'free',
			'is_pro'                  => $this->cfIsPro(),
			'legacy_campaigns_enabled' => $this->cfLegacyCampaignsEnabled(),
			'tables'                  => $tables,
			'field_types_usable'      => $available,
			'field_types_locked'      => $lockedTypes,
			'apps'                    => $this->pluginsInGroup('convertformsapps'),
			'tools'                   => $this->pluginsInGroup('convertformstools'),
			'notes'                   => [
				'field_types_locked lists types the form builder shows with a padlock: '
					. 'they are in the vendor registry but their PHP class is not on disk, '
					. 'so adding one would produce a field that cannot render.',
				'Legacy campaigns are absent from clean 5.x installs. When '
					. 'legacy_campaigns_enabled is false, ignore campaign_id on submissions.',
			],
		]);
	}

	/** Row count for a Convert Forms table, or null when it can't be read. */
	private function countRows(string $suffix): ?int
	{
		try {
			$q = $this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->cfTableName($suffix)));

			return (int) $this->db->setQuery($q)->loadResult();
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Installed plugins in a Convert Forms plugin group, with enabled state.
	 *
	 * @return array<int, array{element: string, enabled: bool}>
	 */
	private function pluginsInGroup(string $folder): array
	{
		$q = $this->db->getQuery(true)
			->select([$this->db->quoteName('element'), $this->db->quoteName('enabled')])
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
			->order($this->db->quoteName('element') . ' ASC');

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[] = [
				'element' => (string) $row['element'],
				'enabled' => (bool) $row['enabled'],
				'loaded'  => PluginHelper::isEnabled($folder, (string) $row['element']),
			];
		}

		return $out;
	}
}
