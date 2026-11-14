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

namespace Base3\Chatbot\Test\Service;

use Base3\AssistantFoundation\Api\IAgentEventSink;
use Base3\AssistantFoundation\Api\IAgentExecutionService;
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Dto\AgentExecutionRequest;
use Base3\AssistantFoundation\Dto\AgentExecutionResult;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use Base3\Chatbot\Service\ChatbotTurnResponder;
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
