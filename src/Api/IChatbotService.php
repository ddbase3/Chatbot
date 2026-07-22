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

namespace Chatbot\Api;

use AssistantFoundation\Api\IAgentEventSink;
use Base3\Api\IOutput;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;

/**
 * Routable chatbot backend with explicit turn execution.
 */
interface IChatbotService extends IOutput {

	/**
	 * Returns the human-readable label used in chatbot service selection UIs.
	 */
	public static function getServiceLabel(): string;

	/**
	 * Returns a short description for administrators configuring a chatbot.
	 */
	public static function getServiceDescription(): string;

	/**
	 * Executes one explicit chatbot turn without depending on HTTP request state.
	 */
	public function executeTurn(
		ChatbotTurnRequest $request,
		IAgentEventSink $eventSink
	): ChatbotTurnResult;
}
