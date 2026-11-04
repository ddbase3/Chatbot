<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentExecutionStatus;
use AssistantFoundation\Dto\AgentSuspensionScope;
use AssistantFoundation\Dto\AgentSuspensionState;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConversationSuspensionOrderingTest extends TestCase {

	public function testHistoricalSuspensionDoesNotHideFirstPendingApproval(): void {
		$historical = $this->newStoredSuspensionFixture(false, AgentExecutionStatus::COMPLETED, 'old', AgentSuspensionState::LIFECYCLE_RESOLVED);
		$active = $this->newStoredSuspensionFixture(true, AgentExecutionStatus::AWAITING_APPROVAL, 'current');
		$repository = $this->createMock(IAgentSuspensionRepository::class);
		$repository->expects($this->once())->method('findAll')->with(AgentSuspensionScope::forConversation(
			'chatbot:' . hash('sha256', "chatbot\0sidebar"), 'conversation-1'
		))->willReturn([$historical, $active]);
		$result = $this->newConversationServiceFixture($this->newRuntimeWithActiveConversation(), $repository)->getState('chatbot', 'sidebar');
		$this->assertSame($active, $result->getPendingSuspension());
		$this->assertSame([$historical, $active], $result->getSuspensions());
		$this->assertSame('current', $result->toArray()['pending_interaction']['resume_handle']);
		$this->assertSame('', $result->toArray()['interactions'][0]['resume_handle']);
	}

	public function testFirstPendingSuspensionWinsWhenSeveralAreActive(): void {
		$first = $this->newStoredSuspensionFixture(true, AgentExecutionStatus::AWAITING_INPUT, 'first');
		$second = $this->newStoredSuspensionFixture(true, AgentExecutionStatus::AWAITING_APPROVAL, 'second');
		$repository = $this->createStub(IAgentSuspensionRepository::class);
		$repository->method('findAll')->willReturn([$first, $second]);
		$result = $this->newConversationServiceFixture($this->newRuntimeWithActiveConversation(), $repository)->getState('chatbot', 'sidebar');
		$this->assertSame($first, $result->getPendingSuspension());
		$this->assertCount(2, $result->toArray()['interactions']);
	}

	public function testResolvedHistoryDoesNotFabricatePendingInteraction(): void {
		$resolved = $this->newStoredSuspensionFixture(false, AgentExecutionStatus::COMPLETED, 'former', AgentSuspensionState::LIFECYCLE_RESOLVED);
		$repository = $this->createStub(IAgentSuspensionRepository::class);
		$repository->method('findAll')->willReturn([$resolved]);
		$result = $this->newConversationServiceFixture($this->newRuntimeWithActiveConversation(), $repository)->getState('chatbot', 'sidebar');
		$this->assertNull($result->getPendingSuspension());
		$this->assertCount(1, $result->toArray()['interactions']);
		$this->assertSame('', $result->toArray()['interactions'][0]['resume_handle']);
	}

	public function testConfiguredAssistantNodeAndReferenceArePassedToConversationRuntime(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturnCallback(function(AgentConversationRequest $request, string $id): AgentConversationState {
			$this->assertSame('conversation-1', $id);
			$this->assertSame('response-node', $request->getNodeId());
			$this->assertSame(['course' => 17], $request->getContext()['reference']);
			$this->assertSame('chatbot-conversation-api', $request->getContext()['source']);
			return $this->newExistingConversationState();
		});
		$result = $this->newConversationServiceFixture($runtime, null, ['agent_components_assistant_node' => ' response-node '])
			->getState('chatbot', 'sidebar', 'conversation-1', ['course' => 17]);
		$this->assertSame('conversation-1', $result->getState()->getActiveConversation()?->getId());
	}

	public function testDeletionWithRemainingActiveConversationDoesNotCreateDraft(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('deleteConversation')->willReturnCallback(function(AgentConversationRequest $request, string $id): AgentConversationState {
			$this->assertSame('conversation-to-delete', $id);
			return $this->newExistingConversationState();
		});
		$runtime->expects($this->never())->method('getState');
		$result = $this->newConversationServiceFixture($runtime)->deleteConversation('chatbot', 'sidebar', 'conversation-to-delete');
		$this->assertNull($result->getDraft());
		$this->assertSame('conversation-1', $result->getState()->getActiveConversation()?->getId());
	}

	public function testAvailabilityReturnsFalseWhenSettingsBackendThrows(): void {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturnCallback(static function(): never {
			throw new RuntimeException('Unavailable settings backend');
		});
		$service = $this->newConversationServiceFixture($this->createStub(IAgentConversationService::class), null, [], $store);
		$this->assertFalse($service->isAvailable('chatbot', 'sidebar'));
	}

	private function newConversationServiceFixture(
		IAgentConversationService $runtime,
		?IAgentSuspensionRepository $repository = null,
		array $overrides = [],
		?ISettingsStore $store = null
	): ChatbotConversationService {
		if ($store === null) {
			$store = $this->createStub(ISettingsStore::class);
			$store->method('get')->willReturn(array_merge([
				'chatbot_backend' => 'runtime:missionbay',
				'memory_profile' => 'memory',
				'first_message_mode' => 'none'
			], $overrides));
		}
		$store->method('has')->willReturn(true);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('selectRuntimeId')->willReturn('missionbay');
		$config = new ChatbotSettingsService($store, $selector);
		$text = $this->createStub(IAgentTextTaskService::class);
		$language = $this->createStub(ILanguage::class);
		return new ChatbotConversationService(
			$runtime,
			$text,
			$repository ?? $this->createStub(IAgentSuspensionRepository::class),
			$config,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $config, $language),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
	}

	private function newRuntimeWithActiveConversation(): IAgentConversationService {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->newExistingConversationState());
		return $runtime;
	}

	private function newExistingConversationState(): AgentConversationState {
		$conversation = new AgentConversation(
			'conversation-1', 'Existing chat', AgentConversation::TITLE_SOURCE_TEMPORARY, '',
			'2026-11-04T09:00:00Z', '2026-11-04T09:00:00Z', '2026-11-04T09:00:00Z'
		);
		return new AgentConversationState([$conversation], $conversation, [], 'assistant');
	}

	private function newStoredSuspensionFixture(bool $active, string $status, string $handle, string $lifecycle = AgentSuspensionState::LIFECYCLE_ACTIVE): AgentSuspensionState {
		return new AgentSuspensionState(
			$active, $status, [['id' => 'approval', 'kind' => 'approval']], $handle,
			'2026-11-04T09:00:00Z', '2026-11-04T10:00:00Z', $lifecycle, null, $handle
		);
	}
}

require_once __DIR__ . '/ChatbotDraftSessionStub.php';
