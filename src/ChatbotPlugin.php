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

namespace Chatbot;

use Base3\Api\IClassMap;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\Session\Api\ISession;
use Chatbot\Api\IChatbotAppearanceProvider;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use Chatbot\Service\StaticChatbotAppearanceProvider;

class ChatbotPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string {
		return 'chatbotplugin';
	}

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(
				IChatbotAppearanceProvider::class,
				fn() => new StaticChatbotAppearanceProvider(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotConversationChannelResolver::class,
				fn() => new ChatbotConversationChannelResolver(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotSettingsService::class,
				fn($c) => new ChatbotSettingsService(
					$c->get(ISettingsStore::class),
					$c->get(IAgentRuntimeSelector::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotOpeningMessageService::class,
				fn($c) => new ChatbotOpeningMessageService(
					$c->get(IAgentTextTaskService::class),
					$c->get(ChatbotSettingsService::class),
					$c->get(ILanguage::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				SessionChatbotConversationDraftStore::class,
				fn($c) => new SessionChatbotConversationDraftStore($c->get(ISession::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotConversationService::class,
				fn($c) => new ChatbotConversationService(
					$c->get(IAgentConversationService::class),
					$c->get(IAgentTextTaskService::class),
					$c->get(IAgentSuspensionRepository::class),
					$c->get(ChatbotSettingsService::class),
					$c->get(ChatbotConversationChannelResolver::class),
					$c->get(ChatbotOpeningMessageService::class),
					$c->get(SessionChatbotConversationDraftStore::class),
					$c->get(ILogger::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotTurnRequestFactory::class,
				fn($c) => new ChatbotTurnRequestFactory($c->get(IRequest::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotTurnResponder::class,
				fn() => new ChatbotTurnResponder(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				SessionChatbotTurnRequestStore::class,
				fn() => new SessionChatbotTurnRequestStore(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				IChatbotTurnRequestStore::class,
				fn($c) => $c->get(SessionChatbotTurnRequestStore::class),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotServiceRegistry::class,
				fn($c) => new ChatbotServiceRegistry($c->get(IClassMap::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotExtensionRegistry::class,
				fn($c) => new ChatbotExtensionRegistry($c->get(IClassMap::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotExtensionService::class,
				fn($c) => new ChatbotExtensionService(
					$c->get(ChatbotExtensionRegistry::class),
					$c->get(ISettingsStore::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			);
	}
}
