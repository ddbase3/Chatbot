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
	 * Aget flow file name having a json array of base prompts.
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
	 * Returns 3 short, concrete suggestions as JSON array.
	 * Keeps it language-neutral-ish but defaults to German.
	 */
	protected function suggestPrompts(): string {

		$last = (string)$this->request->request('prompt');
		$hint = trim($last) !== '' ? $last : 'dein letztes Thema';

		$suggestions = [
			'Gib mir ein kurzes Beispiel dazu.',
			'Welche nächsten Schritte empfiehlst du?',
			'Fass das in 3 Bulletpoints zusammen.'
		];

		// Slightly adapt if we have any hint text
		if (trim($hint) !== '' && $hint !== 'dein letztes Thema') {
			$suggestions = [
				'Gib mir ein kurzes Beispiel zu: ' . mb_substr($hint, 0, 40) . (mb_strlen($hint) > 40 ? '…' : ''),
				'Welche 3 Optionen habe ich als Nächstes?',
				'Mach daraus eine kurze Checkliste.'
			];
		}

		return json_encode($suggestions, JSON_UNESCAPED_UNICODE);
	}
}
