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

use AssistantFoundation\Dto\AgentExecutionEvent;
use AssistantRuntime\Service\CollectingAgentEventSink;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;
use Throwable;

/**
 * Shared REST and SSE response handling for every chatbot backend.
 */
final class ChatbotTurnResponder {

	public static function getName(): string {
		return 'chatbotturnresponder';
	}

	public function respondRest(
		IChatbotService $service,
		ChatbotTurnRequest $request,
		bool $final = false
	): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		try {
			$result = $service->executeTurn($request, new CollectingAgentEventSink());
		}
		catch (Throwable $exception) {
			$result = ChatbotTurnResult::error('[Chatbot runtime error] ' . $exception->getMessage());
		}

		$json = json_encode(
			$result->toArray(),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return is_string($json)
			? $json
			: '{"type":"error","text":"Chatbot response could not be encoded."}';
	}

	public function respondSse(IChatbotService $service, ChatbotTurnRequest $request): string {
		$sink = new SseAgentEventSink();
		$sink->start();

		try {
			$result = $service->executeTurn($request, $sink);
			$this->emitTerminalFallbacks($sink, $result);
		}
		catch (Throwable $exception) {
			$userMessage = 'Es ist ein technischer Fehler aufgetreten. Die Anfrage konnte nicht vollständig abgeschlossen werden.';
			if (!$sink->hasEmitted('token')) {
				$sink->emit(new AgentExecutionEvent('token', ['text' => $userMessage]));
			}
			if (!$sink->hasEmitted('error')) {
				$sink->emit(new AgentExecutionEvent('error', [
					'message' => $exception->getMessage(),
					'user_message' => $userMessage,
					'type' => get_class($exception),
					'code' => $exception->getCode()
				]));
			}
			if (!$sink->hasEmitted('done')) {
				$sink->emit(new AgentExecutionEvent('done', ['status' => 'error']));
			}
		}

		return '';
	}

	private function emitTerminalFallbacks(SseAgentEventSink $sink, ChatbotTurnResult $result): void {
		$payload = $result->toArray();

		if ($result->getType() === 'message') {
			if (!$sink->hasEmitted('msgid')) {
				$sink->emit(new AgentExecutionEvent('msgid', ['id' => $result->getId()]));
			}
			if (!$sink->hasEmitted('token') && $result->getText() !== '') {
				$sink->emit(new AgentExecutionEvent('token', ['text' => $result->getText()]));
			}
		}
		elseif ($result->getType() === 'interaction_required' && !$sink->hasEmitted('agent.interaction.required')) {
			$sink->emit(new AgentExecutionEvent('agent.interaction.required', $payload));
		}
		elseif ($result->getType() === 'error' && !$sink->hasEmitted('error')) {
			$sink->emit(new AgentExecutionEvent('error', [
				'message' => $result->getText(),
				'user_message' => $result->getText()
			]));
		}

		if (!$sink->hasEmitted('done')) {
			$sink->emit(new AgentExecutionEvent('done', [
				'status' => $result->getType() === 'error' ? 'error' : 'completed'
			]));
		}
	}
}
