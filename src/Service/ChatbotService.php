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
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentFlowFactory;

class ChatbotService implements IOutput {

	public function __construct(
		protected readonly IRequest $request,
		protected readonly IAgentContextFactory $contextFactory,
		protected readonly IAgentFlowFactory $flowFactory
	) {}

	public static function getName(): string {
		return 'chatbotservice';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {

		if ($this->request->get('baseprompt') !== null) {
			return $this->getBasePrompt();
		}

		if ($this->request->request('prompt') !== null) {
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
	 * Executes the AgentFlow for streaming.
	 * The actual streaming (SSE) is executed inside the StreamingAiAssistantNode.
	 */
	protected function runStreamingFlow(): string {

		$context = $this->contextFactory->createContext();
		$this->applyReferenceContext($context);

		$flowFile = $this->getAgentFlowFile();
		$systemFile = $this->getSystemPromptFile();

		$json = $flowFile !== '' ? @file_get_contents($flowFile) : false;
		$config = is_string($json) ? json_decode($json, true) : null;
		$config ??= $this->getSimpleAgentFlow();

		if (!is_array($config) || $config === []) {
			return $this->errorResponse('[Invalid Flow JSON]');
		}

		$flow = $this->flowFactory->createFromArray('strictflow', $config, $context);

		$systemPrompt = $systemFile !== ''
			? ((string) @file_get_contents($systemFile) ?: $this->getSimpleSystemPrompt())
			: $this->getSimpleSystemPrompt();

		$userPrompt = (string) $this->request->request('prompt');

		$inputs = [
			'system' => $systemPrompt,
			'user' => $userPrompt
		];

		$flow->run($inputs);

		exit;

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

		$context = $this->contextFactory->createContext();
		$this->applyReferenceContext($context);

		$flowFile = $this->getSuggestionFlowFile();
		$json = $flowFile !== '' ? @file_get_contents($flowFile) : false;
		$config = is_string($json) ? json_decode($json, true) : null;
		$config ??= $this->getSimpleSuggestionFlow();

		if (!is_array($config) || $config === []) {
			return $this->errorResponse('[Invalid Suggestions Flow JSON]');
		}

		$flow = $this->flowFactory->createFromArray('strictflow', $config, $context);

		$systemFile = $this->getSuggestionPromptFile();
		$systemPrompt = $systemFile !== ''
			? ((string) @file_get_contents($systemFile) ?: $this->getSimpleSuggestionPrompt())
			: $this->getSimpleSuggestionPrompt();

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
