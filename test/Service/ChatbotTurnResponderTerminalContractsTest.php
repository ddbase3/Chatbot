<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\State\Api\IStateStore;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SseAgentEventSink;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class ChatbotTurnResponderTerminalContractsTest extends TestCase {

	public function testMessageFallbackEmitsIdAndTokenWhenBackendDidNotStream(): void {
		[$wire, $sink] = $this->buffered(function(SseAgentEventSink $sink): void {
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::message('message-7', 'Answer'));
		});
		$this->assertSame("event: msgid\ndata: {\"id\":\"message-7\"}\n\nevent: token\ndata: {\"text\":\"Answer\"}\n\nevent: done\ndata: {\"status\":\"completed\"}\n\n", $wire);
		$this->assertTrue($sink->hasEmitted('done'));
	}

	public function testMessageFallbackDoesNotDuplicatePreviouslyStreamedIdOrTokens(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('msgid', ['id' => 'streamed']));
			$sink->emit(new AgentExecutionEvent('token', ['text' => 'Already streamed']));
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::message('fallback', 'Do not duplicate'));
		});
		$this->assertStringContainsString('Already streamed', $wire);
		$this->assertStringNotContainsString('Do not duplicate', $wire);
		$this->assertStringNotContainsString('fallback', $wire);
	}

	public function testEmptyMessageDoesNotInventAToken(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::message('message-8', ''));
		});
		$this->assertStringContainsString('event: msgid', $wire);
		$this->assertStringNotContainsString('event: token', $wire);
	}

	public function testInteractionFallbackEmitsResumableEventAndTerminalStatus(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::interactionRequired('awaiting_input', 'resume-1', [['id' => 'request-1']]));
		});
		$this->assertStringContainsString('event: agent.interaction.required', $wire);
		$this->assertStringContainsString('"resume_handle":"resume-1"', $wire);
		$this->assertStringContainsString('"status":"awaiting_input"', $wire);
	}

	public function testInteractionFallbackDoesNotRepeatAnExistingInteractionEvent(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('agent.interaction.required', ['id' => 'already-streamed']));
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::interactionRequired('awaiting_approval', 'secret', []));
		});
		$this->assertSame(1, substr_count($wire, 'event: agent.interaction.required'));
		$this->assertStringNotContainsString('"resume_handle":"secret"', $wire);
	}

	public function testErrorFallbackEmitsErrorAndErrorTerminalStatus(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::error('Provider unavailable'));
		});
		$this->assertStringContainsString('event: error', $wire);
		$this->assertStringContainsString('"user_message":"Provider unavailable"', $wire);
		$this->assertStringContainsString('"status":"error"', $wire);
	}

	public function testErrorFallbackDoesNotDuplicateAlreadyEmittedError(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('error', ['message' => 'original']));
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::error('duplicate'));
		});
		$this->assertSame(1, substr_count($wire, 'event: error'));
		$this->assertStringNotContainsString('duplicate', $wire);
	}

	public function testCancelledTerminalResultIsMarkedCancelled(): void {
		[$wire] = $this->buffered(function(SseAgentEventSink $sink): void {
			$this->invoke($this->responder(), 'emitTerminalFallbacks', $sink, ChatbotTurnResult::cancelled());
		});
		$this->assertStringContainsString('"status":"cancelled"', $wire);
		$this->assertStringNotContainsString('event: token', $wire);
	}

	public function testTerminalStatusForNormalMessageDefersToPendingDonePayload(): void {
		$this->assertNull($this->invoke($this->responder(), 'resolveTerminalStatus', ChatbotTurnResult::message('id', 'answer'), []));
	}

	public function testTerminalStatusForCancellationAndErrorIsExplicit(): void {
		$this->assertSame('cancelled', $this->invoke($this->responder(), 'resolveTerminalStatus', ChatbotTurnResult::cancelled(), []));
		$this->assertSame('error', $this->invoke($this->responder(), 'resolveTerminalStatus', ChatbotTurnResult::error('failed'), []));
	}

	public function testInteractionTerminalStatusUsesNonBlankStatus(): void {
		$this->assertSame('awaiting_approval', $this->invoke($this->responder(), 'resolveTerminalStatus', ChatbotTurnResult::interactionRequired('awaiting_approval', 'resume', []), ['status' => ' awaiting_approval ']));
	}

	public function testInteractionTerminalStatusFallsBackForMissingStatus(): void {
		$this->assertSame('interaction_required', $this->invoke($this->responder(), 'resolveTerminalStatus', ChatbotTurnResult::interactionRequired('', 'resume', []), ['status' => ' ']));
	}

	public function testFailureLoggerRecordsTransportServiceContextAndOriginalException(): void {
		$exception = new RuntimeException('Remote model error');
		$service = $this->createStub(IChatbotService::class);
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())->method('error')->willReturnCallback(function(string|\Stringable $message, array $context) use ($exception): void {
			$this->assertStringContainsString('Chatbot SSE turn failed', (string)$message);
			$this->assertStringContainsString('config=tenant/sidebar', (string)$message);
			$this->assertStringContainsString('turn=turn-1', (string)$message);
			$this->assertSame('chatbot', $context['scope']);
			$this->assertSame($exception, $context['exception']);
		});
		$this->invoke($this->responder($logger), 'logExecutionFailure', $exception, $service, new ChatbotTurnRequest([
			'prompt' => 'hello', 'config_group' => 'tenant', 'config_name' => 'sidebar', 'turn_id' => 'turn-1'
		]), 'sse');
	}

	public function testCancellationCleanupIsNoOpWithoutTurnId(): void {
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->never())->method('delete');
		$this->invoke($this->responder(state: $state), 'clearCancellation', '');
	}

	/** @return array{0:string,1:SseAgentEventSink} */
	private function buffered(callable $action): array {
		$sink = new SseAgentEventSink();
		(new ReflectionProperty(SseAgentEventSink::class, 'started'))->setValue($sink, true);
		$level = ob_get_level();
		ob_start();
		try {
			$action($sink);
			$wire = (string)ob_get_contents();
		}
		finally {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
		}
		return [$wire, $sink];
	}

	private function invoke(ChatbotTurnResponder $responder, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod(ChatbotTurnResponder::class, $method))->invoke($responder, ...$args);
	}

	private function responder(?ILogger $logger = null, ?IStateStore $state = null): ChatbotTurnResponder {
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('en');
		return new ChatbotTurnResponder(
			$language,
			new ChatbotTurnCancellationService($state ?? $this->createStub(IStateStore::class)),
			$logger ?? $this->createStub(ILogger::class)
		);
	}
}
