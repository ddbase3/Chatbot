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

namespace Base3\Chatbot\Test\Service;

use Base3\AssistantFoundation\Api\IAgentEventSink;
use Base3\AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\Chatbot\Api\IChatbotService;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Dto\ChatbotTurnResult;
use RuntimeException;

final class ChatbotSseProbeService implements IChatbotService {

	public static function getName(): string {
		return 'chatbotsseprobeservice';
	}

	public static function getServiceLabel(): string {
		return 'SSE transport test backend';
	}

	public static function getServiceDescription(): string {
		return 'Produces deterministic transport events for the SSE tests.';
	}

	public function getHelp(): string {
		return 'SSE transport test backend.';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		return '';
	}

	public function executeTurn(ChatbotTurnRequest $request, IAgentEventSink $eventSink): ChatbotTurnResult {
		switch ($request->getPrompt()) {
			case 'throw':
				throw new RuntimeException('Deliberate stream failure.');
			case 'token-then-throw':
				$eventSink->emit(new AgentExecutionEvent('token', ['text' => 'partial']));
				throw new RuntimeException('Failure after token.');
			case 'runtime-done':
				$eventSink->emit(new AgentExecutionEvent('done', ['status' => 'completed', 'origin' => 'runtime']));
				return ChatbotTurnResult::message('message-1', 'Complete');
			case 'interaction':
				return ChatbotTurnResult::interactionRequired(
					'waiting_for_user', 'resume-1', [['id' => 'approval-1', 'kind' => 'approval']]
				);
			case 'cancelled':
				return ChatbotTurnResult::cancelled();
			case 'error-result':
				return ChatbotTurnResult::error('Provider returned an error.');
			default:
				return ChatbotTurnResult::message('message-1', $request->getPrompt());
		}
	}
}
