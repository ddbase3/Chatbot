<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
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

final class ChatbotAgentExecutionEdgeTest extends TestCase {

	public function testBasePromptRequestAvoidsAgentExecution(): void {
		$runtime = $this->createMock(IAgentExecutionService::class);
		$runtime->expects($this->never())->method('execute');
		$this->assertSame('Test base prompt', $this->service($runtime, ['baseprompt' => ''])->getOutput());
	}

	public function testSuggestionRequestStripsJsonCodeFence(): void {
		$runtime = $this->createMock(IAgentExecutionService::class);
		$runtime->expects($this->once())->method('execute')->willReturnCallback(function(AgentExecutionRequest $request): AgentExecutionResult {
			$this->assertSame('suggestions', $request->getInputs()['mode']);
			$this->assertSame('Generate suggestions.', $request->getInputs()['prompt']);
			return new AgentExecutionResult(['assistant' => ['message' => ['content' => "```json\n[\"First\",\"Second\"]\n```"]]]);
		});
		$answer = json_decode($this->service($runtime, ['suggestions' => '1'])->getOutput(), true);
		$this->assertSame(['First', 'Second'], $answer);
	}

	public function testSuggestionsUseTopLevelMessageWhenAssistantNodeIsMissing(): void {
		$runtime = $this->createStub(IAgentExecutionService::class);
		$runtime->method('execute')->willReturn(new AgentExecutionResult(['message' => ['content' => '{"suggestions":["Hello"]}']]));
		$this->assertSame(['suggestions' => ['Hello']], json_decode($this->service($runtime, ['suggestions' => '1'])->getOutput(), true));
	}

	public function testMalformedSuggestionJsonReturnsRawAndCleanVersion(): void {
		$runtime = $this->createStub(IAgentExecutionService::class);
		$runtime->method('execute')->willReturn(new AgentExecutionResult(['assistant' => ['message' => ['content' => "```json\ninvalid\n```"]]]));
		$result = json_decode($this->service($runtime, ['suggestions' => '1'])->getOutput(), true);
		$this->assertSame('Invalid JSON from suggestions model', $result['error']);
		$this->assertSame("```json\ninvalid\n```", $result['raw']);
		$this->assertSame('invalid', $result['clean']);
	}

	public function testMissingSuggestionMessageReturnsExplicitModelError(): void {
		$runtime = $this->createStub(IAgentExecutionService::class);
		$runtime->method('execute')->willReturn(new AgentExecutionResult([]));
		$result = json_decode($this->service($runtime, ['suggestions' => '1'])->getOutput(), true);
		$this->assertSame('Invalid JSON from suggestions model', $result['error']);
		$this->assertSame('', $result['raw']);
	}

	public function testNamedAssistantNodeHasPriorityOverDefaultAssistant(): void {
		$result = $this->execute([
			'custom' => ['message' => ['id' => 'selected', 'content' => 'Chosen']],
			'assistant' => ['message' => ['id' => 'default', 'content' => 'Default']]
		], ['agent_components_assistant_node' => 'custom']);
		$this->assertSame('message', $result['type']);
		$this->assertSame('selected', $result['id']);
		$this->assertSame('Chosen', $result['text']);
	}

	public function testOtherTerminalNodeMessageIsUsedWhenAssistantIsMissing(): void {
		$result = $this->execute(['response' => ['message' => ['id' => 'other', 'content' => 'Result']]]);
		$this->assertSame('other', $result['id']);
		$this->assertSame('Result', $result['text']);
	}

	public function testMissingMessageWithNamedFlowErrorReturnsExplicitError(): void {
		$result = $this->execute(['custom' => ['error' => 'Permission denied']], ['agent_components_assistant_node' => 'custom']);
		$this->assertSame('error', $result['type']);
		$this->assertStringContainsString('Permission denied', $result['text']);
	}

	public function testMissingMessageReportsTerminalNodeIdsWithoutInventingSuccess(): void {
		$result = $this->execute(['alpha' => ['value' => true], 'beta' => []]);
		$this->assertSame('error', $result['type']);
		$this->assertStringContainsString('Terminal nodes: alpha, beta.', $result['text']);
	}

	public function testCompletelyEmptyFlowReportsNoTerminalOutput(): void {
		$result = $this->execute([]);
		$this->assertSame('error', $result['type']);
		$this->assertStringContainsString('No terminal node output was returned.', $result['text']);
	}

	public function testCancellationStatusFromOtherNodeAlwaysWinsOverMessage(): void {
		$result = $this->execute([
			'assistant' => ['message' => ['id' => 'should-not-return', 'content' => 'Finished']],
			'step' => ['status' => 'cancelled']
		]);
		$this->assertSame('cancelled', $result['type']);
		$this->assertSame('', $result['text']);
	}

