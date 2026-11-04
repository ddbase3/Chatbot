<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\ChatbotPlugin;
use Chatbot\Content\ChatbotConfigDisplay;
use Chatbot\Content\ChatbotDisplay;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotService;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\DummyChatbotService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use PHPUnit\Framework\TestCase;

final class ChatbotClassmapTechnicalNamesTest extends TestCase {

	public function testDiscoverableServicesKeepTheirLowercaseUnnamespacedIdentifiers(): void {
		$classes = [
			ChatbotConversationChannelResolver::class,
			ChatbotConversationService::class,
			ChatbotExtensionRegistry::class,
			ChatbotExtensionService::class,
			ChatbotOpeningMessageService::class,
			ChatbotService::class,
			ChatbotServiceRegistry::class,
			ChatbotSettingsService::class,
			ChatbotTurnCancellationService::class,
			ChatbotTurnRequestFactory::class,
			ChatbotTurnResponder::class,
			DummyChatbotService::class,
			SessionChatbotConversationDraftStore::class,
			SessionChatbotTurnRequestStore::class
		];
		$names = [];
		foreach ($classes as $class) {
			$shortName = substr($class, strrpos($class, '\\') + 1);
			$this->assertSame(strtolower($shortName), $class::getName());
			$this->assertSame(1, preg_match('/^[a-z][a-z0-9]*$/', $class::getName()));
			$names[] = $class::getName();
		}
		$this->assertCount(count($classes), array_unique($names));
	}

	public function testDisplaysAndPluginUseTheSameUniqueDiscoveryConvention(): void {
		$classes = [ChatbotPlugin::class, ChatbotConfigDisplay::class, ChatbotDisplay::class];
		$ids = [];
		foreach ($classes as $class) {
			$shortName = substr($class, strrpos($class, '\\') + 1);
			$this->assertSame(strtolower($shortName), $class::getName());
			$ids[] = $class::getName();
		}
		$this->assertCount(3, array_unique($ids));
	}

	public function testRealAndDummyBackendsAdvertiseTheirDifferentCapabilities(): void {
		$this->assertSame('Agent-backed Chatbot Service', ChatbotService::getServiceLabel());
		$this->assertStringContainsString('agent runtime', ChatbotService::getServiceDescription());
		$this->assertSame('Dummy Chatbot Service', DummyChatbotService::getServiceLabel());
		$this->assertStringContainsString('without external AI dependencies', DummyChatbotService::getServiceDescription());
		$this->assertNotSame(ChatbotService::getName(), DummyChatbotService::getName());
	}
}
