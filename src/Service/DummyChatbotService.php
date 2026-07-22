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
use AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\Api\IRequest;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;

/**
 * Dummy / UI-test service without external dependencies.
 */
class DummyChatbotService implements IChatbotService {

	public function __construct(
		private readonly IRequest $request,
		private readonly ChatbotTurnRequestFactory $turnRequestFactory,
		private readonly ChatbotTurnResponder $turnResponder
	) {}

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

		$turn = $this->turnRequestFactory->fromCurrentRequest();
		if (!$turn->hasPromptOrResume()) {
			return '';
		}

		return $turn->getTransportMode() === 'rest'
			? $this->turnResponder->respondRest($this, $turn, $final)
			: $this->turnResponder->respondSse($this, $turn);
	}

	public function executeTurn(
		ChatbotTurnRequest $request,
		IAgentEventSink $eventSink
	): ChatbotTurnResult {
		$id = uniqid('msg_', true);
		$tokens = $this->getResponseTokens($request);

		$eventSink->emit(new AgentExecutionEvent('msgid', ['id' => $id]));
		foreach ($tokens as $token) {
			if ($eventSink->isCancelled()) {
				break;
			}
			$eventSink->emit(new AgentExecutionEvent('token', [
				'type' => 'token',
				'text' => $token
			]));
		}
		$eventSink->emit(new AgentExecutionEvent('done', [
			'type' => 'done',
			'status' => 'completed',
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]));

		return ChatbotTurnResult::message($id, implode('', $tokens));
	}

	public function getHelp(): string {
		return 'Help on DummyChatbotService.';
	}

	protected function getBasePrompt(): string {
		$base = [
			'Hallo! 👋',
			'Hi! Womit soll ich dir helfen?',
			'Test-Prompt: Sag mir kurz, was du brauchst.'
		];

		return $base[array_rand($base)];
	}

	/** @return array<int,string> */
	protected function getResponseTokens(ChatbotTurnRequest $request): array {
		return [
			'OK.',
			' This is a dummy response for UI testing.',
			' Your prompt was: ',
			$request->getPrompt(),
			' ✅'
		];
	}

	protected function suggestPrompts(): string {
		$json = json_encode([
			'Gib mir ein kurzes Beispiel dazu.',
			'Welche nächsten Schritte empfiehlst du?',
			'Fass das in 3 Bulletpoints zusammen.'
		], JSON_UNESCAPED_UNICODE);

		return is_string($json) ? $json : '[]';
	}
}
