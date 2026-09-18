<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\User\User;
use Joomla\Event\DispatcherInterface;

/**
 * Bootstrap helpers for talking to Akeeba Ticket System from inside an MCP tool.
 *
 * ATS 5.x is a modern, namespaced Joomla component (Akeeba\Component\ATS) with a
 * real service provider and MVCFactory, so — unlike RSTicketsPro or Events Booking —
 * there is no manual require_once dance here. What this trait exists for is the
 * four things that are NOT automatic, each of which is a silent failure if skipped.
 *
 * 1. THE COMPOSER AUTOLOADER ONLY LOADS VIA bootComponent()
 * --------------------------------------------------------
 * ATS ships its own vendor/ directory and the ONLY place it is required is
 * services/provider.php:37 — `require_once __DIR__ . '/../vendor/autoload.php';`
 * That file runs when Joomla boots the component, and at no other time. ATS uses
 * HTMLPurifier out of that vendor tree from Helper\Filter::filterText(), which is
 * on the save path for every ticket and post. Touch an ATS class without booting
 * the component first and the write fatals on a missing HTMLPurifier class, with
 * nothing in the log to explain why. atsBoot() is therefore not optional, and
 * every tool calls it before anything else.
 *
 * 2. ATS_PRO IS DEFINED BY THE DISPATCHER, WHICH WE NEVER RUN
 * -----------------------------------------------------------
 * The Core/Pro split is a constant, and roughly thirty-five places across the
 * tables, models and the permissions helper read it as
 * `defined('ATS_PRO') && ATS_PRO`. It is defined in Dispatcher::loadVersion(),
 * which only runs when the component dispatches an HTTP request through its own
 * entry point. An MCP tool call never dispatches com_ats, so without help the
 * constant is simply absent — and "absent" reads as Core to every one of those
 * checks. That would be a quiet, wrong answer on a Pro site: attachments and
 * manager notes would be reported as unavailable on an install that has them.
 *
 * atsDefineVersionConstants() replicates loadVersion() exactly, including its
 * fallback. The real implementation, verbatim from
 * administrator/components/com_ats/src/Dispatcher/Dispatcher.php:236-259:
 *
 *     $filePath = JPATH_ADMINISTRATOR . '/components/com_ats/version.php';
 *     if (@file_exists($filePath) && is_file($filePath)) { include_once $filePath; }
 *     if (!defined('ATS_VERSION')) define('ATS_VERSION', 'dev');
 *     if (!defined('ATS_DATE'))    define('ATS_DATE', gmdate('Y-m-d'));
 *     if (!defined('ATS_PRO')) {
 *         $isPro = @is_dir(JPATH_ADMINISTRATOR . '/components/com_ats/src/CliCommand');
 *         define('ATS_PRO', $isPro ? '1' : '0');
 *     }
 *
 * Two details that matter. version.php in a released build defines ATS_PRO
 * itself, as a STRING '0' or '1' — and PHP treats the string "0" as falsy, which
 * is what makes the vendor's `&& ATS_PRO` idiom work. The is_dir() fallback only
 * applies to dev builds with no version.php. We reproduce both rather than
 * picking one, because a site running either shape must be read correctly.
 *
 * We deliberately do NOT define ATS_PRO to '1' under any circumstance of our own
 * choosing. Re-implementing the vendor's gate is the point; reaching around it is
 * not ours to do. Tools whose capability is Pro-only call requireAtsPro() and
 * refuse cleanly on Core.
 *
 * 3. Permissions CACHES PER-IDENTITY STATE IN STATICS
 * ----------------------------------------------------
 * Helper\Permissions memoises privilege lookups keyed on the "current" user, and
 * getManagers() / getAssignees() / getManagerCategories() additionally hold
 * per-category function statics. That is fine in a web request, which serves one
 * identity and then dies. An MCP server process can serve tool calls for more
 * than one actor, so atsBoot() calls Permissions::setCacheIdentities(false) to
 * turn the identity memoisation off. Note this does NOT clear the per-category
 * function statics inside getManagers()/getAssignees() — those can still go stale
 * within a single long-lived process, which is why tools that need a manager list
 * for a decision re-derive it rather than trusting a value read earlier in the call.
 *
 * 4. MAIL NEEDS A SiteApplication, AND ATS NEEDS A siteurl
 * --------------------------------------------------------
 * EmailSending::sendPostEmails() / sendAssignedEmails() build ticket URLs for the
 * notification bodies. The MCP endpoint runs under an ApiApplication, which has no
 * menu and no site router. Same trap as the RSTicketsPro add-on, same remedy —
 * withSiteAppContext(). ATS additionally reads a `siteurl` component param when
 * building those links; on a site where it was never set the mail goes out with
 * broken links rather than failing, so tools that send check it and say so.
 *
 * @see  ATS 5.6.0, read from a live Core install (mcpfree.basicjoomla.com).
 */
