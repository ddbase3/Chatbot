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

namespace Base3\Chatbot\Test\Output;

use Base3\AssistantFoundation\Api\IAgentConversationService;
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentSuspensionRepository;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Dto\AgentConversation;
use Base3\AssistantFoundation\Dto\AgentConversationState;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Output\ChatbotConversationActivateOutput;
use Base3\Chatbot\Output\ChatbotConversationCreateOutput;
use Base3\Chatbot\Output\ChatbotConversationDeleteOutput;
use Base3\Chatbot\Output\ChatbotConversationMaterializeOutput;
use Base3\Chatbot\Output\ChatbotConversationRenameOutput;
use Base3\Chatbot\Output\ChatbotConversationStateOutput;
use Base3\Chatbot\Output\ChatbotConversationTitleOutput;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotConversationService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use Base3\Chatbot\Test\Service\ChatbotDraftSessionStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConversationEndpointsBehaviorTest extends TestCase {

	public function testCreateEndpointReturnsTransientDraftAndDoesNotPersistConversation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturn($this->emptyState());
		$runtime->expects($this->never())->method('createConversation');
		$response = $this->invokeEndpoint(ChatbotConversationCreateOutput::class, $runtime);
		$this->assertTrue($response['ok']);
		$this->assertNull($response['data']['state']['active_conversation']);
		$this->assertSame([], $response['data']['state']['messages']);
		$this->assertSame(48, strlen($response['data']['state']['draft']['id']));
	}

	public function testActivateEndpointReturnsActiveConversation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('activateConversation')->willReturnCallback(function($request, string $id): AgentConversationState {
			$this->assertSame('conversation-1', $id);
			return $this->activeState();
		});
		$response = $this->invokeEndpoint(ChatbotConversationActivateOutput::class, $runtime, ['conversation_id' => 'conversation-1']);
		$this->assertTrue($response['ok']);
		$this->assertSame('conversation-1', $response['data']['state']['active_conversation']['id']);
	}

	public function testRenameEndpointUsesTrimmedManualTitle(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('renameConversation')->willReturnCallback(function($request, string $id, string $title, string $source): AgentConversationState {
			$this->assertSame('conversation-1', $id);
			$this->assertSame('A title', $title);
			$this->assertSame(AgentConversation::TITLE_SOURCE_MANUAL, $source);
			return $this->activeState();
		});
		$response = $this->invokeEndpoint(ChatbotConversationRenameOutput::class, $runtime, [
			'conversation_id' => 'conversation-1', 'title' => '  A title! '
		]);
		$this->assertTrue($response['ok']);
	}

	public function testDeleteEndpointWithoutActiveConversationReturnsNewDraft(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('deleteConversation')->willReturn($this->emptyState());
		$response = $this->invokeEndpoint(ChatbotConversationDeleteOutput::class, $runtime, ['conversation_id' => 'conversation-1']);
		$this->assertTrue($response['ok']);
		$this->assertNotNull($response['data']['state']['draft']);
	}

	public function testStateEndpointAcceptsPostWithMergedJsonBodyAndPinnedQueryIdentity(): void {
		$captured = [];
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(function($request, string $id = '') use (&$captured): AgentConversationState {
			$captured = $request->getContext();
			$this->assertSame('conversation-1', $id);
			return $this->activeState();
		});
		$request = $this->createRequest('POST',
			['config_group' => 'untrusted', 'config_name' => 'untrusted', 'conversation_id' => 'old', 'reference' => ['body' => 1]],
			['conversation_id' => 'conversation-1', 'reference' => ['course' => ['id' => 17, 'illegal' => new \stdClass()]]],
			['config_group' => 'chatbot', 'config_name' => 'example']
		);
		$endpoint = new ChatbotConversationStateOutput($request, $this->createService($runtime));
		$data = json_decode($endpoint->getOutput(), true);
		$this->assertTrue($data['ok']);
		$this->assertSame('chatbot', $captured['chatbot_config_group']);
		$this->assertSame('example', $captured['chatbot_config_name']);
		$this->assertSame(['course' => ['id' => 17]], $captured['reference']);
	}

	public function testMalformedReferenceIsIgnoredAndDeeplyNestedArraysAreBounded(): void {
		$captured = [];
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(function($request) use (&$captured): AgentConversationState {
			$captured = $request->getContext()['reference'];
			return $this->emptyState();
		});
		$deep = ['root' => ['a' => ['b' => ['c' => ['d' => ['e' => ['g' => 'too-deep']]]]]]];
		$endpoint = new ChatbotConversationStateOutput($this->createRequest('GET', [], [
			'config_group' => 'chatbot', 'config_name' => 'example', 'reference' => $deep
		]), $this->createService($runtime));
		$this->assertTrue(json_decode($endpoint->getOutput(), true)['ok']);
		$this->assertSame([], $captured['root']['a']['b']['c']['d']['e']);
		$endpoint = new ChatbotConversationStateOutput($this->createRequest('GET', [], [
			'config_group' => 'chatbot', 'config_name' => 'example', 'reference' => '{bad json'
		]), $this->createService($runtime));
		$this->assertTrue(json_decode($endpoint->getOutput(), true)['ok']);
		$this->assertSame([], $captured);
	}

	public function testUnconfiguredMemoryProducesUnavailableErrorCode(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->invokeEndpoint(ChatbotConversationStateOutput::class, $runtime, [], ['memory_profile' => ''], 'GET');
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_unavailable', $response['error']['code']);
	}

	public function testMissingConversationProducesNotFoundErrorCode(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(): never { throw new RuntimeException('Conversation not found: missing'); });
		$response = $this->invokeEndpoint(ChatbotConversationStateOutput::class, $runtime, ['conversation_id' => 'missing'], [], 'GET');
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_not_found', $response['error']['code']);
	}

	public function testUnexpectedRuntimeFailureIsReportedWithoutLeakingExtraFields(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(): never { throw new RuntimeException('Backend unavailable'); });
		$response = $this->invokeEndpoint(ChatbotConversationStateOutput::class, $runtime, [], [], 'GET');
		$this->assertSame(['ok' => false, 'error' => ['code' => 'conversation_error', 'message' => 'Backend unavailable']], $response);
	}

	public function testInvalidUtf8InMessagesUsesSafeJsonSubstitution(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->activeState([['role' => 'assistant', 'content' => "broken\xC3byte"]]));
		$response = $this->invokeEndpoint(ChatbotConversationStateOutput::class, $runtime, [], [], 'GET');
		$this->assertTrue($response['ok']);
		$this->assertStringContainsString('broken', $response['data']['state']['messages'][0]['content']);
	}

	public function testEveryEndpointUsesExpectedTechnicalName(): void {
		foreach ([
			ChatbotConversationStateOutput::class => 'chatbotconversationstate',
			ChatbotConversationCreateOutput::class => 'chatbotconversationcreate',
			ChatbotConversationActivateOutput::class => 'chatbotconversationactivate',
			ChatbotConversationRenameOutput::class => 'chatbotconversationrename',
			ChatbotConversationDeleteOutput::class => 'chatbotconversationdelete',
			ChatbotConversationMaterializeOutput::class => 'chatbotconversationmaterialize',
			ChatbotConversationTitleOutput::class => 'chatbotconversationtitle'
		] as $class => $name) {
			$this->assertSame($name, $class::getName());
		}
	}

	private function invokeEndpoint(string $class, IAgentConversationService $runtime, array $extra = [], array $settings = [], string $method = 'POST'): array {
		$request = $this->createRequest($method, array_merge(['config_group' => 'chatbot', 'config_name' => 'example'], $extra));
		$endpoint = new $class($request, $this->createService($runtime, $settings));
		return json_decode($endpoint->getOutput(), true);
	}

	private function createRequest(string $method, array $body = [], array $json = [], array $query = []): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('server')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'REQUEST_METHOD' ? $method : $default);
		$request->method('allRequest')->willReturn($body);
		$request->method('getJsonBody')->willReturn($json);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $query[$key] ?? $default);
		return $request;
	}

	private function createService(IAgentConversationService $runtime, array $overrides = []): ChatbotConversationService {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('has')->willReturn(true);
		$store->method('get')->willReturn(array_replace([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'db-memory',
			'first_message_mode' => 'none'
		], $overrides));
		$config = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		$text = $this->createStub(IAgentTextTaskService::class);
		return new ChatbotConversationService(
			$runtime, $text, $this->createStub(IAgentSuspensionRepository::class),
			$config, new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $config, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
	}

	private function activeState(array $messages = []): AgentConversationState {
		$conversation = new AgentConversation('conversation-1', 'Active chat', AgentConversation::TITLE_SOURCE_TEMPORARY, '', '2026-10-01', '2026-10-01', '2026-10-01');
		return new AgentConversationState([$conversation], $conversation, $messages, 'assistant');
	}

	private function emptyState(): AgentConversationState {
		return new AgentConversationState([], null, [], 'assistant');
	}
}
