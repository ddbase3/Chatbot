<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentTextTaskResult;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConversationServiceRemainingTest extends TestCase {

	public function testUnavailableConfigurationAndMemoryAreRejectedWithoutRuntimeCalls(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$this->expectException(RuntimeException::class);
		$this->makeConversationService($runtime, [], false)->getState('chatbot', 'missing');
	}

	public function testConfigurationWithoutMemoryIsNotAvailable(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$service = $this->makeConversationService($runtime, ['memory_profile' => '']);
		$this->assertFalse($service->isAvailable('chatbot', 'example'));
		$this->expectException(RuntimeException::class);
		$service->getState('chatbot', 'example');
	}

	public function testHistoryDisabledDoesNotCreateConversation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$this->expectException(RuntimeException::class);
		$this->makeConversationService($runtime, ['chat_history_enabled' => 'off'])->createConversation('chatbot', 'example');
	}

	public function testActivationRejectsInvalidIdsBeforeRuntimeMutation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('activateConversation');
		$service = $this->makeConversationService($runtime);
		foreach (['', ' with spaces ', 'bad/id', str_repeat('a', 101)] as $id) {
			try {
				$service->activateConversation('chatbot', 'example', $id);
				$this->fail('Invalid conversation id accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('Conversation id', $exception->getMessage());
			}
		}
	}

	public function testActivationTrimsValidConversationIdAndPreservesChannelIdentity(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('activateConversation')->willReturnCallback(function(AgentConversationRequest $request, string $id): AgentConversationState {
			$this->assertSame('chatbot:' . hash('sha256', "chatbot\0example"), $request->getContext()['conversation_channel_id']);
			$this->assertSame('conversation-1', $id);
			return $this->conversationState();
		});
		$result = $this->makeConversationService($runtime)->activateConversation('chatbot', 'example', ' conversation-1 ');
		$this->assertSame('conversation-1', $result->getState()->getActiveConversation()?->getId());
	}

	public function testRenameRejectsTitleWithoutReadableText(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('renameConversation');
		$service = $this->makeConversationService($runtime);
		foreach (['', ' <b></b> ', '---', '!!!'] as $title) {
			try {
				$service->renameConversation('chatbot', 'example', 'conversation-1', $title);
				$this->fail('Empty title accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('title must not be empty', $exception->getMessage());
			}
		}
	}

	public function testRenameNormalizesHtmlWhitespaceAndTrailingPunctuation(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('renameConversation')->willReturnCallback(function(AgentConversationRequest $request, string $id, string $title, string $source): AgentConversationState {
			$this->assertSame('conversation-1', $id);
			$this->assertSame('A meaningful title', $title);
			$this->assertSame(AgentConversation::TITLE_SOURCE_MANUAL, $source);
			return $this->conversationState();
		});
		$this->makeConversationService($runtime)->renameConversation('chatbot', 'example', 'conversation-1', '  <strong>A</strong>   meaningful  title!!! ');
	}

	public function testMaterializeRejectsMalformedAndUnknownDraftIdentifiers(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('createConversation');
		$service = $this->makeConversationService($runtime);
		foreach (['short', str_repeat('z', 48), str_repeat('a', 47)] as $id) {
			try {
				$service->materializeConversation('chatbot', 'example', $id);
				$this->fail('Invalid draft accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('draft id', $exception->getMessage());
			}
		}
		try {
			$service->materializeConversation('chatbot', 'example', str_repeat('a', 48));
			$this->fail('Unknown draft accepted.');
		}
		catch (RuntimeException $exception) {
			$this->assertSame('Conversation draft not found.', $exception->getMessage());
		}
	}

	public function testAutomaticTitleSkipsManuallyRenamedConversation(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->conversationState(AgentConversation::TITLE_SOURCE_MANUAL, [['role' => 'user', 'content' => 'Question'], ['role' => 'assistant', 'content' => 'Answer']]));
		$text = $this->createMock(IAgentTextTaskService::class);
		$text->expects($this->never())->method('executeTextTask');
		$result = $this->makeConversationService($runtime, [], true, $text)->generateAutomaticTitle('chatbot', 'example', 'conversation-1');
		$this->assertSame(AgentConversation::TITLE_SOURCE_MANUAL, $result->getState()->getActiveConversation()?->getTitleSource());
	}

	public function testAutomaticTitleSkipsWhenDisabledOrNoCompleteTurnExists(): void {
		foreach ([['automatic_chat_titles' => false], []] as $settings) {
			$runtime = $this->createStub(IAgentConversationService::class);
			$runtime->method('getState')->willReturn($this->conversationState());
			$text = $this->createMock(IAgentTextTaskService::class);
			$text->expects($this->never())->method('executeTextTask');
			$this->assertSame('Chat title', $this->makeConversationService($runtime, $settings, true, $text)
				->generateAutomaticTitle('chatbot', 'example', 'conversation-1')->getState()->getActiveConversation()?->getTitle());
		}
	}

	public function testAutomaticTitleRejectsMissingConversationBeforeTextTask(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn(new AgentConversationState([], null, [], 'assistant'));
		$this->expectException(RuntimeException::class);
		$this->makeConversationService($runtime)->generateAutomaticTitle('chatbot', 'example', 'missing');
	}

	public function testAutomaticTitleUsesFirstNonCancelledTurnAndNormalizesResult(): void {
		$messages = [
			['role' => 'user', 'content' => 'ignored', 'status' => 'cancelled'],
			['role' => 'assistant', 'content' => 'ignored'],
			['role' => 'user', 'content' => ' <strong>Actual</strong> question '],
			['role' => 'assistant', 'content' => '  Actual answer  ']
		];
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturn($this->conversationState(AgentConversation::TITLE_SOURCE_TEMPORARY, $messages));
		$runtime->expects($this->once())->method('renameConversation')->willReturnCallback(function(AgentConversationRequest $request, string $id, string $title, string $source) use ($messages): AgentConversationState {
			$this->assertSame('conversation-1', $id);
			$this->assertSame('Generated title', $title);
			$this->assertSame(AgentConversation::TITLE_SOURCE_AUTOMATIC, $source);
			return $this->conversationState(AgentConversation::TITLE_SOURCE_AUTOMATIC, $messages);
		});
		$text = $this->createMock(IAgentTextTaskService::class);
		$text->expects($this->once())->method('executeTextTask')->willReturnCallback(function($request): AgentTextTaskResult {
			$this->assertStringContainsString('Actual question', $request->getPrompt());
			$this->assertStringContainsString('Actual answer', $request->getPrompt());
			$this->assertStringNotContainsString('ignored', $request->getPrompt());
			return new AgentTextTaskResult(' "Generated title!!!" ');
		});
		$result = $this->makeConversationService($runtime, [], true, $text)->generateAutomaticTitle('chatbot', 'example', 'conversation-1');
		$this->assertSame(AgentConversation::TITLE_SOURCE_AUTOMATIC, $result->getState()->getActiveConversation()?->getTitleSource());
	}

	public function testAutomaticTitleFailureRetainsExistingConversationState(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturn($this->conversationState(AgentConversation::TITLE_SOURCE_TEMPORARY, [
			['role' => 'user', 'content' => 'Question'],
			['role' => 'assistant', 'content' => 'Answer']
		]));
		$runtime->expects($this->never())->method('renameConversation');
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturnCallback(static function(): never { throw new RuntimeException('provider is unavailable'); });
		$result = $this->makeConversationService($runtime, [], true, $text)->generateAutomaticTitle('chatbot', 'example', 'conversation-1');
		$this->assertSame('Chat title', $result->getState()->getActiveConversation()?->getTitle());
	}

	public function testRepeatedStateReadsReuseAnExistingDraft(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn(new AgentConversationState([], null, [], 'assistant'));
		$service = $this->makeConversationService($runtime);
		$first = $service->getState('chatbot', 'example');
		$second = $service->getState('chatbot', 'example');
		$this->assertSame($first->getDraft()?->getId(), $second->getDraft()?->getId());
	}

	public function testMaterializeEmptyDraftCreatesReservedConversationOnce(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturn(new AgentConversationState([], null, [], 'assistant'));
		$runtime->expects($this->once())->method('createConversation')->willReturnCallback(function(
			AgentConversationRequest $request,
			?string $id,
			string $title,
			string $source,
			string $opening
		): AgentConversationState {
			$this->assertTrue(is_string($id) && str_starts_with($id, 'conversation-'));
			$this->assertStringContainsString('Chat ', $title);
			$this->assertSame(AgentConversation::TITLE_SOURCE_TEMPORARY, $source);
			$conversation = new AgentConversation($id, $title, $source, $opening, '2026-11-03', '2026-11-03', '2026-11-03');
			return new AgentConversationState([$conversation], $conversation, [], 'assistant');
		});
		$runtime->expects($this->never())->method('appendMessage');
		$service = $this->makeConversationService($runtime);
		$draft = $service->createConversation('chatbot', 'example')->getDraft();
		$this->assertNotNull($draft);
		$materialized = $service->materializeConversation('chatbot', 'example', $draft->getId());
		$this->assertSame($draft->getConversationId(), $materialized->getState()->getActiveConversation()?->getId());
		$this->assertNull($materialized->getDraft());
	}

	public function testMaterializeExistingConversationDoesNotCreateDuplicate(): void {
		$id = '';
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function() use (&$id): AgentConversationState {
			if ($id === '') {
				return new AgentConversationState([], null, [], 'assistant');
			}
			$conversation = new AgentConversation($id, 'Existing', AgentConversation::TITLE_SOURCE_TEMPORARY, '', '2026-11-03', '2026-11-03', '2026-11-03');
			return new AgentConversationState([$conversation], null, [], 'assistant');
		});
		$runtime->expects($this->never())->method('createConversation');
		$runtime->expects($this->never())->method('appendMessage');
		$runtime->expects($this->once())->method('activateConversation')->willReturnCallback(function(AgentConversationRequest $request, string $conversationId): AgentConversationState {
			$this->assertTrue(str_starts_with($conversationId, 'conversation-'));
			$conversation = new AgentConversation($conversationId, 'Existing', AgentConversation::TITLE_SOURCE_TEMPORARY, '', '2026-11-03', '2026-11-03', '2026-11-03');
			return new AgentConversationState([$conversation], $conversation, [], 'assistant');
		});
		$service = $this->makeConversationService($runtime);
		$draft = $service->createConversation('chatbot', 'example')->getDraft();
		$id = $draft->getConversationId();
		$result = $service->materializeConversation('chatbot', 'example', $draft->getId());
		$this->assertSame($id, $result->getState()->getActiveConversation()?->getId());
	}

	public function testDeleteWithoutNextActiveConversationCreatesFreshDraft(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('deleteConversation')->willReturn(new AgentConversationState([], null, [], 'assistant'));
		$service = $this->makeConversationService($runtime);
		$result = $service->deleteConversation('chatbot', 'example', 'conversation-1');
		$this->assertNull($result->getState()->getActiveConversation());
		$this->assertNotNull($result->getDraft());
		$this->assertSame([], $result->getState()->getMessages());
	}

	public function testAutomaticTitleEmptyModelResponseLeavesExistingTitleUntouched(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->conversationState(AgentConversation::TITLE_SOURCE_TEMPORARY, [
			['role' => 'user', 'content' => 'Question'],
			['role' => 'assistant', 'content' => 'Answer']
		]));
		$runtime->expects($this->never())->method('renameConversation');
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturn(new AgentTextTaskResult('  --  '));
		$result = $this->makeConversationService($runtime, [], true, $text)->generateAutomaticTitle('chatbot', 'example', 'conversation-1');
		$this->assertSame('Chat title', $result->getState()->getActiveConversation()?->getTitle());
	}

	public function testMaterializeDoesNotAppendOpeningMessageTwiceWhenHistoryExists(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->method('getState')->willReturn(new AgentConversationState([], null, [], 'assistant'));
		$runtime->expects($this->once())->method('createConversation')->willReturnCallback(static function(
			AgentConversationRequest $request, ?string $id, string $title, string $source, string $opening
		): AgentConversationState {
			$conversation = new AgentConversation($id, $title, $source, $opening, '2026-11-03', '2026-11-03', '2026-11-03');
			return new AgentConversationState([$conversation], $conversation, [['role' => 'assistant', 'content' => $opening]], 'assistant');
		});
		$runtime->expects($this->never())->method('appendMessage');
		$service = $this->makeConversationService($runtime, [
			'first_message_mode' => 'random', 'first_messages' => ['Welcome to the chat.']
		]);
		$draft = $service->createConversation('chatbot', 'example')->getDraft();
		$this->assertSame('Welcome to the chat.', $draft->getMessages()[0]['content']);
		$result = $service->materializeConversation('chatbot', 'example', $draft->getId());
		$this->assertSame(1, count($result->getState()->getMessages()));
	}

	public function testMaterializationFailureDoesNotRemoveDraft(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn(new AgentConversationState([], null, [], 'assistant'));
		$runtime->method('createConversation')->willReturnCallback(static function(): never { throw new RuntimeException('Storage unavailable'); });
		$service = $this->makeConversationService($runtime);
		$draft = $service->createConversation('chatbot', 'example')->getDraft();
		try {
			$service->materializeConversation('chatbot', 'example', $draft->getId());
			$this->fail('Storage failure must propagate.');
		}
		catch (RuntimeException $exception) {
			$this->assertSame('Storage unavailable', $exception->getMessage());
		}
		$this->assertSame($draft->getId(), $service->getState('chatbot', 'example')->getDraft()?->getId());
	}

	private function makeConversationService(
		IAgentConversationService $runtime,
		array $overrides = [],
		bool $configured = true,
		?IAgentTextTaskService $text = null
	): ChatbotConversationService {
		$settings = array_replace([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'db-memory',
			'first_message_mode' => 'none'
		], $overrides);
		$store = $this->createStub(ISettingsStore::class);
		$store->method('has')->willReturn($configured);
		$store->method('get')->willReturn($settings);
		$settingsService = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		$text ??= $this->createStub(IAgentTextTaskService::class);
		return new ChatbotConversationService(
			$runtime,
			$text,
			$this->createStub(IAgentSuspensionRepository::class),
			$settingsService,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $settingsService, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
	}

	private function conversationState(string $source = AgentConversation::TITLE_SOURCE_TEMPORARY, array $messages = []): AgentConversationState {
		$conversation = new AgentConversation(
			'conversation-1', 'Chat title', $source, '',
			'2026-10-01T10:00:00+00:00', '2026-10-01T10:00:00+00:00', '2026-10-01T10:00:00+00:00'
		);
		return new AgentConversationState([$conversation], $conversation, $messages, 'assistant');
	}
}
