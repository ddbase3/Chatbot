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
use AssistantFoundation\Api\IAgentConfigFormService;
use AssistantFoundation\Api\IAgentRuntimeRegistry;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use Throwable;

/**
 * Class ChatbotConfigDisplay
 *
 * Provides a reusable configuration display for one concrete chatbot instance.
 * Legacy service settings are migrated to one explicit chatbot backend value.
 */
class ChatbotConfigDisplay implements IDisplay {

	protected const FORM_ACTION_SAVE = 'save';
	protected const BACKEND_SERVICE_PREFIX = 'service:';
	protected const BACKEND_RUNTIME_PREFIX = 'runtime:';
	protected const AGENT_CHATBOT_SERVICE = 'chatbotservice';

	protected array $data = [];

	protected array $messages = [];

	protected ?array $postedValues = null;

	protected ?array $postedSettings = null;

	public function __construct(
		private readonly IMvcView $view,
		private readonly IRequest $request,
		private readonly ISettingsStore $settingsStore,
		private readonly ILinkTargetService $linkTargetService,
		private readonly IClassMap $classMap,
		private readonly IAgentConfigFormService $agentConfigFormService,
		private readonly IAgentRuntimeRegistry $agentRuntimeRegistry,
		private readonly IAgentRuntimeSelector $agentRuntimeSelector
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

		if ($this->isSaveRequest($context)) {
			$this->handleSave($context);
		}

		$settings = $this->loadSettings($context);
		$values = $this->postedValues ?? $this->settingsToViewValues($settings);

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
		$this->view->assign('backend_options', $this->listChatbotBackendOptions($context));
		$this->view->assign('messages', $this->messages);

		$runtimeId = $this->getRuntimeIdFromBackend((string)($values['chatbot_backend'] ?? ''));
		$runtimeActive = $runtimeId !== '';
		$runtimeSettings = $this->postedSettings ?? $settings;
		if ($runtimeActive) {
			$runtimeSettings['agent_runtime'] = $runtimeId;
		}

		$this->agentConfigFormService->assignViewData($this->view, $runtimeSettings, [
			'form_id' => $context['form_id'],
			'selected_runtime' => $runtimeActive ? $runtimeId : $this->agentRuntimeSelector->getDefaultRuntimeId(),
			'show_runtime_selector' => false,
			'runtime_active' => $runtimeActive
		]);

		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'Configure one chatbot instance and store its settings through ISettingsStore.';
	}

	public function setData($data) {
		$this->data = is_array($data) ? $data : [];
		$this->messages = [];
		$this->postedValues = null;
		$this->postedSettings = null;
	}

	// ---------------------------------------------------------------------
	// JSON endpoint
	// ---------------------------------------------------------------------

	protected function getJsonOutput(bool $final): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		$action = trim((string)$this->request->request('action', ''));

