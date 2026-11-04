<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\DummyChatbotService;
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
