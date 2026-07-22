<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use AssistantRuntime\Service\CollectingAgentEventSink;
use Chatbot\Service\ChatbotService;
use Chatbot\Service\EventStreamAgentEventSink;
use EventTransport\Api\IEventStream;
use EventTransport\Api\IEventStreamFactory;
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

	public function testGetHelpReturnsConfiguredServiceHelp(): void {
		$service = $this->makeService($this->createStub(IRequest::class));

		$this->assertSame('SettingsStore backed chatbot service using the selected agent runtime.', $service->getHelp());
	}

	public function testGetOutputReturnsEmptyStringByDefault(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturn(null);

		$this->assertSame('', $this->makeService($request)->getOutput('json'));
	}

	public function testGetOutputReturnsBasePromptWhenBasepromptIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnMap([
			['baseprompt', null, '1']
		]);
		$request->method('request')->willReturn(null);

		$this->assertSame('Test base prompt', $this->makeService($request)->getOutput('json'));
	}

	public function testGetOutputRunsStreamingFlowWhenPromptIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['suggestions', null, null],
			['prompt', null, 'Hello']
		]);

		$this->assertSame('STREAM_OK', $this->makeService($request)->getOutput('json'));
	}

	public function testStreamingUsesSameExecuteOperationWithEventSink(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['suggestions', null, null],
			['prompt', null, 'Hello'],
			['transport_mode', null, 'sse']
		]);

		$stream = $this->createMock(IEventStream::class);
		$stream->expects($this->once())->method('start');
		$streamFactory = $this->createMock(IEventStreamFactory::class);
		$streamFactory->expects($this->once())
			->method('createStream')
			->with('chatbot', $this->stringStartsWith('chat-'))
			->willReturn($stream);

		$executionService = $this->createMock(IAgentExecutionService::class);
		$executionService->expects($this->once())
			->method('execute')
			->with(
				$this->isInstanceOf(AgentExecutionRequest::class),
				$this->isInstanceOf(EventStreamAgentEventSink::class)
			)
			->willReturn(new AgentExecutionResult([]));

		$service = new StreamingTestChatbotService(
			$request,
			$this->createStub(ISettingsStore::class),
			$executionService,
			$streamFactory
		);

		$this->assertSame('', $service->getOutput('json'));
	}

	public function testGetOutputRunsStreamingFlowWhenOnlyResumeIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['suggestions', null, null],
			['prompt', null, null],
			['resume', null, null],
			['resume_handle', null, str_repeat('a', 43)],
			['resume_response', null, 'jo hau rein'],
			['resume_responses', null, null]
		]);

		$this->assertSame('STREAM_OK', $this->makeService($request)->getOutput('json'));
	}

	public function testResumeInputIsIncludedInAgentInputs(): void {
		$handle = str_repeat('b', 43);
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['resume', null, null],
			['resume_handle', null, $handle],
			['resume_response', null, 'in Ordnung'],
			['resume_responses', null, null]
		]);
		$service = $this->makeService($request);

		$inputs = $service->callBuildAgentInputs('system', 'in Ordnung');

		$this->assertSame('system', $inputs['system']);
		$this->assertSame('in Ordnung', $inputs['prompt']);
		$this->assertSame('chat', $inputs['mode']);
		$this->assertSame([
			'resume_handle' => $handle,
			'responses' => [],
			'response_text' => 'in Ordnung'
		], $inputs['resume']);
	}

	public function testExplicitResumeResponsesAreAcceptedAsJson(): void {
		$handle = str_repeat('c', 43);
		$responses = [[
			'request_id' => 'air-1',
			'decision' => 'approve',
			'input' => []
		]];
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['resume', null, null],
			['resume_handle', null, $handle],
			['resume_response', null, null],
			['resume_responses', null, json_encode($responses, JSON_THROW_ON_ERROR)]
		]);
		$service = $this->makeService($request);

		$this->assertSame([
			'resume_handle' => $handle,
			'responses' => $responses
		], $service->callResumeInput());
	}

	public function testRestSuspensionReturnsInteractionRequiredAndPassesResumeInput(): void {
		$handle = str_repeat('d', 43);
		$request = $this->createRestRequest('go', $handle);
		$executionService = $this->createMock(IAgentExecutionService::class);
		$executionService->expects($this->once())
			->method('execute')
			->with(
				$this->callback(static function(AgentExecutionRequest $request) use ($handle): bool {
					$inputs = $request->getInputs();
					return ($inputs['mode'] ?? null) === 'chat'
						&& ($inputs['resume']['resume_handle'] ?? null) === $handle
						&& ($inputs['resume']['response_text'] ?? null) === 'go';
				}),
				$this->isInstanceOf(CollectingAgentEventSink::class)
			)
			->willReturn(new AgentExecutionResult([
				'assistant' => [
					'status' => 'awaiting_approval',
					'resume_handle' => $handle,
					'interaction_requests' => [[
						'id' => 'air-1',
						'kind' => 'approval',
						'title' => 'Confirm update',
						'message' => 'Update the preference?',
						'risk' => 'medium'
					]]
				]
			]));

		$data = json_decode($this->makeService($request, $executionService)->getOutput('json'), true);

		$this->assertSame('interaction_required', $data['type'] ?? null);
		$this->assertSame('awaiting_approval', $data['status'] ?? null);
		$this->assertSame($handle, $data['resume_handle'] ?? null);
		$this->assertSame('air-1', $data['interaction_requests'][0]['id'] ?? null);
	}

	public function testRestCompletedResultRemainsNormalMessage(): void {
		$request = $this->createRestRequest('Hello');
		$executionService = $this->createMock(IAgentExecutionService::class);
		$executionService->method('execute')->willReturn(new AgentExecutionResult([
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

	public function testGetOutputReturnsSuggestionsJsonWhenSuggestionsIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['suggestions', null, '1']
		]);

		$this->assertSame(
			['S1', 'S2', 'S3'],
			json_decode($this->makeService($request)->getOutput('json'), true)
		);
	}

	private function createRestRequest(string $prompt, ?string $resumeHandle = null): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['suggestions', null, null],
			['prompt', null, $prompt],
			['transport_mode', null, 'rest'],
			['resume', null, null],
			['resume_handle', null, $resumeHandle],
			['resume_response', null, $resumeHandle !== null ? $prompt : null],
			['resume_responses', null, null],
			['config_group', null, null],
			['config_name', null, null],
			['reference', null, null]
		]);
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
			$this->createStub(IEventStreamFactory::class)
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

	protected function runStreamingFlow(): string {
		return 'STREAM_OK';
	}

	protected function suggestPrompts(): string {
		return json_encode(['S1', 'S2', 'S3'], JSON_UNESCAPED_UNICODE);
	}

	/** @return array<string,mixed>|null */
	public function callResumeInput(): ?array {
		return $this->getResumeInput();
	}

	/** @return array<string,mixed> */
	public function callBuildAgentInputs(string $systemPrompt, string $userPrompt): array {
		return $this->buildAgentInputs($systemPrompt, $userPrompt);
	}
}

final class StreamingTestChatbotService extends ChatbotService {

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