		if ($action === '') {
			$action = trim((string)$this->request->request('chatbot_config_action', ''));
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

	protected function getContext(bool $allowRequestContext): array {
		$group = trim((string)($this->data['group'] ?? ''));
		$name = trim((string)($this->data['name'] ?? ''));

		if ($allowRequestContext && $group === '') {
			$group = trim((string)$this->request->request('chatbot_config_group', ''));
		}

		if ($allowRequestContext && $name === '') {
			$name = trim((string)$this->request->request('chatbot_config_name', ''));
		}

		if ($group === '') {
			$group = 'chatbot';
		}

		if ($name === '') {
			$name = 'default';
		}

		$title = trim((string)($this->data['title'] ?? 'Chatbot Configuration'));
		if ($title === '') {
			$title = 'Chatbot Configuration';
		}

		$description = trim((string)($this->data['description'] ?? 'Configure the selected chatbot instance.'));
		$submitLabel = trim((string)($this->data['submit_label'] ?? 'Save'));

		if ($submitLabel === '') {
			$submitLabel = 'Save';
		}

		$mode = $this->normalizeEnum(
			(string)($this->data['mode'] ?? 'standalone'),
			['standalone', 'embedded'],
			'standalone'
		);

		$saveMode = $this->normalizeEnum(
			(string)($this->data['save_mode'] ?? 'ajax'),
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
			'form_action' => (string)($this->data['form_action'] ?? ($_SERVER['REQUEST_URI'] ?? '')),
			'save_url' => $this->getSaveUrl()
		];
	}

	protected function getSaveUrl(): string {
		$saveUrl = trim((string)($this->data['save_url'] ?? ''));

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
		if ((string)$this->request->request('chatbot_config_action') !== self::FORM_ACTION_SAVE) {
			return false;
		}

		$postedGroup = trim((string)$this->request->request('chatbot_config_group'));
		$postedName = trim((string)$this->request->request('chatbot_config_name'));

		return $postedGroup === $context['group'] && $postedName === $context['name'];
	}

	protected function handleSave(array $context): void {
		$this->saveSettingsFromRequest($context);
	}

	protected function saveSettingsFromRequest(array $context): array {
		$errors = [];
		$settings = $this->getPostedSettings($errors);
		$this->postedSettings = $settings;
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
		$backend = $this->normalizeBackendId((string)$this->request->request('chatbot_backend'));
		$backendInfo = $this->validateBackend($backend, $errors);
		$reference = $this->decodeReferenceInput(
			trim((string)$this->request->request('reference')),
			$errors
		);
		$basePrompts = $this->normalizeBasePromptsInput(
			$this->request->request('base_prompts', [])
		);
		$settings = [
			'chatbot_backend' => $backend,
			'default_lang' => trim((string)$this->request->request('default_lang')),
			'use_markdown' => $this->request->request('use_markdown') !== null,
			'use_icons' => $this->request->request('use_icons') !== null,
			'use_voice' => $this->request->request('use_voice') !== null,
			'use_threads' => $this->request->request('use_threads') !== null,
			'transport_mode' => $this->normalizeEnum(
				(string)$this->request->request('transport_mode'),
				['auto', 'sse', 'websocket', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string)$this->request->request('reference_mode'),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference' => $reference,
			'reference_provider' => trim((string)$this->request->request('reference_provider')),
			'base_prompts' => $basePrompts
		];

		if (($backendInfo['type'] ?? '') === 'runtime') {
			$runtimeId = (string)$backendInfo['id'];
			$settings = array_merge(
				$settings,
				$this->agentConfigFormService->getPostedSettings($errors, $runtimeId)
			);
		}

		return $this->normalizeSettings($settings);
	}

	protected function getPostedViewValues(): array {
		$backend = $this->normalizeBackendId((string)$this->request->request('chatbot_backend'));
		$runtimeId = $this->getRuntimeIdFromBackend($backend);
		$values = [
			'chatbot_backend' => $backend,
			'default_lang' => trim((string)$this->request->request('default_lang')),
			'use_markdown' => $this->request->request('use_markdown') !== null,
			'use_icons' => $this->request->request('use_icons') !== null,
			'use_voice' => $this->request->request('use_voice') !== null,
			'use_threads' => $this->request->request('use_threads') !== null,
			'transport_mode' => $this->normalizeEnum(
				(string)$this->request->request('transport_mode'),
				['auto', 'sse', 'websocket', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string)$this->request->request('reference_mode'),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference_json' => (string)$this->request->request('reference'),
			'reference_provider' => trim((string)$this->request->request('reference_provider')),
			'base_prompts' => $this->normalizeBasePromptsInput($this->request->request('base_prompts', []))
		];

		if ($runtimeId !== '' && $this->agentRuntimeRegistry->hasRuntime($runtimeId)) {
			$values = array_merge(
				$values,
				$this->agentConfigFormService->getPostedViewValues($runtimeId)
			);
		}

		return $values;
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
	// Chatbot backend options
	// ---------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function listChatbotBackendOptions(array $context): array {
		$rows = [];

		try {
			$services = $this->classMap->getInstancesByInterface(IChatbotService::class);
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'Chatbot services could not be loaded: ' . $e->getMessage());
			$services = [];
		}

		foreach ($services as $service) {
			if (!$service instanceof IChatbotService) {
				continue;
			}

			$class = $service::class;
			$serviceId = $this->normalizeTechnicalKey((string)$class::getName());
			if ($serviceId === '' || $serviceId === self::AGENT_CHATBOT_SERVICE) {
				continue;
			}

			$label = trim((string)$class::getServiceLabel()) ?: $serviceId;
			$rows[] = [
				'id' => self::BACKEND_SERVICE_PREFIX . $serviceId,
				'label' => $label,
				'description' => trim((string)$class::getServiceDescription()),
				'url' => $this->buildServiceUrl($serviceId, $context)
			];
		}

		foreach ($this->agentRuntimeRegistry->getRuntimeOptions() as $runtimeOption) {
			$runtimeId = $this->normalizeTechnicalKey((string)($runtimeOption['id'] ?? ''));
			if ($runtimeId === '') {
				continue;
			}

			$rows[] = [
				'id' => self::BACKEND_RUNTIME_PREFIX . $runtimeId,
				'label' => trim((string)($runtimeOption['label'] ?? '')) ?: $runtimeId,
				'description' => trim((string)($runtimeOption['description'] ?? '')),
				'url' => $this->buildServiceUrl(self::AGENT_CHATBOT_SERVICE, $context)
			];
		}

		usort($rows, static function(array $left, array $right): int {
			$labelCompare = strcasecmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));

			return $labelCompare !== 0
				? $labelCompare
				: strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
		});

		return $rows;
	}

