<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Dto\AgentExecutionEvent;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;
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