	public function testInteractionDetectedOnOtherTerminalNode(): void {
		$result = $this->execute([
			'assistant' => ['message' => ['id' => 'ignored', 'content' => 'Done']],
			'confirmation' => [
				'status' => 'awaiting_input', 'resume_handle' => 'resume-123',
				'interaction_requests' => [['id' => 'request-1', 'kind' => 'input']]
			]
		]);
		$this->assertSame('interaction_required', $result['type']);
		$this->assertSame('resume-123', $result['resume_handle']);
		$this->assertSame('request-1', $result['interaction_requests'][0]['id']);
	}

	public function testInteractionWithoutRequestsDoesNotHideFlowError(): void {
		$result = $this->execute(['assistant' => ['status' => 'awaiting_input', 'resume_handle' => 'resume', 'error' => 'Missing requests']]);
		$this->assertSame('error', $result['type']);
		$this->assertStringContainsString('Missing requests', $result['text']);
	}

	public function testNonScalarAssistantContentIsJsonEncoded(): void {
		$result = $this->execute(['assistant' => ['message' => ['id' => 'json', 'content' => ['text' => 'Grüße', 'count' => 2]]]]);
		$this->assertSame('json', $result['id']);
		$this->assertSame('{"text":"Grüße","count":2}', $result['text']);
	}

	public function testScalarAssistantContentConvertsNumbersAndBooleans(): void {
		foreach ([12 => '12', 'false' => 'false', 'true' => 'true'] as $key => $expected) {
			$content = match ($key) {12 => 12, 'false' => false, 'true' => true};
			$result = $this->execute(['assistant' => ['message' => ['id' => 'scalar', 'content' => $content]]]);
			$this->assertSame($expected, $result['text']);
		}
	}

	public function testMissingAssistantMessageIdIsGenerated(): void {
		$result = $this->execute(['assistant' => ['message' => ['content' => 'Hello']]]);
		$this->assertTrue(str_starts_with($result['id'], 'msg_'));
		$this->assertSame('Hello', $result['text']);
	}

	public function testCustomSystemPromptAndAgentRuntimePassThroughToExecution(): void {
		$runtime = $this->createMock(IAgentExecutionService::class);
		$runtime->expects($this->once())->method('execute')->willReturnCallback(function(AgentExecutionRequest $request): AgentExecutionResult {
			$this->assertSame('Configured system prompt', $request->getInputs()['system']);
			$this->assertSame('request text', $request->getInputs()['prompt']);
			$this->assertSame('missionbay', $request->getAgentConfiguration()['agent_runtime']);
			$this->assertSame('request text', $request->getContext()['prompt_text']);
			return new AgentExecutionResult(['assistant' => ['message' => ['id' => 'one', 'content' => 'Done']]]);
		});
		$service = $this->service($runtime, [], ['chatbot_backend' => 'runtime:missionbay', 'system_prompt' => 'Configured system prompt']);
		$result = $service->executeTurn(new ChatbotTurnRequest([
			'prompt' => 'request text', 'config_group' => 'chatbot', 'config_name' => 'default'
		]), $this->createStub(IAgentEventSink::class));
		$this->assertSame('Done', $result->getText());
	}

	/** @param array<string,mixed> $result @param array<string,mixed> $settings @return array<string,mixed> */
	private function execute(array $result, array $settings = []): array {
		$runtime = $this->createStub(IAgentExecutionService::class);
		$runtime->method('execute')->willReturn(new AgentExecutionResult($result));
		$service = $this->service($runtime, [], $settings);
		return $service->executeTurn(
			new ChatbotTurnRequest(['prompt' => 'Hello', 'config_group' => 'chatbot', 'config_name' => 'example']),
			$this->createStub(IAgentEventSink::class)
		)->toArray();
	}

	/** @param array<string,mixed> $requestValues @param array<string,mixed> $settings */
	private function service(IAgentExecutionService $execution, array $requestValues = [], array $settings = []): TestableChatbotService {
		$request = $this->createStub(IRequest::class);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($settings);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$selector->method('selectRuntimeId')->willReturn('missionbay');
		$settingsService = new ChatbotSettingsService($store, $selector);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		return new TestableChatbotService(
			$request,
			$settingsService,
			$execution,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder(
				$this->createStub(ILanguage::class),
				new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
				$this->createStub(ILogger::class)
			),
			new ChatbotConversationChannelResolver(),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($map), $store),
			new ChatbotOpeningMessageService(
				$this->createStub(IAgentTextTaskService::class),
				$settingsService, $this->createStub(ILanguage::class)
			)
		);
	}
}

require_once __DIR__ . '/TestableChatbotService.php';
