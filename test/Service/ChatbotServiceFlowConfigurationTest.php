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
use Chatbot\Service\ChatbotService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\TestCase;

final class ChatbotServiceFlowConfigurationTest extends TestCase {

	public function testStructuredAgentFlowRemainsUnchanged(): void {
		$flow = ['nodes' => [['id' => 'writer', 'type' => 'assistant']], 'connections' => []];
		$request = $this->captureTurnExecution(['agent_flow' => $flow]);
		$this->assertSame($flow, $request->getAgentConfiguration()['agent_flow']);
	}

	public function testMissingAgentFlowDoesNotInventANewFlow(): void {
		$request = $this->captureTurnExecution(['chatbot_backend' => 'runtime:missionbay']);
		$this->assertArrayNotHasKey('agent_flow', $request->getAgentConfiguration());
	}

	public function testRequestContextUsesCanonicalServerSideConversationChannel(): void {
		$request = $this->captureTurnExecution(['system_prompt' => 'Root'], [
			'conversation_id' => 'conversation-a', 'turn_id' => 'turn-a',
			'reference' => ['page' => 'courses/7'], 'conversation_channel_id' => 'injected'
		]);
		$this->assertSame('chatbot:' . hash('sha256', "chatbot\0example"), $request->getContext()['conversation_channel_id']);
		$this->assertSame('conversation-a', $request->getContext()['conversation_id']);
		$this->assertSame('turn-a', $request->getContext()['turn_id']);
		$this->assertSame(['page' => 'courses/7'], $request->getContext()['reference']);
		$this->assertSame('chatbot', $request->getContext()['chatbot_config_group']);
		$this->assertSame('example', $request->getContext()['chatbot_config_name']);
		$this->assertSame('Please help', $request->getContext()['prompt_text']);
	}

	public function testCustomSuggestionFlowOverridesNormalAgentFlow(): void {
		$request = $this->captureSuggestionExecution([
			'agent_flow' => ['nodes' => [['id' => 'normal']]],
			'suggestion_agent_flow' => '{"nodes":[{"id":"suggestion"}]}',
			'suggestion_flow' => ['nodes' => [['id' => 'legacy']]],
			'suggestion_system_prompt' => '  Generate relevant prompts.  '
		]);
		$this->assertSame(['nodes' => [['id' => 'suggestion']]], $request->getAgentConfiguration()['agent_flow']);
		$this->assertSame('Generate relevant prompts.', $request->getInputs()['system']);
		$this->assertSame('suggestions', $request->getInputs()['mode']);
		$this->assertSame('Generate suggestions.', $request->getInputs()['prompt']);
	}

	public function testLegacySuggestionFlowRemainsSupported(): void {
		$request = $this->captureSuggestionExecution([
			'agent_flow' => ['nodes' => [['id' => 'normal']]],
			'suggestion_flow' => ['nodes' => [['id' => 'legacy']]]
		]);
		$this->assertSame(['nodes' => [['id' => 'legacy']]], $request->getAgentConfiguration()['agent_flow']);
		$this->assertSame('Suggest three prompts.', $request->getInputs()['system']);
	}

	public function testInvalidNewSuggestionFlowFallsBackToValidLegacyFlow(): void {
		$request = $this->captureSuggestionExecution([
			'suggestion_agent_flow' => 'not-json',
			'suggestion_flow' => '{"nodes":[{"id":"legacy"}]}'
		]);
		$this->assertSame(['nodes' => [['id' => 'legacy']]], $request->getAgentConfiguration()['agent_flow']);
	}

	public function testRestTransportSelectedFromStoredSettingsWhenRequestOmitsMode(): void {
		$execution = $this->createStub(IAgentExecutionService::class);
		$execution->method('execute')->willReturn($this->successfulExecution());
		$service = $this->newChatbotService(['transport_mode' => 'rest'], $execution, [
			'prompt' => 'A question', 'config_group' => 'chatbot', 'config_name' => 'example'
		]);
		$result = json_decode($service->getOutput('json'), true);
		$this->assertSame('message', $result['type']);
		$this->assertSame('answer-1', $result['id']);
	}

	public function testExplicitRestTransportOverridesStoredSettings(): void {
		$execution = $this->createStub(IAgentExecutionService::class);
		$execution->method('execute')->willReturn($this->successfulExecution());
		$service = $this->newChatbotService(['transport_mode' => 'sse'], $execution, [
			'prompt' => 'A question', 'transport_mode' => 'rest',
			'config_group' => 'chatbot', 'config_name' => 'example'
		]);
		$this->assertSame('message', json_decode($service->getOutput('json'), true)['type']);
	}

	public function testBasePromptUsesConfiguredHeadingWithoutExecutingAgent(): void {
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->expects($this->never())->method('execute');
		$service = $this->newChatbotService(['main_headings' => ['<b>Welcome</b> &amp; hello']], $execution, [
			'baseprompt' => '1', 'config_group' => 'chatbot', 'config_name' => 'example'
		]);
		$this->assertSame('Welcome & hello', $service->getOutput());
	}

	private function captureTurnExecution(array $settings, array $values = []): AgentExecutionRequest {
		$captured = null;
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->expects($this->once())->method('execute')->willReturnCallback(function(AgentExecutionRequest $request) use (&$captured): AgentExecutionResult {
			$captured = $request;
			return $this->successfulExecution();
		});
		$service = $this->newChatbotService($settings, $execution);
		$result = $service->executeTurn(new ChatbotTurnRequest(array_replace([
			'prompt' => 'Please help', 'config_group' => 'chatbot', 'config_name' => 'example'
		], $values)), $this->createStub(IAgentEventSink::class));
		$this->assertSame('message', $result->getType());
		$this->assertInstanceOf(AgentExecutionRequest::class, $captured);
		return $captured;
	}

	private function captureSuggestionExecution(array $settings): AgentExecutionRequest {
		$captured = null;
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->expects($this->once())->method('execute')->willReturnCallback(function(AgentExecutionRequest $request) use (&$captured): AgentExecutionResult {
			$captured = $request;
			return new AgentExecutionResult(['assistant' => ['message' => ['content' => '["Suggestion"]']]]);
		});
		$service = $this->newChatbotService($settings, $execution, [
			'suggestions' => '1', 'config_group' => 'chatbot', 'config_name' => 'example'
		]);
		$this->assertSame(['Suggestion'], json_decode($service->getOutput(), true));
		$this->assertInstanceOf(AgentExecutionRequest::class, $captured);
		return $captured;
	}

	private function successfulExecution(): AgentExecutionResult {
		return new AgentExecutionResult(['assistant' => ['message' => ['id' => 'answer-1', 'content' => 'Answer']]]);
	}

	private function newChatbotService(array $settings, IAgentExecutionService $execution, array $values = []): ChatbotService {
		$request = $this->createStub(IRequest::class);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($settings);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('selectRuntimeId')->willReturn('missionbay');
		$config = new ChatbotSettingsService($store, $selector);
		$language = $this->createStub(ILanguage::class);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		return new ChatbotService(
			$request, $config, $execution,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder($language, new ChatbotTurnCancellationService($this->createStub(IStateStore::class)), $this->createStub(ILogger::class)),
			new ChatbotConversationChannelResolver(),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($map), $store),
			new ChatbotOpeningMessageService($this->createStub(IAgentTextTaskService::class), $config, $language)
		);
	}
}
