<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Version, edition and a headline inventory of an AcyMailing install.
 *
 * The edition is reported twice on purpose: `level_configured` is the raw
 * #__acym_configuration.level row the installer wrote, and `edition` is what
 * acym_level() — the vendor's own gate — concludes after demoting an expired
 * trial. When they disagree, the trial has lapsed and the site behaves as
 * Starter regardless of what was purchased.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use AcymailingBootTrait;

	/** Which vendor gate governs which capability (read from acym_level() call sites). */
	private const CAPABILITIES = [
		'starter' => [
			'lists', 'subscribers', 'subscription forms', 'classic campaigns sent now', 'templates',
			'welcome / unsubscribe emails', 'manual queue processing', 'global campaign statistics',
		],
		'essential' => [
			'scheduled campaigns', 'automatic queue processing by cron', 'detailed per-subscriber statistics',
			'click map and link statistics', 'multilingual emails', 'captcha on forms', 'front-end REST API controller',
		],
		'enterprise' => [
			'segments', 'automations', 'scenarios', 'automatic (triggered) campaigns', 'A/B test campaigns',
			'follow-ups', 'birthday campaigns', 'custom fields beyond name/email/language',
			'mailbox actions / bounce handling', 'email overrides', 'front-end list management',
		],
	];

	public function getName(): string { return 'get_acym_component_info'; }

	public function getDescription(): string
	{
		return 'AcyMailing version, edition (Starter / Essential / Enterprise, decided by the vendor\'s own '
			. 'acym_level() gate which also demotes an expired trial to Starter), licence state, mailer '
			. 'method, queue mode, headline counts, and which capabilities each edition unlocks. Start here: '
			. 'it tells you whether the Pro add-on (MCP for AcyMailing Essential & Enterprise) has anything to '
			. 'offer this site. Also reports the URL root AcyMailing baked into its link constants, which must '
			. 'be the site root and never /api/.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$loaded = $this->ensureAcyLoaded();
		$cfg    = $this->acyConfigAll();
		$level  = $this->acyLevel();

		$manifest = (string) $this->db->setQuery(
			$this->db->getQuery(true)->select('manifest_cache')->from('#__extensions')
				->where('element = ' . $this->db->quote('com_acym'))->where('type = ' . $this->db->quote('component'))
		)->loadResult();
		$manifestVersion = (string) (json_decode($manifest, true)['version'] ?? '');

		$plugins = $this->db->setQuery(
			$this->db->getQuery(true)->select(['folder', 'element', 'enabled'])->from('#__extensions')
				->where('type = ' . $this->db->quote('plugin'))
				->where('(element IN (' . implode(',', array_map([$this->db, 'quote'], ['acymtriggers', 'acymailoverride', 'jceacym'])) . ') OR folder = ' . $this->db->quote('acymailing') . ')')
		)->loadAssocList() ?: [];

		$count = fn (string $table, string $where = '1=1'): int => (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable($table)) . ' WHERE ' . $where
		)->loadResult();

		$expiration = $cfg['expirationdate'] ?? '';
		$unlocked   = [];
		foreach (self::CAPABILITIES as $tier => $caps) {
			$unlocked[$tier] = ['available' => $this->acyLevelRank($level) >= $this->acyLevelRank($tier), 'capabilities' => $caps];
		}

		return ToolResult::json([
			'installed'          => true,
			'framework_loaded'   => $loaded,
			'version'            => $cfg['version'] ?? null,
			'manifest_version'   => $manifestVersion ?: null,
			'edition'            => $level,
			'level_configured'   => $cfg['level'] ?? null,
			'is_trial'           => ($cfg['isTrial'] ?? '0') === '1',
			'licence_expires'    => ctype_digit((string) $expiration) && $expiration !== '' ? gmdate('Y-m-d H:i:s', (int) $expiration) . ' UTC' : null,
			'licence_key_set'    => ($cfg['license_key'] ?? '') !== '',
			'install_complete'   => ($cfg['installcomplete'] ?? '0') === '1',
			'mailer'             => $this->acyMailerSummary(),
			'require_confirmation' => $this->acyRequiresConfirmation(),
			'counts'             => [
				'subscribers'           => $count('user'),
				'subscribers_active'    => $count('user', 'active = 1'),
				'subscribers_confirmed' => $count('user', 'confirmed = 1'),
				'lists'                 => $count('list'),
				'campaigns'             => $count('campaign'),
				'templates'             => $count('mail', "type = 'template'"),
				'queued_emails'         => $count('queue'),
			],
			'urls'               => [
				'site_root'        => $this->acySiteRoot(),
				'request_root'     => Uri::root(),
				'acym_live'        => \defined('ACYM_LIVE') ? ACYM_LIVE : null,
				'acym_live_ok'     => \defined('ACYM_LIVE') ? !str_contains((string) ACYM_LIVE, '/api/') : null,
			],
			'plugins'            => array_map(static fn (array $p): array => [
				'plugin' => $p['folder'] . '/' . $p['element'], 'enabled' => (int) $p['enabled'] === 1,
			], $plugins),
			'editions'           => $unlocked,
			'pro_addon'          => $this->acyLevelRank($level) >= 1
				? 'This site runs a paid edition. The Pro add-on (csmcpforjacymailingpro, "MCP for AcyMailing (Essential & Enterprise)") adds tools for the capabilities marked available above.'
				: 'Starter: everything AcyMailing Starter can do is covered by this free add-on. The Pro add-on only adds tools for Essential/Enterprise capabilities, and refuses them on Starter.',
		]);
	}
}
