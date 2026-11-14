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
use Base3\Chatbot\Api\IChatbotService;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Dto\ChatbotTurnResult;

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
