<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Session\Api\ISession;
use Chatbot\Service\ChatbotConversationContextFactory;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationContextFactoryTest extends TestCase {

	public function testUsesAuthenticatedUserAsServerOwnedScope(): void {
		$accesscontrol = $this->createStub(IAccesscontrol::class);
		$accesscontrol->method('getUserId')->willReturn(42);
		$session = $this->createMock(ISession::class);
		$session->expects($this->never())->method('start');
		$payload = (new ChatbotConversationContextFactory($accesscontrol, $session))->enrich([
			'conversation_owner_key' => str_repeat('f', 64)
		]);

		self::assertSame(hash('sha256', 'user:42'), $payload['conversation_owner_key'] ?? null);
	}

	public function testUsesSessionForAnonymousUser(): void {
		$accesscontrol = $this->createStub(IAccesscontrol::class);
		$accesscontrol->method('getUserId')->willReturn(null);
		$session = $this->createMock(ISession::class);
		$session->expects($this->once())->method('start');
		$session->method('getId')->willReturn('session-1');
		$payload = (new ChatbotConversationContextFactory($accesscontrol, $session))->enrich([]);

		self::assertSame(hash('sha256', 'session:session-1'), $payload['conversation_owner_key'] ?? null);
	}
}
