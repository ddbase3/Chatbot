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

namespace Base3\Chatbot\Test\Service;

use Base3\AssistantFoundation\Api\IAgentEventSink;
use Base3\AssistantFoundation\Api\IAgentExecutionService;
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Api\IAssistantResponseExtension;
use Base3\AssistantFoundation\Dto\AgentExecutionRequest;
use Base3\AssistantFoundation\Dto\AgentExecutionResult;
use Base3\AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\AssistantRuntime\Service\CollectingAgentEventSink;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotService;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use Base3\Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(\Base3\Chatbot\Service\ChatbotService::class)]
final class ChatbotServiceTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('chatbotservice', ChatbotService::getName());
	}

	public function testEnabledResponseExtensionContributesSystemPrompt(): void {
		$disabled = $this->makeService($this->createRequest([]));
		$this->assertSame(
			'Configured prompt',
			$disabled->getTestSystemPrompt([
				'system_prompt' => 'Configured prompt',
				'use_markdown' => true
			])
		);

		$enabled = $this->makeService(
			$this->createRequest([]),
			null,
			$this->createExtensionService(true)
		);
		$prompt = $enabled->getTestSystemPrompt([
			'system_prompt' => 'Configured prompt',
			'use_markdown' => true
		]);

		$this->assertStringStartsWith('Configured prompt', $prompt);
		$this->assertStringContainsString('Test response extension prompt.', $prompt);
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
				$this->isInstanceOf(IAgentEventSink::class)
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

	public function testExecuteTurnPassesConversationContext(): void {
		$executionService = $this->createMock(IAgentExecutionService::class);
		$executionService->expects($this->once())
			->method('execute')
			->with(
				$this->callback(static function(AgentExecutionRequest $request): bool {
					$context = $request->getContext();

					return ($context['conversation_id'] ?? null) === 'conversation-1'
						&& ($context['conversation_channel_id'] ?? null) === 'chatbot:' . hash('sha256', "chatbot\0example")
						&& ($context['chatbot_config_group'] ?? null) === 'chatbot'
						&& ($context['chatbot_config_name'] ?? null) === 'example';
				}),
				$this->isInstanceOf(IAgentEventSink::class)
			)
			->willReturn(new AgentExecutionResult([
				'assistant' => [
					'message' => [
						'id' => 'msg-3',
						'content' => 'Remembered'
					]
				]
			]));

		$result = $this->makeService($this->createRequest([]), $executionService)->executeTurn(
			new ChatbotTurnRequest([
				'prompt' => 'Remember this',
				'conversation_id' => 'conversation-1',
				'conversation_channel_id' => 'forged-browser-channel',
				'config_group' => 'chatbot',
				'config_name' => 'example'
			]),
			new CollectingAgentEventSink()
		);

		$this->assertSame('Remembered', $result->getText());
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
		?IAgentExecutionService $executionService = null,
		?ChatbotExtensionService $extensionService = null
	): TestableChatbotService {
		$settingsStore = $this->createStub(ISettingsStore::class);
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$runtimeSelector->method('selectRuntimeId')->willReturn('missionbay');
		$settingsService = new ChatbotSettingsService($settingsStore, $runtimeSelector);
		$openingMessageService = new ChatbotOpeningMessageService(
			$this->createStub(IAgentTextTaskService::class),
			$settingsService,
			$this->createStub(ILanguage::class)
		);

		return new TestableChatbotService(
			$request,
			$settingsService,
			$executionService ?? $this->createStub(IAgentExecutionService::class),
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder(
				$this->createStub(ILanguage::class),
				new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
				$this->createStub(ILogger::class)
			),
			new ChatbotConversationChannelResolver(),
			$extensionService ?? $this->createExtensionService(false),
			$openingMessageService
		);
	}

	private function createExtensionService(bool $enabled): ChatbotExtensionService {
		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstancesByInterface')->willReturn([new TestResponseExtension()]);
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn([
			'enabled' => ['test-response' => $enabled]
		]);

		return new ChatbotExtensionService(
			new ChatbotExtensionRegistry($classMap),
			$settingsStore
		);
	}

}

require_once __DIR__ . '/TestableChatbotService.php';
require_once __DIR__ . '/TestResponseExtension.php';
