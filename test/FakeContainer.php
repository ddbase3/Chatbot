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

namespace Base3\Chatbot\Test;

use Base3\CoreFoundation\Api\IContainer;
use Base3\Chatbot\Api\IChatbotTurnRequestStore;
use Base3\Chatbot\ChatbotPlugin;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotConversationService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotServiceRegistry;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use Base3\Chatbot\Service\ChatbotTurnResponder;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use Base3\Chatbot\Service\SessionChatbotTurnRequestStore;
use PHPUnit\Framework\TestCase;

class FakeContainer implements IContainer {

	private array $items = [];

	public function getServiceList(): array {
		return array_keys($this->items);
	}

	public function set(string $name, $classDefinition, $flags = 0): IContainer {
		$this->items[$name] = $classDefinition;
		return $this;
	}

	public function remove(string $name) {
		unset($this->items[$name]);
	}

	public function has(string $name): bool {
		return array_key_exists($name, $this->items);
	}

	public function get(string $name) {
		return $this->items[$name] ?? null;
	}
}
