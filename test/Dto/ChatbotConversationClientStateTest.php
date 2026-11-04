<?php declare(strict_types=1);

namespace Chatbot\Test\Dto;

use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentExecutionStatus;
use AssistantFoundation\Dto\AgentInteractionResponse;
use AssistantFoundation\Dto\AgentSuspensionResolution;
use AssistantFoundation\Dto\AgentSuspensionState;
use Chatbot\Dto\ChatbotConversationClientState;
use Chatbot\Dto\ChatbotConversationDraft;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationClientStateTest extends TestCase {

	public function testEmptyStateAddsExplicitDraftAndInteractionFields(): void {
		$state = new AgentConversationState([], null, [], 'assistant');
		$result = new ChatbotConversationClientState($state);
		$this->assertSame($state, $result->getState());
		$this->assertNull($result->getDraft());
		$this->assertNull($result->getPendingSuspension());
		$this->assertSame([], $result->getSuspensions());
		$this->assertSame(['conversations' => [], 'active_conversation' => null, 'messages' => [], 'node_id' => 'assistant', 'warnings' => [], 'draft' => null, 'pending_interaction' => null, 'interactions' => []], $result->toArray());
	}

	public function testDraftProjectionOmitsInternalDraftConversationAndTimestamp(): void {
		$draft = new ChatbotConversationDraft(str_repeat('a', 48), 'private-id', 'Welcome', [['role' => 'assistant', 'content' => 'Hi']], 1700000000);
		$result = new ChatbotConversationClientState(new AgentConversationState([], null, [], 'assistant'), $draft);
		$this->assertSame($draft, $result->getDraft());
		$this->assertSame($draft->toClientArray(), $result->toArray()['draft']);
		$this->assertArrayNotHasKey('conversation_id', $result->toArray()['draft']);
		$this->assertArrayNotHasKey('created_at', $result->toArray()['draft']);
	}

	public function testPendingSuspensionProjectsRequestsButNotActionPayload(): void {
		$suspension = new AgentSuspensionState(true, AgentExecutionStatus::AWAITING_APPROVAL, [
			['id' => ' r1 ', 'kind' => 'approval', 'title' => ' Write ', 'message' => ' Please confirm ', 'summary' => ['target' => 'course'], 'risk' => ' high ', 'action' => ['arguments' => ['secret' => 'private']]],
			'invalid'
		], 'secret.resume', 'created', 'expires', AgentSuspensionState::LIFECYCLE_ACTIVE, null, 'suspension-1');
		$result = new ChatbotConversationClientState(new AgentConversationState([], null, [], 'assistant'), null, $suspension, [$suspension]);
		$this->assertSame($suspension, $result->getPendingSuspension());
		$this->assertCount(1, $result->toArray()['interactions']);
		$pending = $result->toArray()['pending_interaction'];
		$this->assertSame('secret.resume', $pending['resume_handle']);
		$this->assertSame('suspension-1', $pending['id']);
		$this->assertSame([['id' => 'r1', 'kind' => 'approval', 'title' => 'Write', 'message' => 'Please confirm', 'summary' => ['target' => 'course'], 'risk' => 'high']], $pending['interaction_requests']);
		$this->assertArrayNotHasKey('action', $pending['interaction_requests'][0]);
	}

	public function testResolvedSuspensionOmitsResumeHandleAndSanitizesResolution(): void {
		$resolution = new AgentSuspensionResolution([new AgentInteractionResponse('r1', 'approve', ['secret' => 'never-expose'], 'private note')], 'explicit', '2026-11-03T10:00:00Z');
		$resolved = new AgentSuspensionState(false, AgentExecutionStatus::COMPLETED, [], 'old.resume', 'created', 'expires', AgentSuspensionState::LIFECYCLE_RESOLVED, $resolution, 'resolved-id');
		$result = new ChatbotConversationClientState(new AgentConversationState([], null, [], 'assistant'), null, null, [$resolved]);
		$visible = $result->toArray()['interactions'][0];
		$this->assertSame('', $visible['resume_handle']);
		$this->assertSame('approved', $visible['resolution']['outcome']);
		$this->assertSame('explicit', $visible['resolution']['source']);
		$this->assertSame([['request_id' => 'r1', 'decision' => 'approve']], $visible['resolution']['responses']);
		$this->assertArrayNotHasKey('input', $visible['resolution']['responses'][0]);
		$this->assertArrayNotHasKey('note', $visible['resolution']['responses'][0]);
	}
}
