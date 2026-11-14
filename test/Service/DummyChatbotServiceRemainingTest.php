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
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use Base3\Chatbot\Service\ChatbotTurnResponder;
use Base3\Chatbot\Service\DummyChatbotService;
use PHPUnit\Framework\TestCase;

final class DummyChatbotServiceRemainingTest extends TestCase {

	public function testEmptyRequestDoesNotCreateMessage(): void {
		$this->assertSame('', $this->makeService([])->getOutput());
	}

	public function testSuggestionRequestReturnsThreeSuggestedPromptsAsJson(): void {
		$data = json_decode($this->makeService(['suggestions' => '1'])->getOutput(), true);
		$this->assertCount(3, $data);
		$this->assertStringContainsString('Beispiel', $data[0]);
	}

	public function testBasePromptTakesPrecedenceOverSuggestions(): void {
		$service = $this->makeService([
			'baseprompt' => '1', 'suggestions' => '1', 'config_group' => 'chatbot', 'config_name' => 'dummy'
		], ['main_headings' => ['Hello']]);
		$this->assertSame('Hello', $service->getOutput());
	}

	public function testRuntimeEmitsMessageIdTokensAndCompletionEvent(): void {
		$events = [];
		$sink = $this->createStub(IAgentEventSink::class);
		$sink->method('isCancelled')->willReturn(false);
		$sink->method('emit')->willReturnCallback(static function(AgentExecutionEvent $event) use (&$events): void {
			$events[] = $event;
		});
		$result = $this->makeService([])->executeTurn(new ChatbotTurnRequest(['prompt' => 'Example']), $sink);
		$this->assertSame('message', $result->getType());
		$this->assertSame($result->getId(), $events[0]->getPayload()['id']);
		$this->assertSame('msgid', $events[0]->getName());
		$this->assertSame('done', $events[count($events) - 1]->getName());
		$this->assertSame('completed', $events[count($events) - 1]->getPayload()['status']);
		$this->assertSame(5, count(array_filter($events, static fn(AgentExecutionEvent $event): bool => $event->getName() === 'token')));
		$this->assertStringContainsString('Example', $result->getText());
	}

	public function testCancelledSinkSkipsTokensWithoutEmittingPartialTokenData(): void {
		$events = [];
		$sink = $this->createStub(IAgentEventSink::class);
		$sink->method('isCancelled')->willReturn(true);
		$sink->method('emit')->willReturnCallback(static function(AgentExecutionEvent $event) use (&$events): void {
			$events[] = $event->getName();
		});
		$this->makeService([])->executeTurn(new ChatbotTurnRequest(['prompt' => 'Please stop']), $sink);
		$this->assertSame(['msgid', 'done'], $events);
	}

	public function testStaticLabelsDescribeDummyOnlyService(): void {
		$this->assertSame('Dummy Chatbot Service', DummyChatbotService::getServiceLabel());
		$this->assertStringContainsString('without external AI', DummyChatbotService::getServiceDescription());
	}

	private function makeService(array $requestValues, array $settings = []): DummyChatbotService {
		$request = $this->createStub(IRequest::class);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($settings);
		$config = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		return new DummyChatbotService(
			$request,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder(
				$this->createStub(ILanguage::class),
				new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
				$this->createStub(ILogger::class)
			),
			$config,
			new ChatbotOpeningMessageService($this->createStub(IAgentTextTaskService::class), $config, $this->createStub(ILanguage::class))
		);
	}
}
