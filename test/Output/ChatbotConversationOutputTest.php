<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\Session\Api\ISession;
use Chatbot\Output\ChatbotConversationCreateOutput;
use Chatbot\Output\ChatbotConversationStateOutput;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
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

		$request = $this->createMock(IRequest::class);
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
		$request = $this->createMock(IRequest::class);
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

		$session = new class implements ISession {
			private array $data = [];
			private bool $started = false;
			public function started(): bool { return $this->started; }
			public function getId(): string { return 'output-test'; }
			public function start(): bool { $this->started = true; return true; }
			public function destroy(): bool { $this->data = []; return true; }
			public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
			public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
			public function has(string $key): bool { return array_key_exists($key, $this->data); }
			public function remove(string $key): void { unset($this->data[$key]); }
		};

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
