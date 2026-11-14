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
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Api\IAgentSuspensionRepository;
use Base3\AssistantFoundation\Dto\AgentConversation;
use Base3\AssistantFoundation\Dto\AgentConversationRequest;
use Base3\AssistantFoundation\Dto\AgentConversationState;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\Session\Api\ISession;
use Base3\Chatbot\Output\ChatbotConversationCreateOutput;
use Base3\Chatbot\Output\ChatbotConversationStateOutput;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotConversationService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationOutputTest extends TestCase {

	public function testStateUsesServerGeneratedQueryIdentityInsteadOfBodyIdentity(): void {
		$conversation = new AgentConversation(
			'conversation-1',
			'Chat title',
			AgentConversation::TITLE_SOURCE_TEMPORARY,
			'',
			'2026-07-29T18:00:00+02:00',
			'2026-07-29T18:00:00+02:00',
			'2026-07-29T18:00:00+02:00'
		);
		$conversationRuntime = $this->createMock(IAgentConversationService::class);
		$conversationRuntime->expects($this->once())
			->method('getState')
			->with(
				$this->callback(static function(AgentConversationRequest $request): bool {
					$context = $request->getContext();
					return ($context['chatbot_config_group'] ?? null) === 'chatbot'
						&& ($context['chatbot_config_name'] ?? null) === 'page-component'
						&& ($context['conversation_channel_id'] ?? null)
							=== 'chatbot:' . hash('sha256', "chatbot\0page-component");
				}),
				'conversation-1'
			)
			->willReturn(new AgentConversationState([$conversation], $conversation, [], 'assistant'));

		$request = $this->createStub(IRequest::class);
		$request->method('allRequest')->willReturn([
			'config_group' => 'forged-body-group',
			'config_name' => 'forged-body-name',
			'conversation_id' => 'conversation-1'
		]);
		$request->method('getJsonBody')->willReturn([
			'config_group' => 'forged-json-group',
			'config_name' => 'forged-json-name'
		]);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => match ($key) {
			'config_group' => 'chatbot',
			'config_name' => 'page-component',
			default => $default
		});
		$request->method('server')->willReturnCallback(static fn(string $key, mixed $default = null): mixed =>
			$key === 'REQUEST_METHOD' ? 'GET' : $default
		);

		$output = new ChatbotConversationStateOutput(
			$request,
			$this->createConversationService($conversationRuntime)
		);
		$payload = json_decode($output->getOutput(), true);

		$this->assertTrue($payload['ok'] ?? false);
		$this->assertSame('conversation-1', $payload['data']['state']['active_conversation']['id'] ?? null);
	}

	public function testMutationRejectsGetRequest(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('server')->willReturnCallback(static fn(string $key, mixed $default = null): mixed =>
			$key === 'REQUEST_METHOD' ? 'GET' : $default
		);
		$request->method('allRequest')->willReturn([]);
		$request->method('getJsonBody')->willReturn([]);
		$request->method('get')->willReturn(null);

		$output = new ChatbotConversationCreateOutput(
			$request,
			$this->createConversationService($this->createStub(IAgentConversationService::class))
		);
		$payload = json_decode($output->getOutput(), true);

		$this->assertFalse($payload['ok'] ?? true);
		$this->assertSame('invalid_request', $payload['error']['code'] ?? null);
	}

	private function createConversationService(
		IAgentConversationService $conversationRuntime
	): ChatbotConversationService {
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('has')->willReturn(true);
		$settingsStore->method('get')->willReturn([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'database-memory',
			'first_message_mode' => 'none'
		]);
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('selectRuntimeId')->willReturn('missionbay');
		$settingsService = new ChatbotSettingsService($settingsStore, $runtimeSelector);
		$textTaskService = $this->createStub(IAgentTextTaskService::class);
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('en');

		$session = new ChatbotConversationOutputTestDouble1();

		return new ChatbotConversationService(
			$conversationRuntime,
			$textTaskService,
			$this->createStub(IAgentSuspensionRepository::class),
			$settingsService,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($textTaskService, $settingsService, $language),
			new SessionChatbotConversationDraftStore($session),
			$this->createStub(ILogger::class)
		);
	}
}

require_once __DIR__ . '/ChatbotConversationOutputTestDouble1.php';