trait ATSBootTrait
{
	/**
	 * The component's own tables, for the scoped query escape hatch. Anything not
	 * on this list is refused — the tool is a convenience for reading ATS data,
	 * not a general SQL console.
	 *
	 * ATS' install SQL creates every table unconditionally, with no edition
	 * branching at all, so all of these exist on a Core install and a SELECT
	 * against them never errors — the Pro-only ones simply return zero rows.
	 *
	 * #__ats_managernotes IS DELIBERATELY ABSENT FROM THIS LIST.
	 * ---------------------------------------------------------
	 * It is the one table where "Pro-only, therefore empty on Core" is false.
	 * Manager notes are private staff-only commentary, and a site that ran ATS
	 * Professional and later dropped to Core keeps every note it ever wrote —
	 * the Pro→Core downgrade removes code, not data. ATS itself fails closed
	 * there: TicketTable::managerNotes() returns [] on the edition check before
	 * it ever builds a query, so those rows are invisible in the Core UI. A
	 * generic SELECT would not fail closed, and would hand private notes to
	 * anyone who could name the table. So this add-on reads notes only through
	 * managerNotes(), and the query tool cannot reach them at all.
	 *
	 * Also absent, and for a duller reason: #__ats_credittransactions and
	 * #__ats_creditconsumptions are DROPped by ATS 5's own installer. Querying
	 * them errors rather than returning nothing.
	 */
	private const ATS_TABLES = [
		'ats_tickets'       => 'Tickets. No access and no language column — both are inherited from the category via catid.',
		'ats_posts'         => 'Ticket replies, including the opening post. attachment_id is a comma-separated list, not a foreign key.',
		'ats_tickets_users' => 'Invited collaborators. A Core feature. No unique index on (ticket_id, user_id), so direct SQL can duplicate a row.',
		'ats_attachments'   => 'Post attachments. Pro-only; zero rows on a Core install.',
		'ats_cannedreplies' => 'Canned replies. Pro-only; zero rows on a Core install. Note access defaults to 0, which is not a valid Joomla view level.',
		'ats_autoreplies'   => 'Automatic replies. Pro-only; zero rows on a Core install. No column has a default.',
	];

	/** Ticket status codes that ATS itself defines. See getStatuses() for the full set. */
	private const ATS_CORE_STATUSES = [
		'O' => 'Open — waiting on the support team.',
		'P' => 'Pending — waiting on the customer. Only a manager\'s reply sets this.',
		'C' => 'Closed.',
	];

	private static bool $atsBooted = false;

	/** Absolute admin component path, or null when ATS is not installed. */
	protected function atsAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_ats';

