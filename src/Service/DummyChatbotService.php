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

use Base3\Api\IRequest;
use Chatbot\Api\IChatbotService;

/**
 * DummyChatbotService
 *
 * Dummy / UI-test service.
 * - baseprompt: returns a simple base prompt
 * - prompt: simulates a streaming endpoint (SSE-like) and exits
 * - suggestions: returns 3 short follow-up suggestions as JSON
 *
 * This is intentionally minimal and has no external dependencies.
 */
class DummyChatbotService implements IChatbotService {

	private IRequest $request;

	public function __construct(IRequest $request) {
		$this->request = $request;
	}

	public static function getName(): string {
		return 'dummychatbotservice';
	}

	public static function getServiceLabel(): string {
		return 'Dummy Chatbot Service';
	}

	public static function getServiceDescription(): string {
		return 'Simple UI-test service without external AI dependencies.';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {

		// Provide a base-test prompt.
		if ($this->request->get('baseprompt') !== null) {
			return $this->getBasePrompt();
		}

		// Streaming request.
		if ($this->getPromptInput() !== null) {
			return $this->runStreamingFlow();
		}

		// Suggestion.
		if ($this->request->request('suggestions') !== null || $this->request->get('suggestions') !== null) {
			return $this->suggestPrompts();
		}

		return '';
	}

	public function getHelp(): string {
		return 'Help on DummyChatbotService.';
	}

	/**
	 * Reads the user prompt from POST or GET.
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
	 * Returns a base prompt for showing when start working with the chatbot.
	 */
	protected function getBasePrompt(): string {
		return $this->getSimpleBasePrompt();
	}

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
	 * Dummy streaming endpoint (SSE-like).
	 * Sends a few events and exits, so the UI can test streaming behavior.
	 */
	protected function runStreamingFlow(): string {

		$prompt = (string) $this->getPromptInput();

		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		$emit = function(string $event, array $data): void {
			echo "event: {$event}\n";
			echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

			if (function_exists('ob_flush')) {
				@ob_flush();
			}

			@flush();
		};

		$emit('start', [
			'id' => uniqid('msg_', true),
			'type' => 'start',
			'text' => 'Dummy stream started',
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);

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
				'text' => (string) $t
			]);

			usleep(120000);
		}

		$emit('done', [
			'type' => 'done',
			'text' => '',
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);

		exit;

		return '';
	}

	/**
	 * Returns 3 short, concrete suggestions as JSON array.
	 * Keeps it language-neutral-ish but defaults to German.
	 */
	protected function suggestPrompts(): string {

		$last = (string) $this->getPromptInput();
		$hint = trim($last) !== '' ? $last : 'dein letztes Thema';

		$suggestions = [
			'Gib mir ein kurzes Beispiel dazu.',
			'Welche nächsten Schritte empfiehlst du?',
			'Fass das in 3 Bulletpoints zusammen.'
		];

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
