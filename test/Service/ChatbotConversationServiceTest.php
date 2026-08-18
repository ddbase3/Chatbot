<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentExecutionStatus;
use AssistantFoundation\Dto\AgentSuspensionScope;
use AssistantFoundation\Dto\AgentSuspensionState;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Session\Api\ISession;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationServiceTest extends TestCase {

	public function testEmptyChannelReturnsUnsavedDraftWithoutCreatingConversation(): void {
		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->once())
			->method('getState')
			->with(
				$this->callback(static fn(AgentConversationRequest $request): bool =>
					($request->getContext()['conversation_channel_id'] ?? null)
						=== 'chatbot:' . hash('sha256', "chatbot\0example")
				),
				''
			)
			->willReturn($this->emptyState());
		$conversationRuntime->expects($this->never())->method('createConversation');
		$conversationRuntime->expects($this->never())->method('appendMessage');

		$result = $this->createService($conversationRuntime, [
			'main_headings' => ['Welcome'],
			'first_message_mode' => 'none'
		])->getState('chatbot', 'example');

		$this->assertNull($result->getState()->getActiveConversation());
		$this->assertSame('Welcome', $result->getDraft()?->getOpeningMessage());
		$this->assertSame([], $result->getDraft()?->getMessages());
	}

	public function testConfiguredFirstAssistantMessageRemainsTransientUntilMaterialization(): void {
		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->once())->method('getState')->willReturn($this->emptyState());
		$conversationRuntime->expects($this->never())->method('createConversation');
		$conversationRuntime->expects($this->never())->method('appendMessage');

		$result = $this->createService($conversationRuntime, [
			'first_message_mode' => 'random',
			'first_messages' => ['Welcome to this chat.']
		])->createConversation('chatbot', 'example');

		$this->assertNull($result->getState()->getActiveConversation());
		$this->assertSame('assistant', $result->getDraft()?->getMessages()[0]['role'] ?? null);
		$this->assertSame('Welcome to this chat.', $result->getDraft()?->getMessages()[0]['content'] ?? null);
	}

	public function testFirstUserTurnMaterializesDraftAndItsAssistantMessage(): void {
		$conversation = $this->conversation('conversation-reserved');
		$created = $this->state($conversation);
		$withMessage = $this->state($conversation, [[
			'id' => 'msg_welcome',
			'role' => 'assistant',
			'content' => 'Welcome to this chat.',
			'timestamp' => '2026-07-29T18:00:00+02:00',
			'feedback' => null
		]]);

		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->exactly(2))
			->method('getState')
			->willReturnOnConsecutiveCalls($this->emptyState(), $this->emptyState());
		$conversationRuntime->expects($this->once())
			->method('createConversation')
			->with(
				$this->isInstanceOf(AgentConversationRequest::class),
				$this->callback(static fn(?string $id): bool => is_string($id) && str_starts_with($id, 'conversation-')),
				$this->stringStartsWith('Chat '),
				AgentConversation::TITLE_SOURCE_TEMPORARY,
				''
			)
			->willReturnCallback(function(
				AgentConversationRequest $request,
				?string $conversationId
			) use (&$conversation, &$created): AgentConversationState {
				$conversation = $this->conversation((string)$conversationId);
				$created = $this->state($conversation);
				return $created;
			});
		$conversationRuntime->expects($this->once())
			->method('appendMessage')
			->with(
				$this->isInstanceOf(AgentConversationRequest::class),
				$this->callback(static fn(string $id): bool => str_starts_with($id, 'conversation-')),
				$this->callback(static fn(array $message): bool =>
					($message['role'] ?? null) === 'assistant'
					&& ($message['content'] ?? null) === 'Welcome to this chat.'
				)
			)
			->willReturnCallback(function(
				AgentConversationRequest $request,
				string $conversationId,
				array $message
			): AgentConversationState {
				return $this->state($this->conversation($conversationId), [$message]);
			});

		$service = $this->createService($conversationRuntime, [
			'first_message_mode' => 'random',
			'first_messages' => ['Welcome to this chat.']
		]);
		$draftState = $service->createConversation('chatbot', 'example');
		$result = $service->materializeConversation(
			'chatbot',
			'example',
			(string)$draftState->getDraft()?->getId()
		);

		$this->assertNotNull($result->getState()->getActiveConversation());
		$this->assertNull($result->getDraft());
		$this->assertSame('Welcome to this chat.', $result->getState()->getMessages()[0]['content'] ?? null);
	}

	public function testExistingConversationIsReturnedWithoutDraft(): void {
		$conversation = $this->conversation('conversation-1');
		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->once())
			->method('getState')
			->willReturn($this->state($conversation));

		$result = $this->createService($conversationRuntime)->getState('chatbot', 'example');

		$this->assertSame('conversation-1', $result->getState()->getActiveConversation()?->getId());
		$this->assertNull($result->getDraft());
	}


	public function testExistingConversationProjectsPendingSuspensionFromCanonicalRepositoryState(): void {
		$conversation = $this->conversation('conversation-1');
		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->once())
			->method('getState')
			->willReturn($this->state($conversation));
		$suspensionRepository = $this->createMock(IAgentSuspensionRepository::class);
		$suspensionRepository->expects($this->once())
			->method('findPending')
			->with(AgentSuspensionScope::forConversation(
				'chatbot:' . hash('sha256', "chatbot\0example"),
				'conversation-1'
			))
			->willReturn(new AgentSuspensionState(
				true,
				AgentExecutionStatus::AWAITING_APPROVAL,
				[
					['id' => 'request-1', 'kind' => 'approval'],
					['id' => 'request-2', 'kind' => 'approval']
				],
				'scope.resume'
			));

		$result = $this->createService($conversationRuntime, [], $suspensionRepository)
			->getState('chatbot', 'example')
			->toArray();

		$this->assertSame('scope.resume', $result['pending_interaction']['resume_handle'] ?? null);
		$this->assertSame(
			AgentExecutionStatus::AWAITING_APPROVAL,
			$result['pending_interaction']['status'] ?? null
		);
		$this->assertSame(2, count($result['pending_interaction']['interaction_requests'] ?? []));
		$this->assertSame('request-1', $result['pending_interaction']['interaction_requests'][0]['id'] ?? null);
		$this->assertSame('request-2', $result['pending_interaction']['interaction_requests'][1]['id'] ?? null);
	}

	public function testManualRenameUsesManualTitleSource(): void {
		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->once())
			->method('renameConversation')
			->with(
				$this->isInstanceOf(AgentConversationRequest::class),
				'conversation-1',
				'My chat',
				AgentConversation::TITLE_SOURCE_MANUAL
			)
			->willReturn($this->state($this->conversation(
				'conversation-1',
				'',
				'My chat',
				AgentConversation::TITLE_SOURCE_MANUAL
			)));

		$result = $this->createService($conversationRuntime)
			->renameConversation('chatbot', 'example', 'conversation-1', ' My chat ');

		$this->assertSame(
			AgentConversation::TITLE_SOURCE_MANUAL,
			$result->getState()->getActiveConversation()?->getTitleSource()
		);
	}

	/** @param array<string,mixed> $overrides */
	private function createService(
		IAgentConversationService $conversationRuntime,
		array $overrides = [],
		?IAgentSuspensionRepository $suspensionRepository = null
	): ChatbotConversationService {
		$settings = array_merge([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'database-memory',
			'automatic_chat_titles' => true,
			'main_headings' => [],
			'first_message_mode' => 'none',
			'first_messages' => []
		], $overrides);
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('has')->willReturn(true);
		$settingsStore->method('get')->willReturn($settings);
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$runtimeSelector->method('selectRuntimeId')->willReturn('missionbay');
		$settingsService = new ChatbotSettingsService($settingsStore, $runtimeSelector);
		$textTaskService = $this->createStub(IAgentTextTaskService::class);
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('en');
		$openingMessageService = new ChatbotOpeningMessageService(
			$textTaskService,
			$settingsService,
			$language
		);
		$draftStore = new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub());

		return new ChatbotConversationService(
			$conversationRuntime,
			$textTaskService,
			$suspensionRepository ?? $this->createStub(IAgentSuspensionRepository::class),
			$settingsService,
			new ChatbotConversationChannelResolver(),
			$openingMessageService,
			$draftStore,
			$this->createStub(ILogger::class)
		);
	}

	private function conversation(
		string $id,
		string $openingMessage = '',
		string $title = 'Chat 2026-07-29 18:00',
		string $titleSource = AgentConversation::TITLE_SOURCE_TEMPORARY
	): AgentConversation {
		return new AgentConversation(
			$id,
			$title,
			$titleSource,
			$openingMessage,
			'2026-07-29T18:00:00+02:00',
			'2026-07-29T18:00:00+02:00',
			'2026-07-29T18:00:00+02:00'
		);
	}

	/** @param array<int,array<string,mixed>> $messages */
	private function state(AgentConversation $conversation, array $messages = []): AgentConversationState {
		return new AgentConversationState([$conversation], $conversation, $messages, 'assistant');
	}

	private function emptyState(): AgentConversationState {
		return new AgentConversationState([], null, [], 'assistant');
	}
}

final class ChatbotDraftSessionStub implements ISession {

	/** @var array<string,mixed> */
	private array $data = [];
	private bool $started = false;

	public function started(): bool {
		return $this->started;
	}

	public function getId(): string {
		return 'chatbot-draft-test';
	}

	public function start(): bool {
		$this->started = true;
		return true;
	}

	public function destroy(): bool {
		$this->data = [];
		$this->started = false;
		return true;
	}

	public function get(string $key, mixed $default = null): mixed {
		return $this->data[$key] ?? $default;
	}

	public function set(string $key, mixed $value): void {
		$this->data[$key] = $value;
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->data);
	}

	public function remove(string $key): void {
		unset($this->data[$key]);
	}
}
