<?php declare(strict_types=1);

namespace Chatbot\Test\Dto;

use Chatbot\Dto\ChatbotTurnResult;
use PHPUnit\Framework\TestCase;

final class ChatbotTurnResultTest extends TestCase {

	public function testMessageHasStableIdAndTimestampedText(): void {
		$result = ChatbotTurnResult::message('msg-1', 'Hello');
		$this->assertSame('message', $result->getType());
		$this->assertSame('msg-1', $result->getId());
		$this->assertSame('Hello', $result->getText());
		$this->assertSame('message', $result->toArray()['type']);
		$this->assertNotEmpty($result->toArray()['meta']['timestamp']);
	}

	public function testInteractionRequiredPreservesResumeDataAndOptionalMetadata(): void {
		$result = ChatbotTurnResult::interactionRequired('awaiting_approval', 'scope.resume', [['id' => 'approval-1']], '2026-11-04T00:00:00Z', ' interaction-id ', ' created-at ');
		$this->assertSame('interaction_required', $result->getType());
		$this->assertSame('interaction-id', $result->toArray()['interaction_id']);
		$this->assertSame('scope.resume', $result->toArray()['resume_handle']);
		$this->assertSame('awaiting_approval', $result->toArray()['status']);
		$this->assertSame([['id' => 'approval-1']], $result->toArray()['interaction_requests']);
		$this->assertSame('created-at', $result->toArray()['created_at']);
		$this->assertSame('', $result->getText());
		$this->assertNotEmpty($result->getId());
	}

	public function testCancelledHasNoUserVisibleText(): void {
		$result = ChatbotTurnResult::cancelled();
		$this->assertSame('cancelled', $result->getType());
		$this->assertSame('', $result->getId());
		$this->assertSame('', $result->getText());
		$this->assertSame('cancelled', $result->toArray()['status']);
	}

	public function testErrorHasUniqueIdentifiersAndMessage(): void {
		$first = ChatbotTurnResult::error('failure');
		$second = ChatbotTurnResult::error('failure');
		$this->assertSame('error', $first->getType());
		$this->assertSame('failure', $first->getText());
		$this->assertTrue(str_starts_with($first->getId(), 'msg_'));
		$this->assertNotSame($first->getId(), $second->getId());
	}
}
