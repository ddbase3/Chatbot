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
 * Dummy / UI-test service without external dependencies.
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
		if ($this->request->get('baseprompt') !== null) {
			return $this->getBasePrompt();
		}

		if ($this->request->request('suggestions') !== null || $this->request->get('suggestions') !== null) {
			return $this->suggestPrompts();
		}

		if ($this->getPromptInput() !== null) {
			return $this->getTransportMode() === 'rest'
				? $this->runRestFlow($final)
				: $this->runStreamingFlow();
		}

		return '';
	}

	public function getHelp(): string {
		return 'Help on DummyChatbotService.';
	}

	protected function getPromptInput(): ?string {
		$prompt = $this->request->request('prompt');

		if ($prompt === null) {
			$prompt = $this->request->get('prompt');
		}

		if ($prompt === null) {
			return null;
		}

		return (string)$prompt;
	}

	protected function getTransportMode(): string {
		$mode = $this->request->request('transport_mode');
		if ($mode === null) {
			$mode = $this->request->get('transport_mode');
		}

		return strtolower(trim((string)$mode));
	}

	protected function getBasePrompt(): string {
		return $this->getSimpleBasePrompt();
	}

	protected function getSimpleBasePrompt(): string {
		$base = [
			'Hallo! 👋',
			'Hi! Womit soll ich dir helfen?',
			'Test-Prompt: Sag mir kurz, was du brauchst.'
		];

		return $base[array_rand($base)];
	}

	protected function runRestFlow(bool $final): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		$response = [
			'id' => uniqid('msg_', true),
			'type' => 'message',
			'text' => $this->buildResponseText()
		];
		$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($json) ? $json : '{"type":"error","text":"Dummy response could not be encoded."}';
	}

	protected function runStreamingFlow(): string {
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		$emit = function(string $event, array $data): void {
			echo "event: {$event}\n";
			echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

			if (function_exists('ob_flush')) {
				@ob_flush();
			}

			@flush();
		};

		$emit('msgid', [
			'id' => uniqid('msg_', true)
		]);

		foreach ($this->getResponseTokens() as $token) {
			$emit('token', [
				'type' => 'token',
				'text' => $token
			]);

			usleep(120000);
		}

		$emit('done', [
			'type' => 'done',
			'status' => 'completed',
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);

		exit;
	}

	protected function buildResponseText(): string {
		return implode('', $this->getResponseTokens());
	}

	/** @return array<int,string> */
	protected function getResponseTokens(): array {
		return [
			'OK.',
			' This is a dummy response for UI testing.',
			' Your prompt was: ',
			(string)$this->getPromptInput(),
			' ✅'
		];
	}

	protected function suggestPrompts(): string {
		$suggestions = [
			'Gib mir ein kurzes Beispiel dazu.',
			'Welche nächsten Schritte empfiehlst du?',
			'Fass das in 3 Bulletpoints zusammen.'
		];

		$json = json_encode($suggestions, JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : '[]';
	}
}
