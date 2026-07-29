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

namespace Chatbot\Dto;

use AssistantFoundation\Dto\AgentConversationState;

/**
 * Client-facing conversation state with an optional unsaved draft.
 */
final class ChatbotConversationClientState {

	public function __construct(
		private readonly AgentConversationState $state,
		private readonly ?ChatbotConversationDraft $draft = null
	) {}

	public function getState(): AgentConversationState {
		return $this->state;
	}

	public function getDraft(): ?ChatbotConversationDraft {
		return $this->draft;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array_replace(
			$this->state->toArray(),
			['draft' => $this->draft?->toClientArray()]
		);
	}
}
