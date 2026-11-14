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

use Base3\CoreFoundation\Api\IRequest;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use PHPUnit\Framework\TestCase;

final class ChatbotTurnRequestFactoryTest extends TestCase {

	public function testConversationChannelIsNotAcceptedFromTheBrowser(): void {
		$request = $this->createStub(IRequest::class);
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
