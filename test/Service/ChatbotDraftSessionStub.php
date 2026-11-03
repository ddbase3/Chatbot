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
