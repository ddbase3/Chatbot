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

use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChatbotTurnCancellationServiceTest extends TestCase {

	public function testRequestCancellationWritesSessionScopedMarkerWithTtlAndFlushes(): void {
		session_id('chatbot-cancellation-test');
		$key = $this->key('turn-1');
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->once())->method('set')->with($key, true, 600);
		$state->expects($this->once())->method('flush');
		(new ChatbotTurnCancellationService($state))->requestCancellation('turn-1');
	}

	public function testCancellationCheckIsStrictBoolean(): void {
		session_id('chatbot-cancellation-test');
		$state = $this->createStub(IStateStore::class);
		$state->method('get')->willReturnCallback(static function(string $key, mixed $default = null): mixed {
			return $key === 'chatbot.turn.cancel.' . hash('sha256', 'chatbot-cancellation-test') . '.turn-1' ? true : $default;
		});
		$service = new ChatbotTurnCancellationService($state);
		$this->assertTrue($service->isCancellationRequested('turn-1'));
		$this->assertFalse($service->isCancellationRequested('turn-2'));
	}

	public function testStrictBooleanRejectsTruthyNonBooleanMarkers(): void {
		session_id('chatbot-cancellation-test');
		$state = $this->createStub(IStateStore::class);
		$state->method('get')->willReturn(1);
		$this->assertFalse((new ChatbotTurnCancellationService($state))->isCancellationRequested('turn-1'));
	}

	public function testClearRemovesOnlyValidMarkersAndPersistsRemoval(): void {
		session_id('chatbot-cancellation-test');
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->once())->method('delete')->with($this->key('abc'))->willReturn(true);
		$state->expects($this->once())->method('flush');
		$service = new ChatbotTurnCancellationService($state);
		$service->clear('  ');
		$service->clear('  a?b/c  ');
	}

	public function testBlankOrInvalidTurnIdIsRejected(): void {
		session_id('chatbot-cancellation-test');
		$service = new ChatbotTurnCancellationService($this->createStub(IStateStore::class));
		foreach ([' ', '!?', "\n"] as $turnId) {
			try {
				$service->requestCancellation($turnId);
				$this->fail('Invalid cancellation ID was accepted.');
			} catch (RuntimeException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testTurnIdIsTruncatedToOneHundredCharacters(): void {
		session_id('chatbot-cancellation-test');
		$id = str_repeat('a', 110);
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->once())->method('set')->with($this->key(str_repeat('a', 100)), true, 600);
		$state->expects($this->once())->method('flush');
		(new ChatbotTurnCancellationService($state))->requestCancellation($id);
	}

	public function testCancellationWithoutSessionIdentityFailsClosed(): void {
		session_id('');
		$service = new ChatbotTurnCancellationService($this->createStub(IStateStore::class));
		$this->expectException(RuntimeException::class);
		$service->requestCancellation('turn-1');
	}

	private function key(string $turnId): string {
		return 'chatbot.turn.cancel.' . hash('sha256', 'chatbot-cancellation-test') . '.' . $turnId;
	}
}
