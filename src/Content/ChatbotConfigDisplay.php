<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of Chatbot for BASE3 Framework.
 *
 * Chatbot extends the BASE3 framework with a modular API
 * foundation for flow-based chatbot services and interfaces.
 * It provides reusable components for AI-driven conversations.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/chatbot
 * https://github.com/ddbase3/Chatbot
 **********************************************************************/

namespace Chatbot\Content;

use Base3\Api\IClassMap;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Api\IChatbotService;
use JsonException;
use Throwable;

/**
 * Class ChatbotConfigDisplay
 *
 * Provides a reusable configuration display for one concrete chatbot instance.
 *
 * Important:
 * The surrounding integration layer, e.g. UIHook, RepositoryObject or
 * PageComponent, only needs to persist a stable instance id. The actual
 * chatbot configuration is stored here through ISettingsStore using:
 *
 * - group: the integration scope, e.g. uihk-chatbot, repo-chatbot, copg-chatbot
 * - name:  the concrete instance id, e.g. default, obj_123, pc_...
 *
 * This keeps the configuration independent from integration plugin config storage
 * and allows each copied object or page component to receive its own settings
 * dataset later.
 */
class ChatbotConfigDisplay implements IDisplay {

	protected const FORM_ACTION_SAVE = 'save';
	protected const LLM_SETTINGS_GROUP = 'service-llm';
	protected const CHAT_LLM_RESOURCE_ID = 'chatllm';
	protected const CHAT_LLM_RESOURCE_TYPE = 'configuredchatmodelagentresource';

	protected array $data = [];

	protected array $messages = [];

	protected ?array $postedValues = null;

	public function __construct(
		private readonly IMvcView $view,
		private readonly IRequest $request,
		private readonly ISettingsStore $settingsStore,
		private readonly ILinkTargetService $linkTargetService,
		private readonly IClassMap $classMap
	) {}

	public static function getName(): string {
		return 'chatbotconfigdisplay';
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------

	public function getOutput(string $out = 'html', bool $final = false): string {
		$out = strtolower(trim($out));

		if ($out === 'json') {
			return $this->getJsonOutput($final);
		}

		if ($out !== 'html') {
			return '';
		}

		$context = $this->getContext(false);

		/*
		 * Fallback for browsers without JavaScript or integrations explicitly
		 * using classic POST. The default UI saves through AJAX and prevents this
		 * full-page POST in the browser.
		 */
		if ($this->isSaveRequest($context)) {
			$this->handleSave($context);
		}

		$values = $this->postedValues ?? $this->settingsToViewValues(
			$this->loadSettings($context)
		);

		$this->view->setPath(DIR_PLUGIN . 'Chatbot');
		$this->view->setTemplate('Content/ChatbotConfigDisplay.php');

		$this->view->assign('title', $context['title']);
		$this->view->assign('description', $context['description']);
		$this->view->assign('group', $context['group']);
		$this->view->assign('name', $context['name']);
		$this->view->assign('form_id', $context['form_id']);
		$this->view->assign('form_action', $context['form_action']);
		$this->view->assign('submit_label', $context['submit_label']);
		$this->view->assign('mode', $context['mode']);
		$this->view->assign('save_mode', $context['save_mode']);
		$this->view->assign('save_url', $context['save_url']);
		$this->view->assign('render_form', $context['render_form']);
		$this->view->assign('values', $values);
		$this->view->assign('service_options', $this->listChatbotServiceOptions($context));
		$this->view->assign('llm_options', $this->listLlmOptions());
		$this->view->assign('messages', $this->messages);

		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'Configure one chatbot instance and store its settings through ISettingsStore.';
	}

	public function setData($data) {
		$this->data = is_array($data) ? $data : [];
		$this->messages = [];
		$this->postedValues = null;
	}

	// ---------------------------------------------------------------------
	// JSON endpoint
	// ---------------------------------------------------------------------

	protected function getJsonOutput(bool $final): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		$action = trim((string) $this->request->request('action', ''));

		if ($action === '') {
			$action = trim((string) $this->request->request('chatbot_config_action', ''));
		}

		if ($action !== self::FORM_ACTION_SAVE) {
			return $this->jsonError('Unknown action.');
		}

		$context = $this->getContext(true);

		if (!$this->isSaveRequest($context)) {
			return $this->jsonError('Configuration identity does not match.');
		}

		$result = $this->saveSettingsFromRequest($context);

		return $this->jsonResponse($result['success'], [
			'messages' => $this->messages,
			'values' => $this->postedValues ?? $this->settingsToViewValues($this->loadSettings($context))
		]);
	}

