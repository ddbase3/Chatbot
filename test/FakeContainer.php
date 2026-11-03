<?php declare(strict_types=1);

namespace Chatbot\Test;

use Base3\Api\IContainer;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\ChatbotPlugin;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Service\SessionChatbotTurnRequestStore;
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
