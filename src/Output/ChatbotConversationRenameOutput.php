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

namespace Chatbot\Output;

final class ChatbotConversationRenameOutput extends AbstractChatbotConversationOutput {

	public static function getName(): string {
		return 'chatbotconversationrename';
	}

	protected function handle(array $input): array {
		$state = $this->conversationService->renameConversation(
			$this->requireString($input, 'config_group'),
			$this->requireString($input, 'config_name'),
			$this->requireString($input, 'conversation_id'),
			$this->requireString($input, 'title'),
			$this->getReference($input)
		);
		return ['state' => $state->toArray()];
	}
}
