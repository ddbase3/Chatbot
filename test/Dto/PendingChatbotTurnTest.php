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

namespace Base3\Chatbot\Test\Dto;

use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Dto\PendingChatbotTurn;
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
