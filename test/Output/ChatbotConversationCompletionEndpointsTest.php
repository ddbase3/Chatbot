<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentTextTaskRequest;
use AssistantFoundation\Dto\AgentTextTaskResult;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Output\ChatbotConversationMaterializeOutput;
use Chatbot\Output\ChatbotConversationTitleOutput;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Test\Service\ChatbotDraftSessionStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConversationCompletionEndpointsTest extends TestCase {

	public function testMaterializeEndpointCreatesExactlyOneReservedConversation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->emptyState());
		$runtime->expects($this->once())->method('createConversation')->willReturnCallback(function(
			AgentConversationRequest $request, ?string $id, string $title, string $source, string $opening
		): AgentConversationState {
			$this->assertTrue(is_string($id) && str_starts_with($id, 'conversation-'));
			$this->assertStringContainsString('Chat ', $title);
			$this->assertSame(AgentConversation::TITLE_SOURCE_TEMPORARY, $source);
			$this->assertSame('', $opening);
			$this->assertSame('chatbot', $request->getContext()['chatbot_config_group']);
			return $this->conversationState($id, $title, $source);
		});
		$runtime->expects($this->never())->method('activateConversation');
		$runtime->expects($this->never())->method('appendMessage');
		$service = $this->service($runtime);
		$draft = $service->createConversation('chatbot', 'sidebar')->getDraft();
		$this->assertNotNull($draft);

		$response = $this->runEndpoint(ChatbotConversationMaterializeOutput::class, $service, [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'draft_id' => $draft->getId()
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame($draft->getConversationId(), $response['data']['state']['active_conversation']['id']);
		$this->assertNull($response['data']['state']['draft']);
	}

	public function testMaterializeEndpointConsumesDraftAndRejectsReplay(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->emptyState());
		$runtime->expects($this->once())->method('createConversation')->willReturnCallback(fn(
			AgentConversationRequest $request, ?string $id, string $title, string $source
		): AgentConversationState => $this->conversationState((string)$id, $title, $source));
		$service = $this->service($runtime);
		$draft = $service->createConversation('chatbot', 'sidebar')->getDraft();
		$this->assertNotNull($draft);
		$payload = ['config_group' => 'chatbot', 'config_name' => 'sidebar', 'draft_id' => $draft->getId()];
		$this->assertTrue($this->runEndpoint(ChatbotConversationMaterializeOutput::class, $service, $payload)['ok']);
		$replay = $this->runEndpoint(ChatbotConversationMaterializeOutput::class, $service, $payload);
		$this->assertFalse($replay['ok']);
		$this->assertSame('conversation_not_found', $replay['error']['code']);
		$this->assertSame('Conversation draft not found.', $replay['error']['message']);
	}

	public function testMaterializeEndpointActivatesAnAlreadyExistingReservedConversation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$existingId = '';
		$runtime->method('getState')->willReturnCallback(function() use (&$existingId): AgentConversationState {
			if ($existingId === '') {
				return $this->emptyState();
			}
			$conversation = $this->conversation($existingId, 'Already stored', AgentConversation::TITLE_SOURCE_TEMPORARY);
			return new AgentConversationState([$conversation], null, [], 'assistant');
		});
		$runtime->expects($this->never())->method('createConversation');
		$runtime->expects($this->once())->method('activateConversation')->willReturnCallback(function(AgentConversationRequest $request, string $id) use (&$existingId): AgentConversationState {
			$this->assertSame($existingId, $id);
			return $this->conversationState($id, 'Already stored', AgentConversation::TITLE_SOURCE_TEMPORARY);
		});
		$service = $this->service($runtime);
		$draft = $service->createConversation('chatbot', 'sidebar')->getDraft();
		$this->assertNotNull($draft);
		$existingId = $draft->getConversationId();
		$response = $this->runEndpoint(ChatbotConversationMaterializeOutput::class, $service, [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'draft_id' => strtoupper($draft->getId())
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame($existingId, $response['data']['state']['active_conversation']['id']);
	}

	public function testMaterializeEndpointPropagatesNormalizedReferenceToRuntime(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->emptyState());
		$captured = [];
		$runtime->method('createConversation')->willReturnCallback(function(AgentConversationRequest $request, ?string $id, string $title, string $source) use (&$captured): AgentConversationState {
			$captured = $request->getContext()['reference'];
			return $this->conversationState((string)$id, $title, $source);
		});
		$service = $this->service($runtime);
		$draft = $service->createConversation('chatbot', 'sidebar')->getDraft();
		$this->assertNotNull($draft);
		$response = $this->runEndpoint(ChatbotConversationMaterializeOutput::class, $service, [
			'config_group' => 'chatbot', 'config_name' => 'sidebar',
			'draft_id' => $draft->getId(),
			'reference' => ['course' => ['id' => 17, 'secret' => new \stdClass()], 'active' => true]
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame(['course' => ['id' => 17], 'active' => true], $captured);
	}

	public function testMaterializeEndpointRejectsMalformedDraftBeforeCallingRuntime(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->runEndpoint(ChatbotConversationMaterializeOutput::class, $this->service($runtime), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'draft_id' => 'not-a-draft'
		]);
		$this->assertFalse($response['ok']);
		$this->assertSame('invalid_request', $response['error']['code']);
		$this->assertSame('Conversation draft id is missing or invalid.', $response['error']['message']);
	}

	public function testTitleEndpointReturnsExistingManualTitleWithoutRegenerating(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturn($this->conversationState('conversation-1', 'Manually named', AgentConversation::TITLE_SOURCE_MANUAL));
		$runtime->expects($this->never())->method('renameConversation');
		$text = $this->createMock(IAgentTextTaskService::class);
		$text->expects($this->never())->method('executeTextTask');
		$response = $this->runEndpoint(ChatbotConversationTitleOutput::class, $this->service($runtime, $text), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'conversation_id' => 'conversation-1'
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame('Manually named', $response['data']['state']['active_conversation']['title']);
	}

	public function testTitleEndpointGeneratesAndPersistsAutomaticTitle(): void {
		$messages = [
			['role' => 'user', 'content' => 'Why do waves interfere?'],
			['role' => 'assistant', 'content' => 'Superposition describes the interference of waves.']
		];
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturn($this->conversationState('conversation-1', 'Chat title', AgentConversation::TITLE_SOURCE_TEMPORARY, $messages));
		$runtime->expects($this->once())->method('renameConversation')->willReturnCallback(function(AgentConversationRequest $request, string $id, string $title, string $source) use ($messages): AgentConversationState {
			$this->assertSame('conversation-1', $id);
			$this->assertSame('Wave interference explained', $title);
			$this->assertSame(AgentConversation::TITLE_SOURCE_AUTOMATIC, $source);
			return $this->conversationState($id, $title, $source, $messages);
		});
		$text = $this->createMock(IAgentTextTaskService::class);
		$text->expects($this->once())->method('executeTextTask')->willReturnCallback(function(AgentTextTaskRequest $request): AgentTextTaskResult {
			$this->assertStringContainsString('Why do waves interfere?', $request->getPrompt());
			return new AgentTextTaskResult(' "Wave interference explained!" ');
		});
		$response = $this->runEndpoint(ChatbotConversationTitleOutput::class, $this->service($runtime, $text), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'conversation_id' => ' conversation-1 ',
			'reference' => ['page' => ['id' => 12]]
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame('Wave interference explained', $response['data']['state']['active_conversation']['title']);
		$this->assertSame(AgentConversation::TITLE_SOURCE_AUTOMATIC, $response['data']['state']['active_conversation']['title_source']);
	}

	public function testTitleEndpointDoesNotGenerateTitleWithoutCompleteTurn(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturn($this->conversationState('conversation-1', 'Chat title', AgentConversation::TITLE_SOURCE_TEMPORARY, [
			['role' => 'user', 'content' => 'Only a question']
		]));
		$runtime->expects($this->never())->method('renameConversation');
		$text = $this->createMock(IAgentTextTaskService::class);
		$text->expects($this->never())->method('executeTextTask');
		$response = $this->runEndpoint(ChatbotConversationTitleOutput::class, $this->service($runtime, $text), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'conversation_id' => 'conversation-1'
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame('Chat title', $response['data']['state']['active_conversation']['title']);
	}

	public function testTitleEndpointReturnsExistingStateWhenTextProviderFails(): void {
		$messages = [
			['role' => 'user', 'content' => 'What is energy?'],
			['role' => 'assistant', 'content' => 'A conserved physical quantity.']
		];
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->conversationState('conversation-1', 'Untitled chat', AgentConversation::TITLE_SOURCE_TEMPORARY, $messages));
		$runtime->expects($this->never())->method('renameConversation');
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturnCallback(static function(): never { throw new RuntimeException('Text model unavailable'); });
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())->method('warning');
		$response = $this->runEndpoint(ChatbotConversationTitleOutput::class, $this->service($runtime, $text, [], $logger), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'conversation_id' => 'conversation-1'
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame('Untitled chat', $response['data']['state']['active_conversation']['title']);
	}

	public function testTitleEndpointClassifiesNonexistentConversationAsNotFound(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->emptyState());
		$response = $this->runEndpoint(ChatbotConversationTitleOutput::class, $this->service($runtime), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'conversation_id' => 'missing-1'
		]);
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_not_found', $response['error']['code']);
		$this->assertSame('Conversation not found: missing-1', $response['error']['message']);
	}

	public function testTitleEndpointRejectsMalformedConversationId(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->runEndpoint(ChatbotConversationTitleOutput::class, $this->service($runtime), [
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'conversation_id' => 'forged / id'
		]);
		$this->assertFalse($response['ok']);
		$this->assertSame('invalid_request', $response['error']['code']);
		$this->assertSame('Conversation id is missing or invalid.', $response['error']['message']);
	}

	private function runEndpoint(string $type, ChatbotConversationService $service, array $body): array {
		$request = $this->createStub(IRequest::class);
		$request->method('allRequest')->willReturn($body);
		$request->method('getJsonBody')->willReturn([]);
		$request->method('get')->willReturn(null);
		$request->method('server')->willReturnCallback(static fn(string $name, mixed $default = null): mixed => $name === 'REQUEST_METHOD' ? 'POST' : $default);
		$output = new $type($request, $service);
		$response = json_decode($output->getOutput('json'), true);
		$this->assertTrue(is_array($response));
		return $response;
	}

	private function service(
		IAgentConversationService $runtime,
		?IAgentTextTaskService $text = null,
		array $overrides = [],
		?ILogger $logger = null
	): ChatbotConversationService {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('has')->willReturn(true);
		$store->method('get')->willReturn(array_replace([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'conversation-memory',
			'first_message_mode' => 'none'
		], $overrides));
		$settings = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		$text ??= $this->createStub(IAgentTextTaskService::class);
		return new ChatbotConversationService(
			$runtime,
			$text,
			$this->createStub(IAgentSuspensionRepository::class),
			$settings,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $settings, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$logger ?? $this->createStub(ILogger::class)
		);
	}

	private function emptyState(): AgentConversationState {
		return new AgentConversationState([], null, [], 'assistant');
	}

	private function conversation(string $id, string $title, string $source): AgentConversation {
		return new AgentConversation($id, $title, $source, '', '2026-11-03', '2026-11-03', '2026-11-03');
	}

	private function conversationState(string $id, string $title, string $source, array $messages = []): AgentConversationState {
		$conversation = $this->conversation($id, $title, $source);
		return new AgentConversationState([$conversation], $conversation, $messages, 'assistant');
	}
}

require_once dirname(__DIR__) . '/Service/ChatbotDraftSessionStub.php';
