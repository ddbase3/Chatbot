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

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Api\IClassMap;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Api\IRequest;
use Base3\Session\Api\ISession;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Service\ChatbotConversationContextFactory;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SessionChatbotTurnRequestStore;

class ChatbotPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string {
		return 'chatbotplugin';
	}

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(
				ChatbotConversationContextFactory::class,
				fn($c) => new ChatbotConversationContextFactory(
					$c->get(IAccesscontrol::class),
					$c->get(ISession::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ChatbotTurnRequestFactory::class,
				fn($c) => new ChatbotTurnRequestFactory(
					$c->get(IRequest::class),
					$c->get(ChatbotConversationContextFactory::class)
				),
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
			);
	}
}
