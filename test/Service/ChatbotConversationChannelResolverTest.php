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

use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationChannelResolverTest extends TestCase {

	public function testResolveUsesThePersistedChatbotIdentity(): void {
		$resolver = new ChatbotConversationChannelResolver();

		$this->assertSame(
			'chatbot:' . hash('sha256', "chatbot\0example"),
			$resolver->resolve('chatbot', 'example')
		);
	}

	public function testResolveRequiresACompleteSettingsIdentity(): void {
		$resolver = new ChatbotConversationChannelResolver();

		$this->assertSame('', $resolver->resolve('', 'example'));
		$this->assertSame('', $resolver->resolve('chatbot', ''));
	}
}
