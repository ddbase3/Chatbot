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

use Base3\Chatbot\ChatbotPlugin;
use Base3\Chatbot\Content\ChatbotConfigDisplay;
use Base3\Chatbot\Content\ChatbotDisplay;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotConversationService;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotService;
use Base3\Chatbot\Service\ChatbotServiceRegistry;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use Base3\Chatbot\Service\ChatbotTurnResponder;
use Base3\Chatbot\Service\DummyChatbotService;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use Base3\Chatbot\Service\SessionChatbotTurnRequestStore;
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
