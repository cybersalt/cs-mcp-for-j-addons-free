<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Convert Forms MCP add-on plugin. Registers 30 tools wrapping Tassos Marinos'
 * Convert Forms (com_convertforms). Works against both the free and Pro
 * editions; tools that touch a Pro-only capability detect it and say so rather
 * than failing obscurely.
 *
 * Reads: direct SQL over `#__convertforms*`, with the JSON blobs decoded and
 * submitted values resolved against their form's field definitions so an agent
 * gets labelled answers instead of an opaque object.
 *
 * Writes: through Convert Forms' own legacy MVC classes —
 * `ConvertFormsModelForm` for forms (so per-field-type onBeforeFormSave hooks
 * run) and `ConvertFormsTableTask` for tasks (so its check() applies the
 * vendor's encoding rules). Convert Forms is not a namespaced component and has
 * no MVCFactory, so ConvertFormsBootTrait does the include-path registration
 * that makes those classes loadable.
 *
 * Deliberately NOT wrapped:
 *
 *   - Creating submissions. ConvertFormsModelConversion::createConversion()
 *     is the front-end pipeline: it runs validation, user PHP scripts, and
 *     fires every Task including outbound email. An MCP "add a submission"
 *     tool would be a way to send mail from the site as a side effect, and
 *     the same call on a form with save_data_to_db=0 returns a mocked row
 *     with a non-integer id that was never persisted.
 *
 *   - Reading or writing connection credentials. `#__convertforms_connections.params`
 *     holds third-party API secrets; list_convertforms_connections returns only
 *     id/app/title so tasks can be wired up by id.
 *
 *   - Bulk submission export. That is Convert Forms' Pro-only
 *     `exportsubmissions` plugin, and reimplementing a paid feature inside a
 *     free add-on is not this project's call to make.
 *     list_convertforms_submissions covers legitimate per-form reads.
 */
final class Csmcpforjconvertformsfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics — what this install is and what it can do
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Diagnostics\ListFieldTypesTool::class,

		// Forms (#__convertforms)
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\ListFormsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\GetFormTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\CreateFormTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\UpdateFormTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\SetFormStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\DuplicateFormTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms\DeleteFormTool::class,

		// Fields (inside #__convertforms.params.fields)
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields\ListFormFieldsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields\AddFormFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields\UpdateFormFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields\DeleteFormFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields\ReorderFormFieldsTool::class,

		// Submissions (#__convertforms_conversions)
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions\ListSubmissionsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions\GetSubmissionTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions\UpdateSubmissionTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions\SetSubmissionStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions\DeleteSubmissionTool::class,

		// Tasks — the on-submission action engine (#__convertforms_tasks)
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\ListAppsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\ListTasksTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\GetTaskTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\CreateTaskTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\UpdateTaskTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\SetTaskStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\DeleteTaskTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\ListTaskHistoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks\ListConnectionsTool::class,

		// Reports (multi-table roll-ups)
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Reports\GetDashboardSummaryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Reports\GetFormSubmissionsSummaryTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
