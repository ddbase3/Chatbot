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

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use AssistantFoundation\Dto\AgentInteractionRequest;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;
use Throwable;

abstract class AbstractChatbotService implements IChatbotService {

	public function __construct(
		protected readonly IRequest $request,
		protected readonly ISettingsStore $settingsStore,
		protected readonly IAgentExecutionService $agentExecutionService,
		protected readonly ChatbotTurnRequestFactory $turnRequestFactory,
		protected readonly ChatbotTurnResponder $turnResponder
	) {}

	abstract public static function getName(): string;

	public static function getServiceLabel(): string {
		return static::getName();
	}

	public static function getServiceDescription(): string {
		return '';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($this->request->get('baseprompt') !== null) {
			return $this->getBasePrompt();
		}

		if ($this->request->request('suggestions') !== null || $this->request->get('suggestions') !== null) {
			return $this->suggestPrompts();
		}

		$turn = $this->turnRequestFactory->fromCurrentRequest();
		if (!$turn->hasPromptOrResume()) {
			return '';
		}

		return $this->isRestTransport($turn)
			? $this->turnResponder->respondRest($this, $turn, $final)
			: $this->turnResponder->respondSse($this, $turn);
	}

	public function executeTurn(
		ChatbotTurnRequest $request,
		IAgentEventSink $eventSink
	): ChatbotTurnResult {
		$chatbotSettings = $this->getChatbotSettings($request);
		$agentSettings = $this->getAgentSettingsForExecution($chatbotSettings);
		$systemPrompt = $this->getSystemPrompt($chatbotSettings);
		$userPrompt = $request->getPrompt();
		if ($userPrompt === '') {
			$userPrompt = $request->getResumeResponseText();
		}

		$result = $this->agentExecutionService->execute(
			new AgentExecutionRequest(
				$agentSettings,
				$this->buildAgentInputs($systemPrompt, $userPrompt, $request->getResume()),
				$this->getAgentContextVars($request, $chatbotSettings)
			),
			$eventSink
		);

		$output = $result->getOutput();
		$assistantNodeId = $this->getAssistantNodeId($chatbotSettings);
		$interaction = $this->extractInteractionRequired($result, $output, $assistantNodeId);
		if ($interaction !== null) {
			return ChatbotTurnResult::interactionRequired(
				(string)($interaction['status'] ?? ''),
				(string)($interaction['resume_handle'] ?? ''),
				$this->normalizeInteractionRequests(
					is_array($interaction['interaction_requests'] ?? null)
						? $interaction['interaction_requests']
						: []
				)
			);
		}

		$message = $this->extractAssistantMessage($output, $assistantNodeId);
		if ($message === null) {
			$error = $this->extractFlowError($output, $assistantNodeId);
			if ($error !== '') {
				return ChatbotTurnResult::error('[Chatbot runtime error] ' . $error);
			}

			return ChatbotTurnResult::error(
				'[Chatbot runtime error] Flow did not return an assistant message. '
				. $this->describeFlowOutput($output)
			);
		}

		return ChatbotTurnResult::message(
			$this->normalizeMessageId($message['id'] ?? null),
			$this->normalizeMessageContent($message['content'] ?? '')
		);
	}

	public function getHelp(): string {
		return 'Help on AbstractChatbotService.';
	}

	protected function getChatbotSettings(?ChatbotTurnRequest $turn = null): array {
		$turn ??= $this->turnRequestFactory->fromCurrentRequest();
		$group = $turn->getConfigGroup();
		$name = $turn->getConfigName();

		if ($group === '' || $name === '') {
			return [];
		}

		try {
			$settings = $this->settingsStore->get($group, $name, []);
		}
		catch (Throwable) {
			return [];
		}

		return is_array($settings) ? $settings : [];
	}

	/** @return array<string,mixed> */
	protected function getAgentContextVars(ChatbotTurnRequest $turn, array $chatbotSettings): array {
		return [
			'reference' => $turn->getReference(),
			'chatbot_config_group' => $turn->getConfigGroup(),
			'chatbot_config_name' => $turn->getConfigName(),
			'chatbot_config' => $chatbotSettings
		];
	}

	protected function getChatbotSettingString(array $settings, string $key, string $default = ''): string {
		if (!array_key_exists($key, $settings)) {
			return $default;
		}

		$value = $settings[$key];
		if (is_scalar($value) || $value === null) {
			return trim((string)$value);
		}

		return $default;
	}

	protected function getSimpleBasePrompt(): string {
		$base = [
			'Hallo! 👋',
			'Hi! Womit soll ich dir helfen?',
			'Test-Prompt: Sag mir kurz, was du brauchst.'
		];

		return $base[array_rand($base)];
	}

	protected function getBasePromptFile(): string {
		return '';
	}

	protected function getBasePrompt(): string {
		$file = $this->getBasePromptFile();
		if ($file === '') {
			return $this->getSimpleBasePrompt();
		}

		$prompts = @json_decode((string)@file_get_contents($file), true);
		if (!is_array($prompts) || $prompts === []) {
			return $this->getSimpleBasePrompt();
		}

		return (string)$prompts[array_rand($prompts)];
	}

