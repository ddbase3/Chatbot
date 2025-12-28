<?php declare(strict_types=1);

namespace Chatbot\Service;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
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

	public function getOutput($out = 'html'): string {

		// Provide a base-test prompt
		if ($this->request->get('baseprompt') !== null) {
			return $this->getBasePrompt();
		}

		// Streaming request
		if ($this->request->request('prompt') !== null) {
			return $this->runStreamingFlow();
		}

		// Suggestion
		if ($this->request->request('suggestions') !== null) {
			return $this->suggestPrompts();
		}

		return '';
	}

	public function getHelp() {
		return 'Help on ChatbotService.';
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
	 * Resturns a base prompt for showing when start working with the chatbot.
	 * Read prompts from JSON array and return a random entry.
	 */
        protected function getBasePrompt(): string {
		$file = $this->getBasePromptFile();

		if (empty($file)) {
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
	 * System prompt file name having a json array of base prompts.
	 */
	protected function getSystemPromptFile(): string {
		return '';
	}

	/**
	 * Returns a simple agent flow configuration (array).
	 */
	protected function getSimpleAgentFlow(): string {
		return null;
	}

	/**
	 * Agent flow file name having a json array of base prompts.
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

		$flowFile = $this->getAgentFlowFile();
		$systemFile = $this->getSystemPromptFile();

		$json = file_get_contents($flowFile);
		$config = json_decode($json, true) ?? $this->getSimpleAgentFlow();

		if (!$config) {
			return $this->errorResponse("[Invalid Flow JSON]");
		}

		$flow = $this->flowFactory->createFromArray("strictflow", $config, $context);

		$systemPrompt = file_get_contents($systemFile) ?: $this->getSimpleSystemPrompt();
		$userPrompt = (string)$this->request->request('prompt');

		$inputs = [
			'system' => $systemPrompt,
			'user' => $userPrompt
		];

		// The StreamingAiAssistantNode opens the SSE stream internally.
		$flow->run($inputs);

		// Necessary, otherwise mime type error.
		exit;

		// Nothing else to output here, the stream is already running.
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
	 * Suggestion prompt file name having a json array of base prompts.
	 */
	protected function getSuggestionPromptFile(): string {
		return '';
	}

	/**
	 * Returns a simple suggestion agent flow configuration (array).
	 */
	protected function getSimpleSuggestionFlow(): string {
		return null;
	}

	/**
	 * Suggestion agent flow file name having a json array of base prompts.
	 */
	protected function getSuggestionFlowFile(): string {
		return '';
	}

	/**
	 * Returns 3 short, concrete suggestions as JSON array.
	 */
	private function suggestPrompts(): string {

		$context = $this->contextFactory->createContext();

		// Load suggestions flow
		$flowFile = $this->getSuggestionFlowFile();
		$json = file_get_contents($flowFile);
		$config = json_decode($json, true) ?? $this->getSimpleSuggestionFlow();

		if (!$config) {
			return $this->errorResponse("[Invalid Suggestions Flow JSON]");
		}

		$flow = $this->flowFactory->createFromArray("strictflow", $config, $context);

		$systemFile = $this->getSuggestionPromptFile();
		$systemPrompt = file_get_contents($systemFile) ?: $this->getSimpleSuggestionPrompt();

		$userPrompt = "Generate suggestions.";

		$inputs = [
			'system' => $systemPrompt,
			'prompt' => $userPrompt,
			'mode'   => 'suggestions'
		];

		$output = $flow->run($inputs);

		// Flow outputs are keyed by node id ("assistant"), then by port name ("message")
		$msg = '';
		if (isset($output['assistant']['message']['content'])) {
			$msg = (string)$output['assistant']['message']['content'];
		} elseif (isset($output['message']['content'])) {
			// fallback, just in case
			$msg = (string)$output['message']['content'];
		}

		// --- CLEAN JSON codeblock ---
		$clean = trim($msg);
		$clean = preg_replace('/^```json/i', '', $clean);
		$clean = preg_replace('/^```/i', '', $clean);
		$clean = preg_replace('/```$/', '', $clean);
		$clean = trim($clean);

		$decoded = json_decode($clean, true);

		if (!is_array($decoded)) {
			return json_encode([
				'error' => 'Invalid JSON from suggestions model',
				'raw'   => $msg,
				'clean' => $clean
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode($decoded, JSON_UNESCAPED_UNICODE);
	}
}
