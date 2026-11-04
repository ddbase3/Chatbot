<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;

final class ChatbotServiceRegistryTestDouble implements IChatbotService {

	public static function getName(): string {
		return 'chatbotserviceregistrytestdouble';
	}

	public static function getServiceLabel(): string {
		return 'Test service';
	}

	public static function getServiceDescription(): string {
		return 'Test service used by the service registry.';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		return '';
	}

	public function executeTurn(ChatbotTurnRequest $request, IAgentEventSink $eventSink): ChatbotTurnResult {
		return ChatbotTurnResult::message('test', $request->getPrompt());
	}
}