		return is_dir($path) ? $path : null;
	}

	/**
	 * Boot com_ats so its Composer autoloader, language files and edition
	 * constants are all in place. Returns false when ATS is not installed, in
	 * which case the caller should return notInstalledError().
	 *
	 * Safe and cheap to call repeatedly; the work happens once per process.
	 */
	protected function atsBoot(): bool
	{
		if ($this->atsAdminBase() === null) {
			return false;
		}

		if (self::$atsBooted) {
			return true;
		}

		// Runs services/provider.php, which requires ATS' vendor/autoload.php.
		// Without this, HTMLPurifier is missing and Filter::filterText() fatals
		// on the save path. See the class docblock, point 1.
		$this->bootComponent('com_ats');

		$this->atsDefineVersionConstants();

		// AdminModel::getForm() resolves forms relative to JPATH_COMPONENT, which
		// under the API app points at the API entry point rather than com_ats.
		// Registering the real paths up front keeps getForm() working.
		$base = $this->atsAdminBase();
		if (is_dir($base . '/forms')) {
			FormHelper::addFormPath($base . '/forms');
		}
		if (is_dir($base . '/src/Field')) {
			FormHelper::addFieldPath($base . '/src/Field');
		}

		// ATS exception and status messages are language strings; without these
		// a refusal surfaces as a raw COM_ATS_* key.
		$lang = Factory::getApplication()->getLanguage();
		$lang->load('com_ats', JPATH_ADMINISTRATOR);
		$lang->load('com_ats', JPATH_SITE);

		// One process, potentially many actors. See the class docblock, point 3.
		if (class_exists(\Akeeba\Component\ATS\Administrator\Helper\Permissions::class)) {
			\Akeeba\Component\ATS\Administrator\Helper\Permissions::setCacheIdentities(false);
		}

		self::$atsBooted = true;

		return true;
	}

	/**
	 * Replicate Dispatcher::loadVersion() so ATS_PRO / ATS_VERSION / ATS_DATE are
	 * defined the way the component itself would define them. See the class
	 * docblock, point 2, for why this is necessary and why we don't shortcut it.
	 */
	private function atsDefineVersionConstants(): void
	{
		$filePath = JPATH_ADMINISTRATOR . '/components/com_ats/version.php';

		if (@file_exists($filePath) && is_file($filePath)) {
			include_once $filePath;
		}

		if (!\defined('ATS_VERSION')) {
			\define('ATS_VERSION', 'dev');
		}

		if (!\defined('ATS_DATE')) {
			\define('ATS_DATE', gmdate('Y-m-d'));
		}

		if (!\defined('ATS_PRO')) {
			// The vendor's own dev-build fallback: Pro ships a CliCommand directory,
			// Core does not.
			$isPro = @is_dir(JPATH_ADMINISTRATOR . '/components/com_ats/src/CliCommand');
			\define('ATS_PRO', $isPro ? '1' : '0');
		}
	}

	/**
	 * Is this install ATS Professional? Uses exactly the expression the component
	 * uses internally — `defined('ATS_PRO') && ATS_PRO` — where ATS_PRO is the
	 * string '0' or '1' and "0" is falsy in PHP.
	 */
	protected function atsIsPro(): bool
	{
		return \defined('ATS_PRO') && ATS_PRO;
	}

	/** Installed ATS version, or null if the constant never got defined. */
	protected function atsVersion(): ?string
	{
		return \defined('ATS_VERSION') ? (string) ATS_VERSION : null;
	}

	/**
	 * Get an ATS model through the component's own MVCFactory, with request
	 * coupling off (we run under the API app; request-derived model state is
	 * wrong here).
	 *
	 * @param string $name e.g. 'Ticket', 'Tickets', 'Post', 'Posts'
	 */
	protected function atsModel(string $name, string $client = 'Administrator'): ?object
	{
		if (!$this->atsBoot()) {
			return null;
		}

		return $this->bootComponent('com_ats')
			->getMVCFactory()
			->createModel($name, $client, ['ignore_request' => true]);
	}

	/**
	 * Get an ATS table through the component's MVCFactory.
	 *
	 * The factory injects the dispatcher, which matters: ATS' AbstractTable
	 * dispatches its own onBeforeStore / onAfterStore / onAfterDelete events, and
	 * that is how PostTable recomputes the parent ticket's status and timespent
	 * after a reply. A table built with plain `new` has no dispatcher and would
	 * silently skip all of it.
	 *
	 * @param string $name e.g. 'Ticket', 'Post', 'Attachment', 'Managernote'
	 */
	protected function atsTable(string $name): ?object
	{
		if (!$this->atsBoot()) {
			return null;
		}

		$table = $this->bootComponent('com_ats')
			->getMVCFactory()
			->createTable($name, 'Administrator', ['dbo' => $this->db]);

		if ($table !== null && method_exists($table, 'setDispatcher')) {
			$table->setDispatcher(Factory::getContainer()->get(DispatcherInterface::class));
		}

		return $table;
	}

	/**
	 * Load a ticket into a TicketTable, or return null when it does not exist.
	 * Always use this rather than binding an unloaded table — ATS' save paths
	 * read existing values off the loaded object to decide what changed.
	 */
	protected function atsLoadTicket(int $id): ?object
	{
		$table = $this->atsTable('Ticket');

		if ($table === null || $id <= 0 || !$table->load($id)) {
			return null;
		}

		return $table;
	}

	/**
	 * The authoritative per-ticket visibility and capability gate.
	 *
	 * Every read tool runs each candidate row through this before returning it,
	 * even rows that came out of a query believed to be correctly filtered. That
	 * belt-and-braces is deliberate: ATS 5.6.0 shipped specifically to fix a
	 * disagreement between the list query and the single-resource check, where a
	 * ticket the collection endpoint correctly omitted was happily served by the
	 * single-resource endpoint through the JSON:API. Private tickets leaked. We
	 * are not going to re-create that bug by hand-rolling the predicate.
	 *
	 * Returns the vendor's privilege array — 'view', 'post', 'edit', 'edit.state',
	 * 'admin', 'attachment', 'ticket.invite' and friends.
	 *
	 * @return array<string, bool>
	 */
	protected function atsTicketPrivileges(object $ticketTable, ?User $user = null): array
	{
		return \Akeeba\Component\ATS\Administrator\Helper\Permissions::getTicketPrivileges($ticketTable, $user);
	}

	/** Convenience: may this actor see this ticket at all? */
	protected function atsCanViewTicket(object $ticketTable, ?User $user = null): bool
	{
		return (bool) ($this->atsTicketPrivileges($ticketTable, $user)['view'] ?? false);
	}

	/** Standard error response for "ATS isn't on this site." */
	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error(
			'Akeeba Ticket System (com_ats) is not installed on this site, or the install is incomplete. '
			. 'The free Core edition is enough for these tools.'
		);
	}

	/**
	 * Standard refusal for a capability that exists only in ATS Professional.
	 *
	 * We refuse rather than write the rows directly, even though every one of
	 * these tables exists on a Core install and a plain INSERT would "work".
	 * Re-implementing the vendor's gate is the whole point.
	 */
	protected function proRequiredError(string $feature): ToolResult
	{
		return ToolResult::error(
			$feature . ' is a feature of Akeeba Ticket System Professional, and this site is running '
			. 'the free Core edition (ATS_PRO is 0). The database table exists but ATS itself never '
			. 'reads or writes it on Core, so populating it would have no effect in the UI. '
			. 'Upgrade at https://www.akeeba.com to enable it.'
		);
	}

	/**
	 * Run $fn with Factory::$application temporarily replaced by a real
	 * bootstrapped SiteApplication, then restore the original (api) app in a
	 * finally block. Returns whatever $fn returns.
	 *
	 * WRAP ANY WRITE THAT MAY SEND MAIL. In practice that means saving a post
	 * (EmailSending::sendPostEmails), assigning a ticket
	 * (sendAssignedEmails) and inviting a collaborator (TicketController::invite
	 * writes a system post and notifies). All of them build ticket URLs for the
	 * mail body, and URL building under the API app fails with
	 * "Error loading menu: api".
	 *
	 * Same trick Joomla's own CLI tasks use when invoking front-end code that
	 * builds SEF URLs.
	 *
	 * ALWAYS PASS $actor. THIS IS NOT OPTIONAL IN PRACTICE.
	 * ------------------------------------------------------
	 * A SiteApplication built out of the DI container has NO IDENTITY LOADED. It
	 * reads as a guest. That matters enormously here, because
	 * Permissions::getUser() with no argument resolves to
	 * Factory::getApplication()->getIdentity() (Permissions.php:1003-1013), and
	 * essentially every ACL decision in ATS runs through it. Swap the application
	 * without restoring the identity and the authenticated MCP actor silently
	 * becomes an anonymous visitor for the duration of the closure — so
	 * TicketModel::inviteUser() throws 403, getTicketPrivileges() returns a guest's
	 * privileges, and a save that should have been a manager's reply is treated as
	 * a stranger's. The failure looks like a permissions bug in our tool rather
	 * than a missing line here.
	 *
	 * Passing $actor makes loadIdentity() restore the caller, so the code inside
	 * the closure sees the same user the API application saw. Omit it only when
	 * the closure genuinely makes no ACL decision — and prefer passing it anyway,
	 * since the cost is one call.
	 *
	 * A related consequence worth knowing: if the vendor method you are calling
	 * performs the ACL check ITSELF and does not build a URL, it is better to call
	 * it OUTSIDE the wrapper under the real API app, and wrap only the
	 * mail-sending part afterwards. InviteUserTool does exactly that.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @param User|null $actor The MCP caller, re-loaded into the swapped application.
	 * @return T
	 */
	protected function withSiteAppContext(callable $fn, ?User $actor = null)
	{
		$originalApp = Factory::$application;

		try {
			$siteApp = Factory::getContainer()->get(SiteApplication::class);
			$siteApp->loadLanguage();

			// Without this the swapped app is a guest. See the docblock.
			if ($actor !== null && (int) $actor->id > 0) {
				$siteApp->loadIdentity($actor);
			}

			Factory::$application = $siteApp;

			return $fn();
		} finally {
			Factory::$application = $originalApp;
		}
	}

	/**
	 * Is the component's `siteurl` param populated?
	 *
	 * BE PRECISE ABOUT WHAT THIS DOES AND DOESN'T PREDICT. The obvious reading —
	 * "empty siteurl means notification mail goes out with broken links" — is
	 * only half right, and stating it flatly in a tool response would be wrong
	 * most of the time in this add-on.
	 *
	 * EmailSending.php:703 resolves the {siteurl} token as:
	 *
	 *     $app->isClient('site') ? Uri::base() : $params->get('siteurl')
	 *
	 * Every mail-sending tool here runs inside withSiteAppContext(), which makes
	 * isClient('site') true — so the param is BYPASSED and the links come from
	 * Uri::base(). An empty `siteurl` therefore does NOT break the mail our tools
	 * send.
	 *
	 * It still matters, which is why this helper exists: the param is what ATS
	 * uses from any non-site context — its Pro CLI tasks, scheduled auto-close
	 * and auto-reply runs, and the mail gateway. So an empty value is a real
	 * latent misconfiguration on the site, just not one that our own sends will
	 * trip over. Report it as site configuration worth fixing, not as "the mail
	 * you just sent is broken".
	 */
	protected function atsSiteUrlConfigured(): bool
	{
		return trim((string) $this->atsParams()->get('siteurl', '')) !== '';
	}

	/** com_ats component params, read from #__extensions the standard way. */
	protected function atsParams(): \Joomla\Registry\Registry
	{
		return \Joomla\CMS\Component\ComponentHelper::getParams('com_ats');
	}

	/**
	 * Human-readable label for a ticket status code.
	 *
	 * ATS stores status as an ENUM of 'O', 'P', 'C' plus the strings '1' through
	 * '99'. The numeric members are user-defined statuses; Permissions::getStatuses()
	 * is the only authority on what they mean on a given site, so we ask it and
	 * fall back to the three built-ins.
	 *
	 * NOTE ON THE RETURN SHAPE OF getStatuses().
	 * ------------------------------------------
	 * It is a FLAT MAP of status code => label string — not a list of
	 * ['value' => ..., 'text' => ...] option objects. See
	 * Helper/Permissions.php:536-603: it seeds ['O' => …, 'P' => …], appends each
	 * parsed custom status, and finally appends 'C' so Closed always sorts last.
	 * Two details follow from that and both bite. First, the custom entries are
	 * written as `self::$ticketStatuses[$statusId]` where $statusId was cast with
	 * `(int)`, so their array keys are INTEGERS while the ENUM column stores the
	 * same value as the STRING '7'; the comparison therefore has to be
	 * `(string) $key === $status`. Second, an earlier version of this method
	 * looked for ->value / ->text on each element, which on a plain string
	 * silently evaluates to null under `??` — no warning, no match, so every
	 * site-defined status fell through to the generic fallback below and the
	 * site's own label was never shown.
	 */
	protected function atsStatusLabel(string $status): string
	{
		if (isset(self::ATS_CORE_STATUSES[$status])) {
			return self::ATS_CORE_STATUSES[$status];
		}

		try {
			$all = \Akeeba\Component\ATS\Administrator\Helper\Permissions::getStatuses();
		} catch (\Throwable) {
			return 'Unknown status "' . $status . '".';
		}

		foreach ($all as $code => $item) {
			if ((string) $code !== $status) {
				continue;
			}

			if (\is_string($item) && $item !== '') {
				return $item;
			}

			// Defensive only: tolerate an option-list shape should a future ATS change it.
			$text = \is_array($item) ? ($item['text'] ?? null) : ($item->text ?? null);

			if ($text !== null && $text !== '') {
				return (string) $text;
			}
		}

		return 'Site-defined status "' . $status . '".';
	}
}
