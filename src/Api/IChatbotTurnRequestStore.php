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

use Base3\Api\IBase;
use Chatbot\Dto\PendingChatbotTurn;

/**
 * Stores large POST payloads until EventSource claims them by opaque id.
 */
interface IChatbotTurnRequestStore extends IBase {

	public function store(PendingChatbotTurn $turn): string;

	public function claim(string $id): ?PendingChatbotTurn;
}
