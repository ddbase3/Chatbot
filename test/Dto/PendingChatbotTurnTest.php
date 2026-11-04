<?php declare(strict_types=1);

namespace Chatbot\Test\Dto;

use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\PendingChatbotTurn;
use PHPUnit\Framework\TestCase;

final class PendingChatbotTurnTest extends TestCase {

	public function testRoundtripUsesSanitizedRequestWithoutBrowserChannelId(): void {
		$turn = new PendingChatbotTurn('dummychatbotservice', new ChatbotTurnRequest(['prompt' => ' hello ', 'conversation_channel_id' => 'forged', 'turn_id' => 'abc?']));
		$this->assertSame('dummychatbotservice', $turn->getServiceId());
		$this->assertSame('hello', $turn->getRequest()->getPrompt());
		$this->assertSame('abc', $turn->getRequest()->getTurnId());
		$this->assertArrayNotHasKey('conversation_channel_id', $turn->toArray()['payload']);
		$restored = PendingChatbotTurn::fromArray($turn->toArray());
		$this->assertSame($turn->toArray(), $restored?->toArray());
	}

	public function testMalformedEntriesCannotBeReconstructed(): void {
		$this->assertNull(PendingChatbotTurn::fromArray([]));
		$this->assertNull(PendingChatbotTurn::fromArray(['service_id' => '  ', 'payload' => []]));
		$this->assertNull(PendingChatbotTurn::fromArray(['service_id' => 'service', 'payload' => 'not-array']));
		$this->assertSame('service', PendingChatbotTurn::fromArray(['service_id' => ' service ', 'payload' => []])?->getServiceId());
	}
}