	protected function buildServiceUrl(string $serviceId, array $context): string {
		try {
			return $this->linkTargetService->getLink(
				['name' => $serviceId],
				[
					'config_group' => $context['group'],
					'config_name' => $context['name']
				]
			);
		}
		catch (Throwable) {
			return '';
		}
	}

	/** @param array<int,string> $errors @return array{type:string,id:string}|array{} */
	protected function validateBackend(string $backend, array &$errors): array {
		if ($backend === '') {
			$errors[] = 'Please select a chatbot backend.';
			return [];
		}

		$runtimeId = $this->getRuntimeIdFromBackend($backend);
		if ($runtimeId !== '') {
			if (!$this->agentRuntimeRegistry->hasRuntime($runtimeId)) {
				$errors[] = 'Selected agent runtime does not exist: ' . $runtimeId;
				return [];
			}

			return ['type' => 'runtime', 'id' => $runtimeId];
		}

		$serviceId = $this->getServiceIdFromBackend($backend);
		if ($serviceId === '' || !$this->chatbotServiceExists($serviceId)) {
			$errors[] = 'Selected chatbot service does not exist: ' . ($serviceId !== '' ? $serviceId : $backend);
			return [];
		}

		return ['type' => 'service', 'id' => $serviceId];
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
		$runtimeId = $this->agentRuntimeSelector->getDefaultRuntimeId();

		return array_merge([
			'chatbot_backend' => self::BACKEND_RUNTIME_PREFIX . $runtimeId,
			'use_markdown' => true,
			'use_icons' => true,
			'use_voice' => true,
			'use_threads' => true,
			'transport_mode' => 'auto',
			'reference_mode' => 'url',
			'reference' => [],
			'reference_provider' => '',
			'default_lang' => 'auto',
			'base_prompts' => []
		], $this->agentConfigFormService->getDefaultSettings());
	}

	protected function normalizeSettings(array $settings): array {
		$defaults = $this->getDefaultSettings();
		$backend = $this->resolveBackendFromSettings($settings);
		$normalized = [
			'chatbot_backend' => $backend,
			'use_markdown' => $this->toBool($settings['use_markdown'] ?? $defaults['use_markdown']),
			'use_icons' => $this->toBool($settings['use_icons'] ?? $defaults['use_icons']),
			'use_voice' => $this->toBool($settings['use_voice'] ?? $defaults['use_voice']),
			'use_threads' => $this->toBool($settings['use_threads'] ?? $defaults['use_threads']),
			'transport_mode' => $this->normalizeEnum(
				(string)($settings['transport_mode'] ?? $defaults['transport_mode']),
				['auto', 'sse', 'websocket', 'rest'],
				(string)$defaults['transport_mode']
			),
			'reference_mode' => $this->normalizeEnum(
				(string)($settings['reference_mode'] ?? $defaults['reference_mode']),
				['none', 'url', 'custom', 'provider'],
				(string)$defaults['reference_mode']
			),
			'reference' => is_array($settings['reference'] ?? null) ? $settings['reference'] : $defaults['reference'],
			'reference_provider' => trim((string)($settings['reference_provider'] ?? $defaults['reference_provider'])),
			'default_lang' => trim((string)($settings['default_lang'] ?? $defaults['default_lang'])),
			'base_prompts' => $this->normalizeBasePromptsInput($settings['base_prompts'] ?? $defaults['base_prompts'])
		];

		$runtimeId = $this->getRuntimeIdFromBackend($backend);
		if ($runtimeId !== '') {
			$runtimeSettings = $settings;
			$runtimeSettings['agent_runtime'] = $runtimeId;
			$normalized = array_merge(
				$normalized,
				$this->agentConfigFormService->normalizeSettings($runtimeSettings)
			);
		}

		return $normalized;
	}