	protected function getSimpleSystemPrompt(): string {
		return 'You are a helpful assistant.';
	}

	protected function getSystemPromptFile(): string {
		return '';
	}

	protected function getSystemPrompt(array $chatbotSettings): string {
		$configuredPrompt = $this->getChatbotSettingString($chatbotSettings, 'system_prompt');
		if ($configuredPrompt !== '') {
			return $configuredPrompt;
		}

		$systemFile = $this->getSystemPromptFile();
		if ($systemFile !== '') {
			$filePrompt = (string)@file_get_contents($systemFile);
			if (trim($filePrompt) !== '') {
				return $filePrompt;
			}
		}

		return $this->getSimpleSystemPrompt();
	}

	protected function getSimpleAgentFlow(): ?array {
		return null;
	}

	protected function getAgentFlowFile(): string {
		return '';
	}

	/** @param array<string,mixed> $resume @return array<string,mixed> */
	protected function buildAgentInputs(string $systemPrompt, string $userPrompt, array $resume = []): array {
		$inputs = [
			'system' => $systemPrompt,
			'prompt' => $userPrompt,
			'mode' => 'chat'
		];
		if ($resume !== []) {
			$inputs['resume'] = $resume;
		}

		return $inputs;
	}

	/** @param array<string,mixed> $chatbotSettings @return array<string,mixed> */
	protected function getAgentSettingsForExecution(array $chatbotSettings): array {
		$settings = $chatbotSettings;
		if (!array_key_exists('agent_runtime', $settings)) {
			$backend = strtolower(trim((string)($settings['chatbot_backend'] ?? '')));
			if (str_starts_with($backend, 'runtime:')) {
				$runtimeId = preg_replace('/[^a-z0-9._-]+/', '', substr($backend, 8)) ?? '';
				if ($runtimeId !== '') {
					$settings['agent_runtime'] = $runtimeId;
				}
			}
		}

		$flowFile = $this->getAgentFlowFile();
		if ($flowFile !== '') {
			$json = @file_get_contents($flowFile);
			$config = is_string($json) ? json_decode($json, true) : null;
			if (is_array($config) && $config !== []) {
				$settings['agent_flow'] = $config;
			}
		}
		elseif (!array_key_exists('agent_flow', $settings)) {
			$config = $this->getSimpleAgentFlow();
			if (is_array($config)) {
				$settings['agent_flow'] = $config;
			}
		}

		return $settings;
	}

	protected function isRestTransport(ChatbotTurnRequest $turn): bool {
		$mode = $turn->getTransportMode();
		if ($mode !== '') {
			return $mode === 'rest';
		}

		return $this->getChatbotSettingString($this->getChatbotSettings($turn), 'transport_mode') === 'rest';
	}

	protected function getAssistantNodeId(array $chatbotSettings): string {
		$nodeId = $this->getChatbotSettingString($chatbotSettings, 'agent_components_assistant_node', 'assistant');

		return $nodeId !== '' ? $nodeId : 'assistant';
	}

	/** @param array<string,mixed> $output @return array<string,mixed>|null */
	protected function extractInteractionRequired(
		AgentExecutionResult $result,
		array $output,
		string $assistantNodeId
	): ?array {
		$agentResult = $result->getAgentResult();
		if ($agentResult !== null && $agentResult->isSuspended()) {
			$suspension = $agentResult->getState()->getSuspension();
			if ($suspension !== null && $suspension->isSuspended()) {
				return [
					'status' => $suspension->getStatus(),
					'interaction_requests' => $this->normalizeInteractionRequests($suspension->getInteractionRequests()),
					'resume_handle' => $suspension->getResumeHandle()
				];
			}
		}

		$candidates = [];
		if (isset($output[$assistantNodeId]) && is_array($output[$assistantNodeId])) {
			$candidates[] = $output[$assistantNodeId];
		}
		if (isset($output['assistant']) && is_array($output['assistant'])) {
			$candidates[] = $output['assistant'];
		}
		foreach ($output as $nodeOutput) {
			if (is_array($nodeOutput)) {
				$candidates[] = $nodeOutput;
			}
		}

		foreach ($candidates as $candidate) {
			$handle = trim((string)($candidate['resume_handle'] ?? ''));
			$requests = $candidate['interaction_requests'] ?? null;
			$status = trim((string)($candidate['status'] ?? ''));
			if ($handle !== '' && is_array($requests) && $requests !== []) {
				return [
					'status' => $status,
					'interaction_requests' => $requests,
					'resume_handle' => $handle
				];
			}
		}

		return null;
	}

	/** @param array<int,mixed> $requests @return array<int,array<string,mixed>> */
	protected function normalizeInteractionRequests(array $requests): array {
		$result = [];
		foreach ($requests as $request) {
			if ($request instanceof AgentInteractionRequest) {
				$result[] = $request->toArray();
				continue;
			}
			if (is_array($request)) {
				$result[] = $request;
			}
		}

		return $result;
	}

