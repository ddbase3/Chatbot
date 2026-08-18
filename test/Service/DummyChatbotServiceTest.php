<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantRuntime\Service\CollectingAgentEventSink;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\DummyChatbotService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chatbot\Service\DummyChatbotService
 */
#[AllowMockObjectsWithoutExpectations]
final class DummyChatbotServiceTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('dummychatbotservice', DummyChatbotService::getName());
	}

	public function testExecuteTurnReturnsDummyMessageAndEvents(): void {
		$request = $this->createStub(IRequest::class);
		$service = $this->createService($request);
		$sink = new CollectingAgentEventSink();

		$result = $service->executeTurn(
			new ChatbotTurnRequest(['prompt' => 'Hello']),
			$sink
		);

		$this->assertSame('message', $result->getType());
		$this->assertStringContainsString('Hello', $result->getText());
		$this->assertNotEmpty($sink->getEvents());
	}

	public function testBasePromptUsesCanonicalStartMessageSettings(): void {
		$values = [
			'baseprompt' => '1',
			'config_group' => 'chatbot',
			'config_name' => 'dummy'
		];
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnCallback(
			static fn(string $key): mixed => $values[$key] ?? null
		);
		$request->method('request')->willReturnCallback(
			static fn(string $key): mixed => $values[$key] ?? null
		);

		$output = $this->createService($request, [
			'main_headings' => ['Welcome']
		])->getOutput('html');

		$this->assertSame('Welcome', $output);
	}

	public function testGetOutputReturnsJsonInRestMode(): void {
		$values = [
			'prompt' => 'Hello',
			'transport_mode' => 'rest'
		];
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnCallback(
			static fn(string $key): mixed => $values[$key] ?? null
		);
		$request->method('request')->willReturnCallback(
			static fn(string $key): mixed => $values[$key] ?? null
		);
		$service = $this->createService($request);

		$data = json_decode($service->getOutput('json'), true);

		$this->assertSame('message', $data['type'] ?? null);
		$this->assertStringContainsString('Hello', (string)($data['text'] ?? ''));
	}

	private function createService(IRequest $request, array $settings = []): DummyChatbotService {
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn($settings);
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$settingsService = new ChatbotSettingsService($settingsStore, $runtimeSelector);

		return new DummyChatbotService(
			$request,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder($this->createStub(ILanguage::class)),
			$settingsService,
			new ChatbotOpeningMessageService(
				$this->createStub(IAgentTextTaskService::class),
				$settingsService,
				$this->createStub(ILanguage::class)
			)
		);
	}

}