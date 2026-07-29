<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use Base3\Api\IRequest;
use Chatbot\Service\ChatbotTurnRequestFactory;
use PHPUnit\Framework\TestCase;

final class ChatbotTurnRequestFactoryTest extends TestCase {

	public function testConversationChannelIsNotAcceptedFromTheBrowser(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnCallback(
			static fn(string $key): mixed => $key === 'conversation_channel_id' ? 'forged-query-channel' : null
		);
		$request->method('request')->willReturnCallback(
			static fn(string $key): mixed => match ($key) {
				'prompt' => 'Hello',
				'conversation_channel_id' => 'forged-payload-channel',
				default => null
			}
		);

		$turn = (new ChatbotTurnRequestFactory($request))->fromCurrentRequest();

		$this->assertArrayNotHasKey('conversation_channel_id', $turn->getPayload());
		$this->assertSame('Hello', $turn->getPrompt());
	}
}
