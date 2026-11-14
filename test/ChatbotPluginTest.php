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

require_once __DIR__ . '/FakeContainer.php';
