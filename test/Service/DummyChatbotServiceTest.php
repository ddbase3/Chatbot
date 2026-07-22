<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantRuntime\Service\CollectingAgentEventSink;
use Base3\Api\IRequest;
use Chatbot\Dto\ChatbotTurnRequest;
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
		$service = new DummyChatbotService(
			$request,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder()
		);
		$sink = new CollectingAgentEventSink();

		$result = $service->executeTurn(
			new ChatbotTurnRequest(['prompt' => 'Hello']),
			$sink
		);

		$this->assertSame('message', $result->getType());
		$this->assertStringContainsString('Hello', $result->getText());
		$this->assertNotEmpty($sink->getEvents());
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
		$service = new DummyChatbotService(
			$request,
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder()
		);

		$data = json_decode($service->getOutput('json'), true);

		$this->assertSame('message', $data['type'] ?? null);
		$this->assertStringContainsString('Hello', (string)($data['text'] ?? ''));
	}
}
