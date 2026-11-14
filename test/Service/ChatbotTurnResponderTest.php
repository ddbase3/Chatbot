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

use Base3\Chatbot\Api\IChatbotService;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnResponder;
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
