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

class ChatbotPluginTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('chatbotplugin', ChatbotPlugin::getName());
	}

	public function testInitRegistersChatbotTransportServices(): void {
		$container = new FakeContainer();
		$plugin = new ChatbotPlugin($container);

		$plugin->init();

		$this->assertTrue($container->has(ChatbotPlugin::getName()));
		$this->assertTrue($container->has(ChatbotConversationChannelResolver::class));
		$this->assertTrue($container->has(ChatbotSettingsService::class));
		$this->assertTrue($container->has(ChatbotOpeningMessageService::class));
		$this->assertTrue($container->has(ChatbotConversationService::class));
		$this->assertTrue($container->has(SessionChatbotConversationDraftStore::class));
		$this->assertTrue($container->has(ChatbotTurnRequestFactory::class));
		$this->assertTrue($container->has(ChatbotTurnResponder::class));
		$this->assertTrue($container->has(SessionChatbotTurnRequestStore::class));
		$this->assertTrue($container->has(IChatbotTurnRequestStore::class));
		$this->assertTrue($container->has(ChatbotServiceRegistry::class));
	}
}

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