	protected function settingsToViewValues(array $settings): array {
		$settings = $this->normalizeSettings($settings);
		$values = [
			'chatbot_backend' => $settings['chatbot_backend'],
			'use_markdown' => $settings['use_markdown'],
			'use_icons' => $settings['use_icons'],
			'use_voice' => $settings['use_voice'],
			'use_threads' => $settings['use_threads'],
			'transport_mode' => $settings['transport_mode'],
			'reference_mode' => $settings['reference_mode'],
			'reference_json' => $this->formatReferenceJson($settings['reference']),
			'reference_provider' => $settings['reference_provider'],
			'default_lang' => $settings['default_lang'],
			'base_prompts' => $settings['base_prompts']
		];

		$runtimeId = $this->getRuntimeIdFromBackend((string)$settings['chatbot_backend']);
		if ($runtimeId !== '') {
			$values = array_merge(
				$values,
				$this->agentConfigFormService->settingsToViewValues($settings)
			);
		}

		return $values;
	}

	protected function resolveBackendFromSettings(array $settings): string {
		$backend = $this->normalizeBackendId((string)($settings['chatbot_backend'] ?? ''));
		if ($backend !== '') {
			return $backend;
		}

		$legacyService = $this->normalizeTechnicalKey((string)($settings['service'] ?? ''));
		if ($legacyService !== '' && $legacyService !== self::AGENT_CHATBOT_SERVICE) {
			return self::BACKEND_SERVICE_PREFIX . $legacyService;
		}

		$runtimeId = $this->agentRuntimeSelector->selectRuntimeId($settings);

		return self::BACKEND_RUNTIME_PREFIX . $runtimeId;
	}

	protected function normalizeBackendId(string $backend): string {
		$backend = strtolower(trim($backend));
		if (str_starts_with($backend, self::BACKEND_RUNTIME_PREFIX)) {
			$id = $this->normalizeTechnicalKey(substr($backend, strlen(self::BACKEND_RUNTIME_PREFIX)));
			return $id !== '' ? self::BACKEND_RUNTIME_PREFIX . $id : '';
		}
		if (str_starts_with($backend, self::BACKEND_SERVICE_PREFIX)) {
			$id = $this->normalizeTechnicalKey(substr($backend, strlen(self::BACKEND_SERVICE_PREFIX)));
			return $id !== '' ? self::BACKEND_SERVICE_PREFIX . $id : '';
		}

		return '';
	}

	protected function getRuntimeIdFromBackend(string $backend): string {
		$backend = $this->normalizeBackendId($backend);
		if (!str_starts_with($backend, self::BACKEND_RUNTIME_PREFIX)) {
			return '';
		}

		return substr($backend, strlen(self::BACKEND_RUNTIME_PREFIX));
	}

	protected function getServiceIdFromBackend(string $backend): string {
		$backend = $this->normalizeBackendId($backend);
		if (!str_starts_with($backend, self::BACKEND_SERVICE_PREFIX)) {
			return '';
		}

		return substr($backend, strlen(self::BACKEND_SERVICE_PREFIX));
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

			$prompt = trim($this->normalizeTextBlock((string)$prompt));

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

		$value = strtolower(trim((string)$value));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}
}
