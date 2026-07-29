<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use Chatbot\Service\ChatbotConversationChannelResolver;
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
