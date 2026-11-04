<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
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
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\TestCase;

final class ChatbotServiceFileFlowTest extends TestCase {

	public function testSystemPromptFileIsUsedOnlyWhenConfiguredPromptIsEmpty(): void {
		$this->withTemporaryFile('File-based instructions', function(string $path): void {
			$service = $this->newService([]);
			$service->systemPromptPath = $path;
			$this->assertSame('File-based instructions', $service->inspectSystemPrompt([]));
			$this->assertSame('Configured instructions', $service->inspectSystemPrompt(['system_prompt' => ' Configured instructions ']));
		});
	}

	public function testMissingSystemPromptFileFallsBackToDefault(): void {
		$service = $this->newService([]);
		$service->systemPromptPath = '/missing/chatbot-prompt-' . bin2hex(random_bytes(4));
		$this->assertSame('You are a helpful assistant.', $service->inspectSystemPrompt([]));
	}

	public function testWhitespaceOnlySystemPromptFileFallsBackToDefault(): void {
		$this->withTemporaryFile("  \n  ", function(string $path): void {
			$service = $this->newService([]);
			$service->systemPromptPath = $path;
			$this->assertSame('You are a helpful assistant.', $service->inspectSystemPrompt([]));
		});
	}

	public function testValidAgentFlowFileOverridesConfiguredFlow(): void {
		$this->withTemporaryFile('{"nodes":[{"id":"from-file"}]}', function(string $path): void {
			$captured = null;
			$service = $this->newService(['agent_flow' => ['nodes' => [['id' => 'settings']]]], $captured);
			$service->agentFlowPath = $path;
			$service->executeTurn($this->turn(), $this->createStub(IAgentEventSink::class));
			$this->assertSame(['nodes' => [['id' => 'from-file']]], $captured->getAgentConfiguration()['agent_flow']);
		});
	}

	public function testInvalidAgentFlowFileDoesNotOverwriteValidConfiguredFlow(): void {
		$this->withTemporaryFile('{invalid json', function(string $path): void {
			$captured = null;
			$configured = ['nodes' => [['id' => 'settings']]];
			$service = $this->newService(['agent_flow' => $configured], $captured);
			$service->agentFlowPath = $path;
			$service->executeTurn($this->turn(), $this->createStub(IAgentEventSink::class));
			$this->assertSame($configured, $captured->getAgentConfiguration()['agent_flow']);
		});
	}

	public function testSuggestionPromptFileOverridesDefaultButNotConfiguredPrompt(): void {
		$this->withTemporaryFile('File suggestions prompt', function(string $path): void {
			$captured = null;
			$service = $this->newService([], $captured, ['suggestions' => '1']);
			$service->suggestionPromptPath = $path;
			$this->assertSame(['Suggestion'], json_decode($service->getOutput(), true));
			$this->assertSame('File suggestions prompt', $captured->getInputs()['system']);
			$configured = null;
			$service = $this->newService(['suggestion_system_prompt' => 'Preferred prompt'], $configured, ['suggestions' => '1']);
			$service->suggestionPromptPath = $path;
			$service->getOutput();
			$this->assertSame('Preferred prompt', $configured->getInputs()['system']);
		});
	}

	public function testValidSuggestionFlowFileTakesPriorityOverConfiguredSuggestionFlow(): void {
		$this->withTemporaryFile('{"nodes":[{"id":"file-suggestions"}]}', function(string $path): void {
			$captured = null;
			$service = $this->newService(['suggestion_agent_flow' => ['nodes' => [['id' => 'settings-suggestions']]]], $captured, ['suggestions' => '1']);
			$service->suggestionFlowPath = $path;
			$service->getOutput();
			$this->assertSame(['nodes' => [['id' => 'file-suggestions']]], $captured->getAgentConfiguration()['agent_flow']);
		});
	}

	public function testInvalidSuggestionFlowFileFallsBackToConfiguredSuggestionFlow(): void {
		$this->withTemporaryFile('not JSON', function(string $path): void {
			$captured = null;
			$service = $this->newService(['suggestion_agent_flow' => ['nodes' => [['id' => 'settings-suggestions']]]], $captured, ['suggestions' => '1']);
			$service->suggestionFlowPath = $path;
			$service->getOutput();
			$this->assertSame(['nodes' => [['id' => 'settings-suggestions']]], $captured->getAgentConfiguration()['agent_flow']);
		});
	}

	private function withTemporaryFile(string $contents, callable $assertions): void {
		$path = tempnam(sys_get_temp_dir(), 'chatbot-test-');
		if ($path === false) {
			$this->fail('Cannot create temporary test file.');
		}
		try {
			file_put_contents($path, $contents);
			$assertions($path);
		}
		finally {
			unlink($path);
		}
	}

	private function turn(): ChatbotTurnRequest {
		return new ChatbotTurnRequest(['config_group' => 'chatbot', 'config_name' => 'example', 'prompt' => 'Test']);
	}

	private function newService(array $settings, mixed &$captured = null, array $values = []): ChatbotServiceFlowFilesTestDouble {
		$request = $this->createStub(IRequest::class);
		$requestValues = array_replace(['config_group' => 'chatbot', 'config_name' => 'example'], $values);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$execution = $this->createStub(IAgentExecutionService::class);
		$execution->method('execute')->willReturnCallback(static function(AgentExecutionRequest $request) use (&$captured): AgentExecutionResult {
			$captured = $request;
			return new AgentExecutionResult(['assistant' => ['message' => ['id' => 'message', 'content' => '["Suggestion"]']]]);
		});
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($settings);
		$config = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		$language = $this->createStub(ILanguage::class);
		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstancesByInterface')->willReturn([]);
		return new ChatbotServiceFlowFilesTestDouble(
			$request, $config, $execution,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder($language, new ChatbotTurnCancellationService($this->createStub(IStateStore::class)), $this->createStub(ILogger::class)),
			new ChatbotConversationChannelResolver(),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($classMap), $store),
			new ChatbotOpeningMessageService($this->createStub(IAgentTextTaskService::class), $config, $language)
		);
	}
}

require_once __DIR__ . '/ChatbotServiceFlowFilesTestDouble.php';
