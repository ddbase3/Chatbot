<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\Api\IChatbotService;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\State\Api\IStateStore;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotTurnResponderTest extends TestCase {

	public function testRestExecutionExceptionIsLogged(): void {
		$exception = new RuntimeException('Deliberate test failure.');
		$service = $this->createMock(IChatbotService::class);
		$service->expects($this->once())
			->method('executeTurn')
			->willThrowException($exception);

		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->callback(static fn(string $message): bool =>
					str_contains($message, 'Chatbot REST turn failed')
						&& str_contains($message, 'Deliberate test failure.')
						&& str_contains($message, 'config=chatbot/test')
					),
				$this->callback(static fn(array $context): bool =>
					($context['scope'] ?? null) === 'chatbot'
						&& ($context['exception'] ?? null) === $exception
				)
			);

		$responder = new ChatbotTurnResponder(
			$this->createStub(ILanguage::class),
			new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
			$logger
		);
		$result = json_decode($responder->respondRest(
			$service,
			new ChatbotTurnRequest([
				'config_group' => 'chatbot',
				'config_name' => 'test',
				'prompt' => 'Hello'
			])
		), true);

		$this->assertSame('error', $result['type'] ?? null);
		$this->assertStringContainsString('Deliberate test failure.', (string)($result['text'] ?? ''));
	}
}
