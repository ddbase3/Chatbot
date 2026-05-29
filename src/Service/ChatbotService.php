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

namespace Chatbot\Service;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentFlowFactory;
use Throwable;

class ChatbotService implements IOutput {

	public function __construct(
		protected readonly IRequest $request,
		protected readonly IAgentContextFactory $contextFactory,
		protected readonly IAgentFlowFactory $flowFactory,
		protected readonly ISettingsStore $settingsStore
	) {}

	public static function getName(): string {
		return 'chatbotservice';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {

		if ($this->request->get('baseprompt') !== null) {
			return $this->getBasePrompt();
		}

		if ($this->getPromptInput() !== null) {
			return $this->runStreamingFlow();
		}

		if ($this->request->request('suggestions') !== null || $this->request->get('suggestions') !== null) {
			return $this->suggestPrompts();
		}

		return '';
	}

	public function getHelp(): string {
		return 'Help on ChatbotService.';
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Chatbot instance configuration
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Reads the chatbot SettingsStore identity from the request.
	 *
	 * The browser only sends the identity of the chatbot instance. The actual
	 * configuration, especially server-side values like system_prompt and later
	 * agent/tool settings, is loaded on the server through ISettingsStore.
	 */
	protected function getConfigIdentity(): array {
		$group = trim((string) ($this->request->request('config_group') ?? ''));

		if ($group === '') {
			$group = trim((string) ($this->request->get('config_group') ?? ''));
		}

		$name = trim((string) ($this->request->request('config_name') ?? ''));

		if ($name === '') {
			$name = trim((string) ($this->request->get('config_name') ?? ''));
		}

		return [
			'group' => $group,
			'name' => $name
		];
	}

	/**
	 * Returns the SettingsStore dataset for the current chatbot instance.
	 *
	 * Empty group/name means that the service is called without instance-bound
	 * configuration. This keeps older direct service usage working. Loading
	 * errors are kept non-fatal here because the service still has file/default
	 * fallbacks for prompts and flows.
	 */
	protected function getChatbotSettings(): array {
		$identity = $this->getConfigIdentity();

		if ($identity['group'] === '' || $identity['name'] === '') {
			return [];
		}

		try {
			$settings = $this->settingsStore->get($identity['group'], $identity['name'], []);
		}
		catch (Throwable) {
			return [];
		}

		return is_array($settings) ? $settings : [];
	}

	/**
	 * Stores instance metadata and loaded settings in the agent context.
	 *
	 * This gives later nodes access to the concrete chatbot instance without
	 * exposing server-side settings to the browser. The complete settings array
	 * is stored intentionally because upcoming configuration areas such as
	 * agent tools will be service-side concerns as well.
	 */
	protected function applyChatbotConfigContext(IAgentContext $context, array $chatbotSettings): void {
		$identity = $this->getConfigIdentity();

		$context->setVar('chatbot_config_group', $identity['group']);
		$context->setVar('chatbot_config_name', $identity['name']);
		$context->setVar('chatbot_config', $chatbotSettings);
	}

	/**
	 * Reads a string value from the loaded chatbot settings.
	 */
	protected function getChatbotSettingString(array $settings, string $key, string $default = ''): string {
		if (!array_key_exists($key, $settings)) {
			return $default;
		}

		$value = $settings[$key];

		if (is_scalar($value) || $value === null) {
			return trim((string) $value);
		}

		return $default;
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Reference context
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Reads the client reference payload.
	 */
	protected function getReferenceInput(): array {
		$raw = $this->request->request('reference');

		if ($raw === null) {
			$raw = $this->request->get('reference');
		}

		if ($raw === null || $raw === '') {
			return [];
		}

		if (is_array($raw)) {
			return $this->normalizeReferenceArray($raw);
		}

		$format = (string) ($this->request->request('reference_format') ?? '');

		if ($format === '') {
			$format = (string) ($this->request->get('reference_format') ?? '');
		}

		$decoded = $this->decodeReferenceString((string) $raw, $format);

		if (!is_array($decoded)) {
			return [];
		}

		return $this->normalizeReferenceArray($decoded);
	}

	/**
	 * Decodes a serialized reference payload.
	 */
	protected function decodeReferenceString(string $raw, string $format): ?array {
		$raw = trim($raw);

		if ($raw === '') {
			return null;
		}

		if ($format === 'base64json') {
			return $this->decodeBase64JsonReference($raw);
		}

		$decoded = json_decode($raw, true);

		if (is_array($decoded)) {
			return $decoded;
		}

		return $this->decodeBase64JsonReference($raw);
	}

	/**
	 * Decodes a Base64URL encoded JSON reference payload.
	 */
	protected function decodeBase64JsonReference(string $raw): ?array {
		$base64 = strtr($raw, '-_', '+/');
		$padding = strlen($base64) % 4;

		if ($padding > 0) {
			$base64 .= str_repeat('=', 4 - $padding);
		}

		$json = base64_decode($base64, true);

		if (!is_string($json) || $json === '') {
			return null;
		}

		$decoded = json_decode($json, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Stores the client reference payload in the agent context.
	 */
	protected function applyReferenceContext(IAgentContext $context): void {
		$context->setVar('reference', $this->getReferenceInput());
	}

	/**
	 * Limits reference payloads to simple serializable values.
	 */
	protected function normalizeReferenceArray(array $data, int $depth = 0): array {
		if ($depth > 5) {
			return [];
		}

		$result = [];

		foreach ($data as $key => $value) {
			if (!is_string($key) && !is_int($key)) {
				continue;
			}

			if (is_scalar($value) || $value === null) {
				$result[$key] = $value;
				continue;
			}

			if (is_array($value)) {
				$result[$key] = $this->normalizeReferenceArray($value, $depth + 1);
			}
		}

		return $result;
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Base prompt
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Returns a simple base prompt.
	 */
	protected function getSimpleBasePrompt(): string {
		$base = [
			'Hallo! 👋',
			'Hi! Womit soll ich dir helfen?',
			'Test-Prompt: Sag mir kurz, was du brauchst.'
		];

		return $base[array_rand($base)];
	}

	/**
	 * Base prompt file name having a json array of base prompts.
	 */
	protected function getBasePromptFile(): string {
		return '';
	}

	/**
	 * Returns a base prompt for showing when start working with the chatbot.
	 * Read prompts from JSON array and return a random entry.
	 */
	protected function getBasePrompt(): string {
		$file = $this->getBasePromptFile();

		if ($file === '') {
			return $this->getSimpleBasePrompt();
		}

		$prompts = @json_decode((string) @file_get_contents($file), true);

		if (!is_array($prompts) || $prompts === []) {
			return $this->getSimpleBasePrompt();
		}

		return (string) $prompts[array_rand($prompts)];
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Assistant answer
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Returns a simple system prompt.
	 */
	protected function getSimpleSystemPrompt(): string {
		return 'You are a helpful assistant.';
	}

	/**
	 * System prompt file name.
	 */
	protected function getSystemPromptFile(): string {
		return '';
	}

	/**
	 * Returns the effective system prompt for the current chatbot instance.
	 *
	 * Priority:
	 * 1. SettingsStore value "system_prompt" for config_group/config_name
	 * 2. File returned by getSystemPromptFile()
	 * 3. Built-in fallback prompt
	 */
	protected function getSystemPrompt(array $chatbotSettings): string {
		$configuredPrompt = $this->getChatbotSettingString($chatbotSettings, 'system_prompt');

		if ($configuredPrompt !== '') {
			return $configuredPrompt;
		}

		$systemFile = $this->getSystemPromptFile();

		if ($systemFile !== '') {
			$filePrompt = (string) @file_get_contents($systemFile);

			if (trim($filePrompt) !== '') {
				return $filePrompt;
			}
		}

		return $this->getSimpleSystemPrompt();
	}

	/**
	 * Returns a simple agent flow configuration.
	 */
	protected function getSimpleAgentFlow(): ?array {
		return null;
	}

	/**
	 * Agent flow file name.
	 */
	protected function getAgentFlowFile(): string {
		return '';
	}

	/**
	 * Reads the user prompt from POST or GET.
	 *
	 * The chat client may POST long messages first and then use GET for the SSE
	 * stream. Therefore the runtime must accept both transports here.
	 */
	protected function getPromptInput(): ?string {
		$prompt = $this->request->request('prompt');

		if ($prompt === null) {
			$prompt = $this->request->get('prompt');
		}

		if ($prompt === null) {
			return null;
		}

		return (string) $prompt;
	}

	/**
	 * Executes the AgentFlow for streaming.
	 * The actual streaming (SSE) is executed inside the StreamingAiAssistantNode.
	 */
	protected function runStreamingFlow(): string {

		try {
			$chatbotSettings = $this->getChatbotSettings();

			$context = $this->contextFactory->createContext();
			$this->applyReferenceContext($context);
			$this->applyChatbotConfigContext($context, $chatbotSettings);

			$flowFile = $this->getAgentFlowFile();

			$json = $flowFile !== '' ? @file_get_contents($flowFile) : false;
			$config = is_string($json) ? json_decode($json, true) : null;
			$config ??= $this->getSimpleAgentFlow();

			if (!is_array($config) || $config === []) {
				return $this->errorResponse('[Invalid Flow JSON]');
			}

			$flow = $this->flowFactory->createFromArray('strictflow', $config, $context);

			$systemPrompt = $this->getSystemPrompt($chatbotSettings);
			$userPrompt = (string) $this->getPromptInput();

			$inputs = [
				'system' => $systemPrompt,
				'user' => $userPrompt
			];

			$flow->run($inputs);

			exit;
		}
		catch (Throwable $e) {
			return $this->errorResponse('[Chatbot runtime error] ' . $e->getMessage());
		}

		return '';
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Prompt suggestion
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Returns a simple suggestion prompt.
	 */
	protected function getSimpleSuggestionPrompt(): string {
		return 'Suggest three prompts.';
	}

	/**
	 * Suggestion prompt file name.
	 */
	protected function getSuggestionPromptFile(): string {
		return '';
	}

	/**
	 * Returns the effective suggestion system prompt.
	 *
	 * The first implementation keeps suggestion prompts file/default based.
	 * A dedicated SettingsStore key can be added later without changing the
	 * external chatbot instance identity model.
	 */
	protected function getSuggestionPrompt(array $chatbotSettings): string {
		$configuredPrompt = $this->getChatbotSettingString($chatbotSettings, 'suggestion_system_prompt');

		if ($configuredPrompt !== '') {
			return $configuredPrompt;
		}

		$systemFile = $this->getSuggestionPromptFile();

		if ($systemFile !== '') {
			$filePrompt = (string) @file_get_contents($systemFile);

			if (trim($filePrompt) !== '') {
				return $filePrompt;
			}
		}

		return $this->getSimpleSuggestionPrompt();
	}

	/**
	 * Returns a simple suggestion agent flow configuration.
	 */
	protected function getSimpleSuggestionFlow(): ?array {
		return null;
	}

	/**
	 * Suggestion agent flow file name.
	 */
	protected function getSuggestionFlowFile(): string {
		return '';
	}

	/**
	 * Returns 3 short, concrete suggestions as JSON array.
	 */
	protected function suggestPrompts(): string {

		$chatbotSettings = $this->getChatbotSettings();

		$context = $this->contextFactory->createContext();
		$this->applyReferenceContext($context);
		$this->applyChatbotConfigContext($context, $chatbotSettings);

		$flowFile = $this->getSuggestionFlowFile();
		$json = $flowFile !== '' ? @file_get_contents($flowFile) : false;
		$config = is_string($json) ? json_decode($json, true) : null;
		$config ??= $this->getSimpleSuggestionFlow();

		if (!is_array($config) || $config === []) {
			return $this->errorResponse('[Invalid Suggestions Flow JSON]');
		}

		$flow = $this->flowFactory->createFromArray('strictflow', $config, $context);

		$systemPrompt = $this->getSuggestionPrompt($chatbotSettings);
		$userPrompt = 'Generate suggestions.';

		$inputs = [
			'system' => $systemPrompt,
			'prompt' => $userPrompt,
			'mode' => 'suggestions'
		];

		$output = $flow->run($inputs);

		$msg = '';
		if (isset($output['assistant']['message']['content'])) {
			$msg = (string) $output['assistant']['message']['content'];
		} elseif (isset($output['message']['content'])) {
			$msg = (string) $output['message']['content'];
		}

		$clean = trim($msg);
		$clean = preg_replace('/^```json/i', '', $clean);
		$clean = preg_replace('/^```/i', '', $clean);
		$clean = preg_replace('/```$/', '', $clean);
		$clean = trim($clean);

		$decoded = json_decode($clean, true);

		if (!is_array($decoded)) {
			return json_encode([
				'error' => 'Invalid JSON from suggestions model',
				'raw' => $msg,
				'clean' => $clean
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode($decoded, JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Returns a JSON error object.
	 */
	protected function errorResponse(string $msg): string {
		return json_encode([
			'id' => uniqid('msg_', true),
			'type' => 'error',
			'text' => $msg,
			'meta' => [
				'timestamp' => gmdate('c')
			]
		], JSON_UNESCAPED_UNICODE);
	}
}
