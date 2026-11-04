<?php declare(strict_types=1);

namespace Chatbot\Test\Dto;

use AssistantFoundation\Dto\AgentAction;
use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentExecutionStatus;
use AssistantFoundation\Dto\AgentInteractionRequest;
use AssistantFoundation\Dto\AgentInteractionResponse;
use AssistantFoundation\Dto\AgentSuspensionResolution;
use AssistantFoundation\Dto\AgentSuspensionState;
use Chatbot\Dto\ChatbotConversationClientState;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationProjectionBoundariesTest extends TestCase {

	public function testTypedInteractionOmitsToolArgumentsAndInternalFingerprint(): void {
		$request = new AgentInteractionRequest(
			'approval-1',
			AgentInteractionRequest::KIND_APPROVAL,
			new AgentAction('action-id', 'tool_call', 'delete-item', ['secret_key' => 'private']),
			'private-fingerprint',
			'Review',
			'Approve the change',
			['count' => 2],
			'high',
			['internal' => true]
		);
		$pending = $this->newSuspensionFixture(true, AgentExecutionStatus::AWAITING_APPROVAL, [$request]);
		$projection = new ChatbotConversationClientState($this->newEmptyConversationState(), null, $pending, [$pending]);
		$visible = $projection->toArray()['pending_interaction']['interaction_requests'][0];

		$this->assertSame(['id' => 'approval-1', 'kind' => 'approval', 'title' => 'Review', 'message' => 'Approve the change', 'summary' => ['count' => 2], 'risk' => 'high'], $visible);
		$this->assertArrayNotHasKey('action', $visible);
		$this->assertArrayNotHasKey('action_fingerprint', $visible);
		$this->assertArrayNotHasKey('metadata', $visible);
	}

	public function testArrayInteractionStripsUnknownFieldsAndNormalizesScalars(): void {
		$pending = $this->newSuspensionFixture(true, AgentExecutionStatus::AWAITING_INPUT, [[
			'id' => ' item-1 ', 'kind' => ' clarification ', 'title' => ' Title ', 'message' => ' Message ',
			'summary' => 'unstructured', 'risk' => ' low ', 'action' => ['token' => 'never-expose'],
			'resume_handle' => 'private'
		]]);
		$visible = (new ChatbotConversationClientState($this->newEmptyConversationState(), null, $pending))
			->toArray()['pending_interaction']['interaction_requests'][0];
		$this->assertSame(['id' => 'item-1', 'kind' => 'clarification', 'title' => 'Title', 'message' => 'Message', 'summary' => [], 'risk' => 'low'], $visible);
	}

	public function testMalformedInteractionEntriesAreExcludedWithoutDroppingValidOnes(): void {
		$pending = $this->newSuspensionFixture(true, AgentExecutionStatus::AWAITING_INPUT, [
			false, null, 'ignored', ['id' => 'valid-1', 'kind' => 'clarification'], 42,
			['id' => 'valid-2', 'kind' => 'approval', 'summary' => ['a' => 1]]
		]);
		$visible = (new ChatbotConversationClientState($this->newEmptyConversationState(), null, $pending))
			->toArray()['pending_interaction']['interaction_requests'];
		$this->assertCount(2, $visible);
		$this->assertSame('valid-1', $visible[0]['id']);
		$this->assertSame('valid-2', $visible[1]['id']);
		$this->assertSame(['a' => 1], $visible[1]['summary']);
	}

	public function testResolvedSuspensionCannotLeakFormerResumeHandle(): void {
		$resolved = $this->newSuspensionFixture(false, AgentExecutionStatus::COMPLETED, [], AgentSuspensionState::LIFECYCLE_RESOLVED);
		$visible = (new ChatbotConversationClientState($this->newEmptyConversationState(), null, null, [$resolved]))
			->toArray()['interactions'][0];
		$this->assertSame('resolved', $visible['lifecycle']);
		$this->assertSame('', $visible['resume_handle']);
		$this->assertNull($visible['resolution']);
	}

	public function testExpiredSuspensionCannotLeakFormerResumeHandle(): void {
		$expired = $this->newSuspensionFixture(false, AgentExecutionStatus::FAILED, [], AgentSuspensionState::LIFECYCLE_EXPIRED);
		$visible = (new ChatbotConversationClientState($this->newEmptyConversationState(), null, null, [$expired]))
			->toArray()['interactions'][0];
		$this->assertSame('expired', $visible['lifecycle']);
		$this->assertSame('', $visible['resume_handle']);
	}

	public function testResolutionShowsOnlyDecisionAndRequestId(): void {
		$resolution = new AgentSuspensionResolution([
			new AgentInteractionResponse('request-1', 'deny', ['access_token' => 'private'], 'hidden note', ['internal' => 'private'])
		], ' host ', ' 2026-11-04T09:00:00Z ');
		$resolved = $this->newSuspensionFixture(false, AgentExecutionStatus::COMPLETED, [], AgentSuspensionState::LIFECYCLE_RESOLVED, $resolution);
		$visible = (new ChatbotConversationClientState($this->newEmptyConversationState(), null, null, [$resolved]))
			->toArray()['interactions'][0]['resolution'];
		$this->assertSame('denied', $visible['outcome']);
		$this->assertSame('host', $visible['source']);
		$this->assertSame('2026-11-04T09:00:00Z', $visible['resolved_at']);
		$this->assertSame([['request_id' => 'request-1', 'decision' => 'deny']], $visible['responses']);
	}

	public function testMixedResolutionNeverExposesPrivateResponses(): void {
		$resolution = new AgentSuspensionResolution([
			new AgentInteractionResponse('request-1', 'approve', ['secret' => 1]),
			new AgentInteractionResponse('request-2', 'deny', ['secret' => 2])
		]);
		$resolved = $this->newSuspensionFixture(false, AgentExecutionStatus::COMPLETED, [], AgentSuspensionState::LIFECYCLE_RESOLVED, $resolution);
		$visible = (new ChatbotConversationClientState($this->newEmptyConversationState(), null, null, [$resolved]))
			->toArray()['interactions'][0]['resolution'];
		$this->assertSame('mixed', $visible['outcome']);
		$this->assertSame([['request_id' => 'request-1', 'decision' => 'approve'], ['request_id' => 'request-2', 'decision' => 'deny']], $visible['responses']);
	}

	public function testHistoricalInteractionsDoNotBecomePendingInteractions(): void {
		$resolved = $this->newSuspensionFixture(false, AgentExecutionStatus::COMPLETED, [] , AgentSuspensionState::LIFECYCLE_RESOLVED);
		$projection = new ChatbotConversationClientState($this->newEmptyConversationState(), null, null, [$resolved]);
		$this->assertNull($projection->getPendingSuspension());
		$this->assertNull($projection->toArray()['pending_interaction']);
		$this->assertCount(1, $projection->toArray()['interactions']);
	}

	/** @param array<int,mixed> $requests */
	private function newSuspensionFixture(
		bool $active,
		string $status,
		array $requests,
		string $lifecycle = AgentSuspensionState::LIFECYCLE_ACTIVE,
		?AgentSuspensionResolution $resolution = null
	): AgentSuspensionState {
		return new AgentSuspensionState(
			$active,
			$status,
			$requests,
			'private.resume.handle',
			'2026-11-04T08:00:00Z',
			'2026-11-04T10:00:00Z',
			$lifecycle,
			$resolution,
			'suspension-1'
		);
	}

	private function newEmptyConversationState(): AgentConversationState {
		return new AgentConversationState([], null, [], 'assistant');
	}
}
