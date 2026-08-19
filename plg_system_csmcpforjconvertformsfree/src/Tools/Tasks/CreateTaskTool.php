<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

final class CreateTaskTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'create_convertforms_task'; }

	public function getDescription(): string
	{
		return 'Add a Task to a Convert Forms form — most commonly an email '
			. 'notification sent when the form is submitted (app="email", '
			. 'action="email"). Options accept Smart Tags: {field.<name>} for a '
			. 'submitted value, {submission.id}, {form.name}. Call '
			. 'list_convertforms_apps first to see which apps this install has. On '
			. 'the free edition each app is limited to one task per form, which this '
			. 'tool checks before writing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id', 'app'],
			'properties' => [
				'form_id' => ['type' => 'integer'],
				'app'     => ['type' => 'string', 'description' => 'App element name, e.g. "email" or "acymailing".'],
				'action'  => ['type' => 'string', 'description' => 'Action to run. Defaults to the app\'s only action when it has exactly one.'],
				'title'   => ['type' => 'string', 'description' => 'Admin-facing task name. Defaults to a description of the app + action.'],
				'options' => [
					'type'        => 'object',
					'description' => 'App-specific settings. For app="email": recipient, subject, body, from_name, from_email, reply_to, attachments.',
				],
				'conditions' => [
					'type'        => 'object',
					'description' => 'Optional condition sets gating whether the task runs. Omit to always run.',
				],
				'connection_id' => ['type' => 'integer', 'description' => 'Stored connection to use, for apps that need credentials.'],
				'trigger'    => ['type' => 'string', 'description' => 'Defaults to onNewSubmission, the only trigger Convert Forms currently fires.'],
				'enabled'    => ['type' => 'boolean', 'description' => 'Default true.'],
				'silentfail' => ['type' => 'boolean', 'description' => 'Swallow failures rather than aborting the submission. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('tasks')) {
			return ToolResult::error(
				'#__convertforms_tasks does not exist. The Tasks engine arrived in '
				. 'Convert Forms 5.0 — this site is on ' . ($this->cfVersion() ?? 'an older release') . '.'
			);
		}

		$formId = $this->requirePositiveInt($arguments, 'form_id');
		$form   = $this->cfFetchForm($formId);

		if ($form === null) {
			return ToolResult::error('Convert Forms form ' . $formId . ' not found.');
		}

		$app = strtolower($this->requireString($arguments, 'app'));

		if (!$this->appInstalled($app)) {
			return ToolResult::error(
				'No convertformsapps plugin named "' . $app . '" is installed on this site'
				. ($this->cfIsPro() ? '' : ' (this is the free edition; most apps are Pro-only)')
				. '. Use list_convertforms_apps to see what is available.'
			);
		}

		if (!PluginHelper::isEnabled('convertformsapps', $app)) {
			return ToolResult::error(
				'The convertformsapps/' . $app . ' plugin is installed but disabled, so a task '
				. 'using it would never run. Enable it first (set_extension_enabled), then retry.'
			);
		}

		// Free edition caps each app at one task per form. Writing a second row
		// succeeds at the SQL level but the extra task silently never executes,
		// so refuse instead of leaving dead configuration behind.
		if (!$this->cfIsPro()) {
			$existing = $this->countTasks($formId, $app);
			if ($existing > 0) {
				return ToolResult::error(
					'Form ' . $formId . ' already has a "' . $app . '" task, and the free edition '
					. 'of Convert Forms allows only one task per app per form '
					. '(ConvertForms\Tasks\LimitAppUsage). A second one would be saved but never run. '
					. 'Update the existing task with update_convertforms_task instead.'
				);
			}
		}

		$action = strtolower(trim((string) ($arguments['action'] ?? '')));
		if ($action === '') {
			$action = $this->defaultAction($app);
			if ($action === null) {
				return ToolResult::error(
					'"' . $app . '" exposes more than one action, so "action" is required. '
					. 'Use list_convertforms_apps to see them.'
				);
			}
		}

		$options    = $arguments['options'] ?? [];
		$options    = is_array($options) ? $options : [];
		$conditions = $arguments['conditions'] ?? [];
		$conditions = is_array($conditions) ? $conditions : [];

		if ($app === 'email') {
			$problem = $this->validateEmailOptions($options);
			if ($problem !== null) {
				return ToolResult::error($problem);
			}
		}

		$title = trim((string) ($arguments['title'] ?? ''));
		if ($title === '') {
			$title = ucfirst($app) . ' — ' . $action;
		}

		$data = [
			'id'            => null,
			'form_id'       => $formId,
			'title'         => $title,
			'state'         => (!array_key_exists('enabled', $arguments) || $arguments['enabled']) ? 1 : 0,
			'action'        => $action,
			'app'           => $app,
			'trigger'       => (string) ($arguments['trigger'] ?? 'onNewSubmission'),
			'connection_id' => array_key_exists('connection_id', $arguments) ? (int) $arguments['connection_id'] : null,
			'options'       => $options,
			'conditions'    => $conditions,
			'silentfail'    => !empty($arguments['silentfail']) ? 1 : 0,
			'ordering'      => $this->nextOrdering($formId),
		];

		$id = $this->saveTask($data);

		if ($id <= 0) {
			return ToolResult::error('Task create failed — the row was not written.');
		}

		return ToolResult::json([
			'ok'      => true,
			'id'      => $id,
			'form_id' => $formId,
			'app'     => $app,
			'action'  => $action,
			'title'   => $title,
			'enabled' => $data['state'] === 1,
			'note'    => 'This task runs on new submissions of form ' . $formId . '. '
				. 'Submit the form once to verify, then check list_convertforms_task_history.',
		]);
	}

	private function appInstalled(string $app): bool
	{
		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('convertformsapps'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote($app));

		return (int) $this->db->setQuery($q)->loadResult() > 0;
	}

	private function countTasks(int $formId, string $app): int
	{
		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('tasks')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId)
			->where($this->db->quoteName('app') . ' = ' . $this->db->quote($app));

		return (int) $this->db->setQuery($q)->loadResult();
	}

	/** The app's action when it has exactly one; null when ambiguous. */
	private function defaultAction(string $app): ?string
	{
		// The two apps that ship in the free edition each have a single action.
		$known = ['email' => 'email', 'acymailing' => 'subscribe'];

		if (isset($known[$app])) {
			return $known[$app];
		}

		if (!class_exists('\ConvertForms\Tasks\Apps')) {
			return null;
		}

		try {
			$instance = \ConvertForms\Tasks\Apps::getApp($app);
			if (!is_object($instance)) {
				return null;
			}

			$actions = [];
			foreach (get_class_methods($instance) as $method) {
				if (stripos($method, 'action') === 0 && strlen($method) > 6) {
					$actions[] = strtolower(substr($method, 6));
				}
			}
			$actions = array_values(array_unique($actions));

			return count($actions) === 1 ? $actions[0] : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Catch the email-task mistakes that otherwise only show up as a submission
	 * that silently never sends: no recipient, or no body at all.
	 */
	private function validateEmailOptions(array $options): ?string
	{
		$recipient = trim((string) ($options['recipient'] ?? $options['to'] ?? ''));

		if ($recipient === '') {
			return 'An email task needs a "recipient" in options — the address to notify. '
				. 'A Smart Tag such as {field.email} is allowed if you want to reply to the submitter.';
		}

		$body = trim((string) ($options['body'] ?? ''));
		if ($body === '') {
			return 'An email task needs a "body" in options. Use {all_fields} to include every '
				. 'submitted value, or reference fields individually as {field.<name>}.';
		}

		return null;
	}

	private function nextOrdering(int $formId): int
	{
		$q = $this->db->getQuery(true)
			->select('MAX(' . $this->db->quoteName('ordering') . ')')
			->from($this->db->quoteName($this->cfTableName('tasks')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId);

		return ((int) $this->db->setQuery($q)->loadResult()) + 1;
	}

	/**
	 * Persist through ConvertFormsTableTask so its check() applies the vendor's
	 * own encoding rules (options and conditions json_encoded, booleans coerced,
	 * created/created_by stamped). Falls back to ModelTasks::save() if the Table
	 * class cannot be loaded.
	 *
	 * @param array<string, mixed> $data
	 */
	private function saveTask(array $data): int
	{
		$table = $this->cfTable('Task');

		if ($table !== null) {
			if (!$table->bind($data)) {
				throw new \RuntimeException('Task bind failed: ' . $table->getError());
			}
			if (!$table->check()) {
				throw new \RuntimeException('Task check failed: ' . $table->getError());
			}
			if (!$table->store()) {
				throw new \RuntimeException('Task store failed: ' . $table->getError());
			}

			return (int) $table->id;
		}

		if (class_exists('\ConvertForms\Tasks\ModelTasks')) {
			\ConvertForms\Tasks\ModelTasks::save($data);

			$q = $this->db->getQuery(true)
				->select('MAX(' . $this->db->quoteName('id') . ')')
				->from($this->db->quoteName($this->cfTableName('tasks')));

			return (int) $this->db->setQuery($q)->loadResult();
		}

		throw new \RuntimeException('Neither ConvertFormsTableTask nor ConvertForms\Tasks\ModelTasks could be loaded.');
	}
}
