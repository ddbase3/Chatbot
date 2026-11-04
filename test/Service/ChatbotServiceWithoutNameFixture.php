<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;

final class ChatbotServiceWithoutNameFixture implements IChatbotService {

	public static function getName(): string {
		return '';
	}

	public static function getServiceLabel(): string {
		return 'Unnamed service fixture';
	}

	public static function getServiceDescription(): string {
		return 'Service with an invalid technical identifier for registry tests.';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		return '';
	}

	public function executeTurn(ChatbotTurnRequest $request, IAgentEventSink $eventSink): ChatbotTurnResult {
		return ChatbotTurnResult::message('unused', $request->getPrompt());
	}
}