	protected function jsonResponse(bool $success, array $data = []): string {
		$json = json_encode(array_merge([
			'status' => $success ? 'ok' : 'error',
			'success' => $success
		], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($json) ? $json : '{"status":"error","success":false}';
	}

	protected function jsonError(string $message): string {
		$this->messages = [[
			'type' => 'danger',
			'text' => $message
		]];

		return $this->jsonResponse(false, [
			'messages' => $this->messages
		]);
	}

	// ---------------------------------------------------------------------
	// Context
	// ---------------------------------------------------------------------

	/**
	 * Returns the fixed storage context for the rendered configuration form.
	 *
	 * The group/name pair is not trusted from POST data during normal HTML
	 * rendering. JSON endpoint calls may derive the context from the posted
	 * hidden fields because no surrounding integration object calls setData().
	 */
	protected function getContext(bool $allowRequestContext): array {
		$group = trim((string) ($this->data['group'] ?? ''));
		$name = trim((string) ($this->data['name'] ?? ''));

		if ($allowRequestContext && $group === '') {
			$group = trim((string) $this->request->request('chatbot_config_group', ''));
		}

		if ($allowRequestContext && $name === '') {
			$name = trim((string) $this->request->request('chatbot_config_name', ''));
		}

		if ($group === '') {
			$group = 'chatbot';
		}

		if ($name === '') {
			$name = 'default';
		}

		$title = trim((string) ($this->data['title'] ?? 'Chatbot Configuration'));
		if ($title === '') {
			$title = 'Chatbot Configuration';
		}

		$description = trim((string) ($this->data['description'] ?? 'Configure the selected chatbot instance.'));
		$submitLabel = trim((string) ($this->data['submit_label'] ?? 'Save'));

		if ($submitLabel === '') {
			$submitLabel = 'Save';
		}

		$mode = $this->normalizeEnum(
			(string) ($this->data['mode'] ?? 'standalone'),
			['standalone', 'embedded'],
			'standalone'
		);

		$saveMode = $this->normalizeEnum(
			(string) ($this->data['save_mode'] ?? 'ajax'),
			['ajax', 'post'],
			'ajax'
		);

		$renderForm = $mode !== 'embedded';

		if (array_key_exists('render_form', $this->data)) {
			$renderForm = $this->toBool($this->data['render_form']);
		}

		return [
			'group' => $group,
			'name' => $name,
			'title' => $title,
			'description' => $description,
			'submit_label' => $submitLabel,
			'mode' => $mode,
			'save_mode' => $saveMode,
			'render_form' => $renderForm,
			'form_id' => 'base3_chatbot_config_' . md5($group . '/' . $name),
			'form_action' => (string) ($this->data['form_action'] ?? ($_SERVER['REQUEST_URI'] ?? '')),
			'save_url' => $this->getSaveUrl()
		];
	}

	protected function getSaveUrl(): string {
		$saveUrl = trim((string) ($this->data['save_url'] ?? ''));

		if ($saveUrl !== '') {
			return $saveUrl;
		}

		return $this->linkTargetService->getLink(
			[
				'name' => self::getName(),
				'out' => 'json'
			],
			[
				'action' => self::FORM_ACTION_SAVE
			]
		);
	}

	// ---------------------------------------------------------------------
	// Save handling
	// ---------------------------------------------------------------------

	protected function isSaveRequest(array $context): bool {
		if ((string) $this->request->request('chatbot_config_action') !== self::FORM_ACTION_SAVE) {
			return false;
		}

		$postedGroup = trim((string) $this->request->request('chatbot_config_group'));
		$postedName = trim((string) $this->request->request('chatbot_config_name'));

		return $postedGroup === $context['group'] && $postedName === $context['name'];
	}

	protected function handleSave(array $context): void {
		$this->saveSettingsFromRequest($context);
	}

	protected function saveSettingsFromRequest(array $context): array {
		$errors = [];
		$settings = $this->getPostedSettings($errors);
		$this->postedValues = $this->getPostedViewValues();

		if ($errors !== []) {
			foreach ($errors as $error) {
				$this->addMessage('danger', $error);
			}

			return [
				'success' => false
			];
		}

		try {
			$this->settingsStore->set($context['group'], $context['name'], $settings);
			$this->settingsStore->save();

			$this->postedValues = $this->settingsToViewValues($settings);
			$this->addMessage('success', 'Settings saved.');

			return [
				'success' => true
			];
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'Settings could not be saved: ' . $e->getMessage());

			return [
				'success' => false
			];
		}
	}

	protected function getPostedSettings(array &$errors): array {
		$service = $this->normalizeTechnicalKey((string) $this->request->request('service'));

		if ($service === '') {
			$errors[] = 'Please select a chatbot service.';
		} elseif (!$this->chatbotServiceExists($service)) {
			$errors[] = 'Selected chatbot service does not exist: ' . $service;
		}

		$reference = $this->decodeReferenceInput(
			trim((string) $this->request->request('reference')),
			$errors
		);

		$basePrompts = $this->normalizeBasePromptsInput(
			$this->request->request('base_prompts', [])
		);

		$agentFlow = $this->decodeConfigJsonInput(
			trim((string) $this->request->request('agent_flow')),
			'AgentFlow configuration',
			$errors
		);

		$llm = $this->normalizeTechnicalKey((string) $this->request->request('llm'));

		if ($llm !== '' && !$this->llmExists($llm)) {
			$errors[] = 'Selected LLM does not exist in settings group "' . self::LLM_SETTINGS_GROUP . '": ' . $llm;
		}

		if ($errors === [] && $llm !== '') {
			$agentFlow = $this->applyLlmToAgentFlow($agentFlow, $llm);
		}

		return $this->normalizeSettings([
			'service' => $service,
			'llm' => $llm,
			'default_lang' => trim((string) $this->request->request('default_lang')),
			'use_markdown' => $this->request->request('use_markdown') !== null,
			'use_icons' => $this->request->request('use_icons') !== null,
			'use_voice' => $this->request->request('use_voice') !== null,
			'use_threads' => $this->request->request('use_threads') !== null,
			'transport_mode' => $this->normalizeEnum(
				(string) $this->request->request('transport_mode'),
				['auto', 'sse', 'websocket', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string) $this->request->request('reference_mode'),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference' => $reference,
			'reference_provider' => trim((string) $this->request->request('reference_provider')),
			'system_prompt' => $this->normalizeTextBlock((string) $this->request->request('system_prompt')),
			'base_prompts' => $basePrompts,
			'agent_flow' => $agentFlow
		]);
	}

	protected function getPostedViewValues(): array {
		return [
			'service' => $this->normalizeTechnicalKey((string) $this->request->request('service')),
			'llm' => $this->normalizeTechnicalKey((string) $this->request->request('llm')),
			'default_lang' => trim((string) $this->request->request('default_lang')),
			'use_markdown' => $this->request->request('use_markdown') !== null,
			'use_icons' => $this->request->request('use_icons') !== null,
			'use_voice' => $this->request->request('use_voice') !== null,
			'use_threads' => $this->request->request('use_threads') !== null,
			'transport_mode' => $this->normalizeEnum(
				(string) $this->request->request('transport_mode'),
				['auto', 'sse', 'websocket', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string) $this->request->request('reference_mode'),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference_json' => (string) $this->request->request('reference'),
			'reference_provider' => trim((string) $this->request->request('reference_provider')),
			'system_prompt' => $this->normalizeTextBlock((string) $this->request->request('system_prompt')),
			'base_prompts' => $this->normalizeBasePromptsInput($this->request->request('base_prompts', [])),
			'agent_flow_json' => (string) $this->request->request('agent_flow')
		];
	}

	protected function decodeReferenceInput(string $raw, array &$errors): array {
		return $this->decodeConfigJsonInput($raw, 'Reference', $errors);
	}

	protected function decodeConfigJsonInput(string $raw, string $label, array &$errors): array {
		if ($raw === '') {
			return [];
		}

		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e) {
			$errors[] = $label . ' must be valid JSON: ' . $e->getMessage();
			return [];
		}

		if (!is_array($decoded)) {
			$errors[] = $label . ' must decode to a JSON object or array.';
			return [];
		}

		return $decoded;
	}

	protected function addMessage(string $type, string $text): void {
		$this->messages[] = [
			'type' => $type,
			'text' => $text
		];
	}

	// ---------------------------------------------------------------------
	// Chatbot service options
	// ---------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function listChatbotServiceOptions(array $context): array {
		$rows = [];

		try {
			$services = $this->classMap->getInstancesByInterface(IChatbotService::class);
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'Chatbot services could not be loaded: ' . $e->getMessage());
			return [];
		}

		foreach ($services as $service) {
			if (!$service instanceof IChatbotService) {
				continue;
			}

			$class = $service::class;
			$id = $this->normalizeTechnicalKey((string) $class::getName());

			if ($id === '') {
				continue;
			}

			$label = trim((string) $class::getServiceLabel());

			if ($label === '') {
				$label = $id;
			}

			$params = [
				'config_group' => $context['group'],
				'config_name' => $context['name']
			];

			try {
				$url = $this->linkTargetService->getLink(
					[
						'name' => $id
					],
					$params
				);
			}
			catch (Throwable) {
				$url = '';
			}

			$rows[$id] = [
				'id' => $id,
				'label' => $label,
				'description' => trim((string) $class::getServiceDescription()),
				'class' => $class,
				'url' => $url
			];
		}

		$rows = array_values($rows);

		usort($rows, static function(array $a, array $b): int {
			$aSort = trim((string) ($a['label'] ?? ''));
			$bSort = trim((string) ($b['label'] ?? ''));

			if ($aSort === '') {
				$aSort = (string) ($a['id'] ?? '');
			}

			if ($bSort === '') {
				$bSort = (string) ($b['id'] ?? '');
			}

			$cmp = strcasecmp($aSort, $bSort);

			if ($cmp !== 0) {
				return $cmp;
			}

			return strcasecmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
		});

		return $rows;
	}

