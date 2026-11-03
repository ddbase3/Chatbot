<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Api\IAssistantResponseExtension;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use AssistantFoundation\Dto\AssistantResponseClientPlugin;
use AssistantRuntime\Service\CollectingAgentEventSink;
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
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

final class TestResponseExtension implements IAssistantResponseExtension {

	public static function getName(): string {
		return 'testresponseextension';
	}

	public function id(): string {
		return 'test-response';
	}

	public function getLabel(): string {
		return 'Test response';
	}

	public function getDescription(): string {
		return 'Test response extension.';
	}

	public function getPriority(): int {
		return 100;
	}

	public function isEnabledByDefault(): bool {
		return false;
	}

	public function getRequirements(): array {
		return [];
	}

	public function getSystemPrompt(array $context): string {
		return 'Test response extension prompt.';
	}

	public function getClientPlugin(array $context): ?AssistantResponseClientPlugin {
		return null;
	}

	public function getClientPluginOptions(array $context): array {
		return [];
	}
}
