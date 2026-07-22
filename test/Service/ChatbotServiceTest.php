<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use AssistantRuntime\Service\CollectingAgentEventSink;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Service\ChatbotService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chatbot\Service\ChatbotService
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatbotServiceTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('chatbotservice', ChatbotService::getName());
	}

	public function testGetOutputReturnsEmptyStringWithoutTurn(): void {
		$request = $this->createRequest([]);

		$this->assertSame('', $this->makeService($request)->getOutput('json'));
	}

	public function testRestOutputUsesExplicitTurnExecution(): void {
		$request = $this->createRequest([
			'prompt' => 'Hello',
			'transport_mode' => 'rest'
		]);
		$executionService = $this->createMock(IAgentExecutionService::class);
		$executionService->expects($this->once())
			->method('execute')
			->with(
				$this->callback(static function(AgentExecutionRequest $request): bool {
					$inputs = $request->getInputs();
					return ($inputs['prompt'] ?? null) === 'Hello'
						&& ($inputs['mode'] ?? null) === 'chat';
				}),
				$this->isInstanceOf(CollectingAgentEventSink::class)
			)
			->willReturn(new AgentExecutionResult([
				'assistant' => [
					'message' => [
						'id' => 'msg-1',
						'content' => 'Hello back'
					]
				]
			]));

		$data = json_decode($this->makeService($request, $executionService)->getOutput('json'), true);

		$this->assertSame('message', $data['type'] ?? null);
		$this->assertSame('msg-1', $data['id'] ?? null);
		$this->assertSame('Hello back', $data['text'] ?? null);
	}

	public function testExecuteTurnPassesResumePayload(): void {
		$handle = str_repeat('a', 43);
		$executionService = $this->createMock(IAgentExecutionService::class);
		$executionService->expects($this->once())
			->method('execute')
			->with(
				$this->callback(static function(AgentExecutionRequest $request) use ($handle): bool {
					$inputs = $request->getInputs();
					return ($inputs['resume']['resume_handle'] ?? null) === $handle
						&& ($inputs['resume']['response_text'] ?? null) === 'approved';
				}),
				$this->isInstanceOf(IAgentEventSink::class)
			)
			->willReturn(new AgentExecutionResult([
				'assistant' => [
					'message' => [
						'id' => 'msg-2',
						'content' => 'Done'
					]
				]
			]));

		$service = $this->makeService($this->createRequest([]), $executionService);
		$result = $service->executeTurn(
			new ChatbotTurnRequest([
				'resume' => [
					'resume_handle' => $handle,
					'response_text' => 'approved',
					'responses' => []
				]
			]),
			new CollectingAgentEventSink()
		);

		$this->assertSame('message', $result->getType());
		$this->assertSame('Done', $result->getText());
	}

	public function testSuspendedResultReturnsInteractionRequired(): void {
		$handle = str_repeat('b', 43);
		$executionService = $this->createStub(IAgentExecutionService::class);
		$executionService->method('execute')->willReturn(new AgentExecutionResult([
			'assistant' => [
				'status' => 'awaiting_approval',
				'resume_handle' => $handle,
				'interaction_requests' => [[
					'id' => 'air-1',
					'kind' => 'approval',
					'title' => 'Confirm update'
				]]
			]
		]));

		$result = $this->makeService($this->createRequest([]), $executionService)->executeTurn(
			new ChatbotTurnRequest(['prompt' => 'go']),
			new CollectingAgentEventSink()
		);

		$this->assertSame('interaction_required', $result->getType());
		$this->assertSame($handle, $result->toArray()['resume_handle'] ?? null);
		$this->assertSame('air-1', $result->toArray()['interaction_requests'][0]['id'] ?? null);
	}

	/** @param array<string,mixed> $values */
	private function createRequest(array $values): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnCallback(
			static fn(string $key): mixed => $values[$key] ?? null
		);
		$request->method('request')->willReturnCallback(
			static fn(string $key): mixed => $values[$key] ?? null
		);

		return $request;
	}

	private function makeService(
		IRequest $request,
		?IAgentExecutionService $executionService = null
	): TestableChatbotService {
		return new TestableChatbotService(
			$request,
			$this->createStub(ISettingsStore::class),
			$executionService ?? $this->createStub(IAgentExecutionService::class),
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder()
		);
	}
}

final class TestableChatbotService extends ChatbotService {

	protected function getBasePrompt(): string {
		return 'Test base prompt';
	}

	protected function getSimpleAgentFlow(): ?array {
		return [
			'nodes' => [[
				'id' => 'assistant',
				'type' => 'aiassistantnode'
			]],
			'connections' => []
		];
	}
}