	protected function chatbotServiceExists(string $id): bool {
		if ($id === '') {
			return false;
		}

		try {
			$service = $this->classMap->getInstanceByInterfaceName(IChatbotService::class, $id);
		}
		catch (Throwable) {
			return false;
		}

		return $service instanceof IChatbotService;
	}

	// ---------------------------------------------------------------------
	// LLM options
	// ---------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function listLlmOptions(): array {
		$rows = [];

		try {
			$group = $this->settingsStore->getGroup(self::LLM_SETTINGS_GROUP);
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'LLMs could not be loaded: ' . $e->getMessage());
			return [];
		}

		if (!is_array($group)) {
			return [];
		}

		foreach ($group as $id => $settings) {
			if (!is_string($id) || $id === '' || !is_array($settings)) {
				continue;
			}

			$rows[] = $this->normalizeLlmOption($id, $settings);
		}

		usort($rows, static function(array $a, array $b): int {
			$aSort = trim((string) ($a['label'] ?? ''));
			$bSort = trim((string) ($b['label'] ?? ''));

			if ($aSort === '') {
				$aSort = (string) ($a['id'] ?? '');
			}

			if ($bSort === '') {
				$bSort = (string) ($b['id'] ?? '');
			}

			$cmp = strcasecmp($aSort, $bSort);

			if ($cmp !== 0) {
				return $cmp;
			}

			return strcasecmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
		});

		return $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function normalizeLlmOption(string $id, array $settings): array {
		$label = trim((string) ($settings['name'] ?? ($settings['label'] ?? '')));

		if ($label === '') {
			$label = $id;
		}

		return [
			'id' => $id,
			'label' => $label,
			'model' => trim((string) ($settings['model'] ?? '')),
			'driver' => trim((string) ($settings['driver'] ?? '')),
			'connection' => trim((string) ($settings['connection'] ?? ($settings['provider'] ?? ''))),
			'enabled' => $this->toBool($settings['enabled'] ?? true)
		];
	}

	protected function llmExists(string $id): bool {
		if ($id === '') {
			return false;
		}

		try {
			$settings = $this->settingsStore->get(self::LLM_SETTINGS_GROUP, $id, []);
		}
		catch (Throwable) {
			return false;
		}

		return is_array($settings) && $settings !== [];
	}

	// ---------------------------------------------------------------------
	// AgentFlow LLM binding
	// ---------------------------------------------------------------------

	protected function applyLlmToAgentFlow(array $agentFlow, string $llm): array {
		if ($llm === '') {
			return $agentFlow;
		}

		if (!isset($agentFlow['resources']) || !is_array($agentFlow['resources'])) {
			$agentFlow['resources'] = [];
		}

		$resources = $agentFlow['resources'];
		$resourceIndex = $this->findChatLlmResourceIndex($resources);

		$resource = [
			'id' => self::CHAT_LLM_RESOURCE_ID,
			'type' => self::CHAT_LLM_RESOURCE_TYPE,
			'config' => [
				'service' => [
					'mode' => 'fixed',
					'value' => $llm
				]
			]
		];

		if ($resourceIndex !== null && isset($resources[$resourceIndex]) && is_array($resources[$resourceIndex])) {
			$resource = array_merge($resources[$resourceIndex], $resource);
			$resource['config'] = is_array($resources[$resourceIndex]['config'] ?? null)
				? $resources[$resourceIndex]['config']
				: [];
			$resource['config']['service'] = [
				'mode' => 'fixed',
				'value' => $llm
			];
			$resource['type'] = self::CHAT_LLM_RESOURCE_TYPE;
		}

		if ($resourceIndex === null) {
			$resources[] = $resource;
		}
		else {
			$resources[$resourceIndex] = $resource;
		}

		$agentFlow['resources'] = array_values($resources);

		return $agentFlow;
	}

	protected function findChatLlmResourceIndex(array $resources): ?int {
		$fallback = null;

		foreach ($resources as $index => $resource) {
			if (!is_array($resource)) {
				continue;
			}

			if ((string) ($resource['id'] ?? '') === self::CHAT_LLM_RESOURCE_ID) {
				return (int) $index;
			}

			if ($fallback === null && (string) ($resource['type'] ?? '') === self::CHAT_LLM_RESOURCE_TYPE) {
				$fallback = (int) $index;
			}
		}

		return $fallback;
	}

	protected function extractLlmFromAgentFlow(array $agentFlow): string {
		$resources = $agentFlow['resources'] ?? null;

		if (!is_array($resources)) {
			return '';
		}

		$resourceIndex = $this->findChatLlmResourceIndex($resources);

		if ($resourceIndex === null || !isset($resources[$resourceIndex]) || !is_array($resources[$resourceIndex])) {
			return '';
		}

		$resource = $resources[$resourceIndex];
		$service = $resource['config']['service'] ?? null;

		if (!is_array($service)) {
			return '';
		}

		if ((string) ($service['mode'] ?? '') !== 'fixed') {
			return '';
		}

		return $this->normalizeTechnicalKey((string) ($service['value'] ?? ''));
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	protected function loadSettings(array $context): array {
		try {
			return $this->normalizeSettings(
				$this->settingsStore->get($context['group'], $context['name'], $this->getDefaultSettings())
			);
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'Settings could not be loaded: ' . $e->getMessage());
			return $this->getDefaultSettings();
		}
	}

	protected function getDefaultSettings(): array {
		return [
			'service' => 'chatbotservice',

			// Guided server-side selections.
			'llm' => '',

			// Client-side UI feature flags.
			'use_markdown' => true,
			'use_icons' => true,
			'use_voice' => true,
			'use_threads' => true,

			// Client-side transport and reference behavior.
			'transport_mode' => 'auto',
			'reference_mode' => 'url',
			'reference' => [],
			'reference_provider' => '',

			// Voice defaults.
			'default_lang' => 'auto',

			// Server-side prompt and flow configuration.
			// These values are intentionally not meant for client-side rendering.
			// The chatbot service will load them by config_group/config_name later.
			'system_prompt' => '',
			'base_prompts' => [],
			'agent_flow' => []
		];
	}

	protected function normalizeSettings(array $settings): array {
		$defaults = $this->getDefaultSettings();

		$agentFlow = is_array($settings['agent_flow'] ?? null) ? $settings['agent_flow'] : $defaults['agent_flow'];
		$llm = $this->normalizeTechnicalKey((string) ($settings['llm'] ?? ''));

		if ($llm === '') {
			$llm = $this->extractLlmFromAgentFlow($agentFlow);
		}

		$service = $this->normalizeTechnicalKey((string) ($settings['service'] ?? $defaults['service']));

		if ($service === '') {
			$service = (string) $defaults['service'];
		}

		return [
			'service' => $service,
			'llm' => $llm,
			'use_markdown' => $this->toBool($settings['use_markdown'] ?? $defaults['use_markdown']),
			'use_icons' => $this->toBool($settings['use_icons'] ?? $defaults['use_icons']),
			'use_voice' => $this->toBool($settings['use_voice'] ?? $defaults['use_voice']),
			'use_threads' => $this->toBool($settings['use_threads'] ?? $defaults['use_threads']),
			'transport_mode' => $this->normalizeEnum(
				(string) ($settings['transport_mode'] ?? $defaults['transport_mode']),
				['auto', 'sse', 'websocket', 'rest'],
				(string) $defaults['transport_mode']
			),
			'reference_mode' => $this->normalizeEnum(
				(string) ($settings['reference_mode'] ?? $defaults['reference_mode']),
				['none', 'url', 'custom', 'provider'],
				(string) $defaults['reference_mode']
			),
			'reference' => is_array($settings['reference'] ?? null) ? $settings['reference'] : $defaults['reference'],
			'reference_provider' => trim((string) ($settings['reference_provider'] ?? $defaults['reference_provider'])),
			'default_lang' => trim((string) ($settings['default_lang'] ?? $defaults['default_lang'])),
			'system_prompt' => $this->normalizeTextBlock((string) ($settings['system_prompt'] ?? $defaults['system_prompt'])),
			'base_prompts' => $this->normalizeBasePromptsInput($settings['base_prompts'] ?? $defaults['base_prompts']),
			'agent_flow' => $agentFlow
		];
	}

	protected function settingsToViewValues(array $settings): array {
		$settings = $this->normalizeSettings($settings);

		return [
			'service' => $settings['service'],
			'llm' => $settings['llm'],
			'use_markdown' => $settings['use_markdown'],
			'use_icons' => $settings['use_icons'],
			'use_voice' => $settings['use_voice'],
			'use_threads' => $settings['use_threads'],
			'transport_mode' => $settings['transport_mode'],
			'reference_mode' => $settings['reference_mode'],
			'reference_json' => $this->formatReferenceJson($settings['reference']),
			'reference_provider' => $settings['reference_provider'],
			'default_lang' => $settings['default_lang'],
			'system_prompt' => $settings['system_prompt'],
			'base_prompts' => $settings['base_prompts'],
			'agent_flow_json' => $this->formatConfigJson($settings['agent_flow'], '{}')
		];
	}

	protected function normalizeBasePromptsInput(mixed $value): array {
		if (is_string($value)) {
			$value = trim($value);

			if ($value === '') {
				return [];
			}

			if (substr($value, 0, 1) === '[') {
				try {
					$decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

					if (is_array($decoded)) {
						return $this->normalizeBasePromptsInput($decoded);
					}
				}
				catch (JsonException) {}
			}

			return [$this->normalizeTextBlock($value)];
		}

		if (!is_array($value)) {
			return [];
		}

		$prompts = [];

		foreach ($value as $prompt) {
			if (is_array($prompt) || is_object($prompt)) {
				continue;
			}

			$prompt = trim($this->normalizeTextBlock((string) $prompt));

			if ($prompt === '') {
				continue;
			}

			$prompts[] = $prompt;
		}

		return array_values($prompts);
	}

	protected function formatReferenceJson(array $reference): string {
		return $this->formatConfigJson($reference, '{}');
	}

	protected function formatConfigJson(array $data, string $emptyJson): string {
		if ($data === []) {
			return $emptyJson;
		}

		try {
			return json_encode(
				$data,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);
		}
		catch (JsonException) {
			return $emptyJson;
		}
	}

	protected function normalizeEnum(string $value, array $allowed, string $default): string {
		return in_array($value, $allowed, true) ? $value : $default;
	}

	protected function normalizeTextBlock(string $value): string {
		return str_replace(["\r\n", "\r"], "\n", $value);
	}

	protected function normalizeTechnicalKey(string $value): string {
		$value = strtolower(trim($value));

		return preg_replace('/[^a-z0-9._-]+/', '', $value) ?? '';
	}

	protected function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value === 1;
		}

		$value = strtolower(trim((string) $value));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}
}
