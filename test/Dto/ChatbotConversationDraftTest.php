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

use Base3\Chatbot\Dto\ChatbotConversationDraft;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationDraftTest extends TestCase {

	public function testStorageRoundtripPreservesConversationAndClientPayloadOmitsInternalFields(): void {
		$draft = new ChatbotConversationDraft(str_repeat('a', 48), 'conversation-1', 'Welcome', [['role' => 'assistant', 'content' => 'Hi']], 1700000000);
		$this->assertSame(str_repeat('a', 48), $draft->getId());
		$this->assertSame('conversation-1', $draft->getConversationId());
		$this->assertSame('Welcome', $draft->getOpeningMessage());
		$this->assertSame([['role' => 'assistant', 'content' => 'Hi']], $draft->getMessages());
		$this->assertSame(1700000000, $draft->getCreatedAt());
		$this->assertSame($draft->toStorageArray(), ChatbotConversationDraft::fromArray($draft->toStorageArray())->toStorageArray());
		$this->assertSame(['id' => str_repeat('a', 48), 'opening_message' => 'Welcome', 'messages' => [['role' => 'assistant', 'content' => 'Hi']]], $draft->toClientArray());
		$this->assertArrayNotHasKey('conversation_id', $draft->toClientArray());
	}

	public function testFromArrayNormalizesListAndTechnicalIds(): void {
		$draft = ChatbotConversationDraft::fromArray([
			'id' => ' ' . str_repeat('f', 48) . ' ',
			'conversation_id' => ' conversation-1 ',
			'opening_message' => 'Hello',
			'messages' => [3 => ['role' => 'user', 'content' => 'test']],
			'created_at' => '123'
		]);
		$this->assertSame('conversation-1', $draft->getConversationId());
		$this->assertSame([['role' => 'user', 'content' => 'test']], $draft->getMessages());
		$this->assertSame(123, $draft->getCreatedAt());
		$this->assertSame([], ChatbotConversationDraft::fromArray(['id' => str_repeat('a', 48), 'conversation_id' => 'ok', 'messages' => 'invalid'])->getMessages());
	}

	public function testInvalidDraftIdsAreRejected(): void {
		foreach (['', 'abc', str_repeat('g', 48), str_repeat('a', 49)] as $id) {
			try {
				new ChatbotConversationDraft($id, 'conversation-1', '', [], time());
				$this->fail('Invalid draft identifier was accepted.');
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testInvalidConversationIdentifiersAreRejected(): void {
		foreach (['', ' a ', 'a/b', str_repeat('a', 101)] as $conversationId) {
			try {
				new ChatbotConversationDraft(str_repeat('a', 48), $conversationId, '', [], time());
				$this->fail('Invalid conversation id was accepted.');
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
