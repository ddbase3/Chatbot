<?php declare(strict_types=1);

namespace Chatbot\Service;

use Base3\Api\IOutput;
use Base3\Api\IRequest;

/**
 * ChatbotService
 *
 * Dummy / UI-test service.
 * - baseprompt: returns a simple base prompt
 * - prompt: simulates a streaming endpoint (SSE-like) and exits
 * - suggestions: returns 3 short follow-up suggestions as JSON
 *
 * This is intentionally minimal and has no external dependencies.
 */
class DummyChatbotService implements IOutput {

	private IRequest $request;

	public function __construct(IRequest $request) {
		$this->request = $request;
	}

	public static function getName(): string {
		return 'dummychatbotservice';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {

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

	public function getHelp(): string {
		return 'Help on DummyChatbotService.';
	}

	/**
	 * Resturns a base prompt for showing when start working with the chatbot.
	 * Read prompts from JSON array and return a random entry.
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
	 * Dummy streaming endpoint (SSE-like).
	 * Sends a few events and exits, so the UI can test streaming behavior.
	 */
	protected function runStreamingFlow() {

		$prompt = (string)$this->request->request('prompt');

		// Basic SSE headers (works even if your UI only "expects" streaming).
		// If your stack already sets headers elsewhere, you can remove these lines.
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		// Helper to emit an SSE event
		$emit = function(string $event, array $data): void {
			echo "event: {$event}\n";
			echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

			if (function_exists('ob_flush')) {
				@ob_flush();
			}
			@flush();
		};

		// Start event
		$emit('start', [
			'id' => uniqid('msg_', true),
			'type' => 'start',
			'text' => 'Dummy stream started',
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);

		// Simulated token stream
		$tokens = [
			'OK.',
			' This is a dummy response for UI testing.',
			' Your prompt was: ',
			$prompt,
			' ✅'
		];

		foreach ($tokens as $t) {
			$emit('token', [
				'type' => 'token',
				'text' => (string)$t
			]);

			// Tiny delay to make streaming visible in the UI
			usleep(120000);
		}

		// Done event
		$emit('done', [
			'type' => 'done',
			'text' => '',
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);

		// Necessary, otherwise mime type error / additional output in some stacks.
		exit;

		return '';
	}

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