	/** @param array<string,mixed> $output @return array<string,mixed>|null */
	protected function extractAssistantMessage(array $output, string $assistantNodeId): ?array {
		if (isset($output[$assistantNodeId]['message']) && is_array($output[$assistantNodeId]['message'])) {
			return $output[$assistantNodeId]['message'];
		}
		if (isset($output['assistant']['message']) && is_array($output['assistant']['message'])) {
			return $output['assistant']['message'];
		}
		foreach ($output as $nodeOutput) {
			if (is_array($nodeOutput) && isset($nodeOutput['message']) && is_array($nodeOutput['message'])) {
				return $nodeOutput['message'];
			}
		}

		return null;
	}

	/** @param array<string,mixed> $output */
	protected function extractFlowError(array $output, string $assistantNodeId): string {
		if (isset($output[$assistantNodeId]['error']) && is_scalar($output[$assistantNodeId]['error'])) {
			return trim((string)$output[$assistantNodeId]['error']);
		}
		if (isset($output['assistant']['error']) && is_scalar($output['assistant']['error'])) {
			return trim((string)$output['assistant']['error']);
		}
		foreach ($output as $nodeOutput) {
			if (is_array($nodeOutput) && isset($nodeOutput['error']) && is_scalar($nodeOutput['error'])) {
				return trim((string)$nodeOutput['error']);
			}
		}

		return '';
	}

	/** @param array<string,mixed> $output */
	protected function describeFlowOutput(array $output): string {
		$nodeIds = array_values(array_filter(
			array_map('strval', array_keys($output)),
			static fn(string $id): bool => $id !== ''
		));

		return $nodeIds === []
			? 'No terminal node output was returned.'
			: 'Terminal nodes: ' . implode(', ', $nodeIds) . '.';
	}

	protected function normalizeMessageId(mixed $id): string {
		if (is_scalar($id)) {
			$id = trim((string)$id);
			if ($id !== '') {
				return $id;
			}
		}

		return uniqid('msg_', true);
	}

	protected function normalizeMessageContent(mixed $content): string {
		if ($content === null) {
			return '';
		}
		if (is_string($content)) {
			return $content;
		}
		if (is_bool($content)) {
			return $content ? 'true' : 'false';
		}
		if (is_int($content) || is_float($content)) {
			return (string)$content;
		}

		$json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($json) && $json !== 'null' ? $json : '';
	}

	protected function getSimpleSuggestionPrompt(): string {
		return 'Suggest three prompts.';
	}

	protected function getSuggestionPromptFile(): string {
		return '';
	}

	protected function getSuggestionPrompt(array $chatbotSettings): string {
		$configuredPrompt = $this->getChatbotSettingString($chatbotSettings, 'suggestion_system_prompt');
		if ($configuredPrompt !== '') {
			return $configuredPrompt;
		}

		$systemFile = $this->getSuggestionPromptFile();
		if ($systemFile !== '') {
			$filePrompt = (string)@file_get_contents($systemFile);
			if (trim($filePrompt) !== '') {
				return $filePrompt;
			}
		}

		return $this->getSimpleSuggestionPrompt();
	}

	protected function getSimpleSuggestionFlow(): ?array {
		return null;
	}

	protected function getSuggestionFlowFile(): string {
		return '';
	}

	protected function suggestPrompts(): string {
		$turn = $this->turnRequestFactory->fromCurrentRequest();
		$chatbotSettings = $this->getChatbotSettings($turn);
		$flowFile = $this->getSuggestionFlowFile();
		$json = $flowFile !== '' ? @file_get_contents($flowFile) : false;
		$config = is_string($json) ? json_decode($json, true) : null;
		$config ??= $this->getSimpleSuggestionFlow();

		$agentSettings = $this->getAgentSettingsForExecution($chatbotSettings);
		if (is_array($config) && $config !== []) {
			$agentSettings['agent_flow'] = $config;
		}

		$result = $this->agentExecutionService->execute(new AgentExecutionRequest(
			$agentSettings,
			[
				'system' => $this->getSuggestionPrompt($chatbotSettings),
				'prompt' => 'Generate suggestions.',
				'mode' => 'suggestions'
			],
			$this->getAgentContextVars($turn, $chatbotSettings)
		));
		$output = $result->getOutput();

		$msg = '';
		if (isset($output['assistant']['message']['content'])) {
			$msg = (string)$output['assistant']['message']['content'];
		}
		elseif (isset($output['message']['content'])) {
			$msg = (string)$output['message']['content'];
		}

		$clean = trim($msg);
		$clean = preg_replace('/^```json/i', '', $clean);
		$clean = preg_replace('/^```/i', '', $clean);
		$clean = preg_replace('/```$/', '', $clean);
		$clean = trim($clean);
		$decoded = json_decode($clean, true);

		if (!is_array($decoded)) {
			return (string)json_encode([
				'error' => 'Invalid JSON from suggestions model',
				'raw' => $msg,
				'clean' => $clean
			], JSON_UNESCAPED_UNICODE);
		}

		return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE);
	}
}
