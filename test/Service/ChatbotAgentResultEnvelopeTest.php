<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentAction;
use AssistantFoundation\Dto\AgentExecutionResult;
use AssistantFoundation\Dto\AgentExecutionStatus;
use AssistantFoundation\Dto\AgentInteractionRequest;
use AssistantFoundation\Dto\AgentResult;
use AssistantFoundation\Dto\AgentState;
use AssistantFoundation\Dto\AgentSuspensionState;
use Base3\Api\IClassMap;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\TestCase;
use Test\Chatbot\Service\TestableChatbotService;

final class ChatbotAgentResultEnvelopeTest extends TestCase {

	public function testCanonicalCancellationStatusOverridesSuccessfulAssistantMessage(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult(
			['assistant' => ['message' => ['id' => 'unexpected', 'content' => 'Ignored']]],
			[],
			new AgentResult(AgentExecutionStatus::CANCELLED, AgentState::empty())
		));
		$this->assertSame('cancelled', $result['type']);
		$this->assertSame('', $result['text']);
	}

	public function testCanonicalSuspensionProjectsTypedInteractionRequests(): void {
		$approval = new AgentInteractionRequest(
			'approval-1',
			AgentInteractionRequest::KIND_APPROVAL,
			new AgentAction('action-1', AgentAction::TYPE_TOOL_CALL, 'update-course', ['secret' => 'not-display']),
			'fingerprint-1',
			'Approve update',
			'Please confirm',
			['resource' => 'course'],
			'high'
		);
		$suspension = new AgentSuspensionState(
			true,
			AgentExecutionStatus::AWAITING_APPROVAL,
			[$approval, ['id' => 'custom-2', 'kind' => 'clarification'], 'ignored'],
			'resume.handle',
			'created',
			'expires'
		);
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult(
			['assistant' => ['message' => ['id' => 'ignored', 'content' => 'Not completed']]],
			[],
			new AgentResult(AgentExecutionStatus::AWAITING_APPROVAL, AgentState::empty()->withSuspension($suspension))
		));

		$this->assertSame('interaction_required', $result['type']);
		$this->assertSame(AgentExecutionStatus::AWAITING_APPROVAL, $result['status']);
		$this->assertSame('resume.handle', $result['resume_handle']);
		$this->assertCount(2, $result['interaction_requests']);
		$this->assertSame($approval->toArray(), $result['interaction_requests'][0]);
		$this->assertSame('custom-2', $result['interaction_requests'][1]['id']);
	}

	public function testAwaitingAgentResultWithoutSuspensionFallsBackToReturnedMessage(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult(
			['assistant' => ['message' => ['id' => 'finished', 'content' => 'Already answered']]],
			[],
			new AgentResult(AgentExecutionStatus::AWAITING_INPUT, AgentState::empty())
		));
		$this->assertSame('message', $result['type']);
		$this->assertSame('finished', $result['id']);
	}

	public function testUnsuspendedStateCannotCreateInteractionWithoutHandleAndRequests(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult([
			'assistant' => [
				'status' => AgentExecutionStatus::AWAITING_INPUT,
				'resume_handle' => 'resume.handle',
				'interaction_requests' => [],
				'message' => ['id' => 'message', 'content' => 'Fallback']
			]
		]));
		$this->assertSame('message', $result['type']);
		$this->assertSame('Fallback', $result['text']);
	}

	public function testMixedArrayAndScalarInteractionRequestsDiscardUnstructuredEntries(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult([
			'confirmation' => [
				'status' => AgentExecutionStatus::AWAITING_INPUT,
				'resume_handle' => 'resume.handle',
				'interaction_requests' => [null, 'not-an-interaction', ['id' => 'valid-1', 'kind' => 'clarification'], 17]
			]
		]));
		$this->assertSame('interaction_required', $result['type']);
		$this->assertSame([['id' => 'valid-1', 'kind' => 'clarification']], $result['interaction_requests']);
	}

	public function testNumericMessageIdentifierIsPreservedAsString(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult([
			'assistant' => ['message' => ['id' => 0, 'content' => 123.5]]
		]));
		$this->assertSame('message', $result['type']);
		$this->assertSame('0', $result['id']);
		$this->assertSame('123.5', $result['text']);
	}

	public function testUnstructuredMessageIdentifierGeneratesAStableFormat(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult([
			'assistant' => ['message' => ['id' => ['invalid'], 'content' => null]]
		]));
		$this->assertSame('message', $result['type']);
		$this->assertTrue(str_starts_with($result['id'], 'msg_'));
		$this->assertSame('', $result['text']);
	}

	public function testInvalidUtf8InsideStructuredContentCannotProduceBrokenJson(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult([
			'assistant' => ['message' => ['id' => 'message-1', 'content' => ['bad' => "\xB1"]]]
		]));
		$this->assertSame('message', $result['type']);
		$this->assertSame('', $result['text']);
	}

	public function testNonArrayMessageEnvelopeDoesNotMasqueradeAsSuccessfulTurn(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult([
			'assistant' => ['message' => 'not-structured'],
			'another' => ['error' => 'No usable assistant message']
		]));
		$this->assertSame('error', $result['type']);
		$this->assertStringContainsString('No usable assistant message', $result['text']);
	}

	public function testAgentResultStatusOtherThanCancellationKeepsUsableAssistantOutput(): void {
		$result = $this->evaluateExecutionEnvelope(new AgentExecutionResult(
			['assistant' => ['message' => ['id' => 'partial-answer', 'content' => 'Available text']]],
			[],
			new AgentResult(AgentExecutionStatus::PARTIAL, AgentState::empty())
		));
		$this->assertSame('message', $result['type']);
		$this->assertSame('Available text', $result['text']);
	}

	/** @return array<string,mixed> */
	private function evaluateExecutionEnvelope(AgentExecutionResult $response): array {
		$execution = $this->createStub(IAgentExecutionService::class);
		$execution->method('execute')->willReturn($response);
		$request = $this->createStub(IRequest::class);
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn(['chatbot_backend' => 'runtime:missionbay']);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('selectRuntimeId')->willReturn('missionbay');
		$config = new ChatbotSettingsService($store, $selector);
		$language = $this->createStub(ILanguage::class);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		$service = new TestableChatbotService(
			$request,
			$config,
			$execution,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder(
				$language,
				new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
				$this->createStub(ILogger::class)
			),
			new ChatbotConversationChannelResolver(),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($map), $store),
			new ChatbotOpeningMessageService($this->createStub(IAgentTextTaskService::class), $config, $language)
		);

		return $service->executeTurn(new ChatbotTurnRequest(['prompt' => 'User request']), $this->createStub(IAgentEventSink::class))->toArray();
	}
}

require_once __DIR__ . '/TestableChatbotService.php';
