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

use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantRuntime\Service\CollectingAgentEventSink;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(\Base3\Chatbot\Service\DummyChatbotService::class)]
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
			new ChatbotTurnResponder(
				$this->createStub(ILanguage::class),
				new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
				$this->createStub(ILogger::class)
			),
			$settingsService,
			new ChatbotOpeningMessageService(
				$this->createStub(IAgentTextTaskService::class),
				$settingsService,
				$this->createStub(ILanguage::class)
			)
		);
	}

}