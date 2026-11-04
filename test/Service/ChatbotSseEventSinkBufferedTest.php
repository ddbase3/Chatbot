<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Dto\AgentExecutionEvent;
use Chatbot\Service\SseAgentEventSink;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit-level checks for frames without terminating PHPUnit's own buffers.
 * Actual transport start is exercised by ChatbotSseTransportIntegrationTest.
 */
final class ChatbotSseEventSinkBufferedTest extends TestCase {

	public function testTokenFramePreservesUnicodeAndForwardSlashes(): void {
		[$wire, $sink] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('token', ['text' => 'Grüße 🌍', 'url' => 'https://example.test/a/b']));
		});
		$this->assertSame("event: token\ndata: {\"text\":\"Grüße 🌍\",\"url\":\"https://example.test/a/b\"}\n\n", $wire);
		$this->assertTrue($sink->hasEmitted('token'));
		$this->assertFalse($sink->hasEmitted('done'));
	}

	public function testDoneEventIsNotWrittenUntilFinish(): void {
		[$wire, $sink] = $this->buffered(function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('done', ['status' => 'suspended']));
			$this->assertFalse($sink->hasEmitted('done'));
			$sink->emit(new AgentExecutionEvent('token', ['text' => 'working']));
			$sink->finish();
		});
		$this->assertSame("event: token\ndata: {\"text\":\"working\"}\n\nevent: done\ndata: {\"status\":\"suspended\"}\n\n", $wire);
		$this->assertTrue($sink->hasEmitted('done'));
	}

	public function testFinishWithoutPendingDoneUsesCompletedStatus(): void {
		[$wire, $sink] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->finish();
		});
		$this->assertSame("event: done\ndata: {\"status\":\"completed\"}\n\n", $wire);
		$this->assertTrue($sink->hasEmitted('done'));
	}

	public function testExplicitTerminalStatusOverridesPendingStatus(): void {
		[$wire] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('done', ['status' => 'completed', 'trace' => 'kept']));
			$sink->finish('error');
		});
		$this->assertSame("event: done\ndata: {\"status\":\"error\",\"trace\":\"kept\"}\n\n", $wire);
	}

	public function testBlankPendingStatusGetsCompletedDefault(): void {
		[$wire] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('done', ['status' => ' ', 'trace' => 1]));
			$sink->finish();
		});
		$this->assertSame("event: done\ndata: {\"status\":\"completed\",\"trace\":1}\n\n", $wire);
	}

	public function testSecondFinishAndLateTokensAreIgnored(): void {
		[$wire, $sink] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('token', ['text' => 'first']));
			$sink->finish('cancelled');
			$sink->emit(new AgentExecutionEvent('token', ['text' => 'late']));
			$sink->finish('error');
		});
		$this->assertSame("event: token\ndata: {\"text\":\"first\"}\n\nevent: done\ndata: {\"status\":\"cancelled\"}\n\n", $wire);
		$this->assertTrue($sink->hasEmitted('done'));
	}

	public function testMultipleTokensKeepWireOrderAndTrimNames(): void {
		[$wire] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent(' token ', ['text' => 'A']));
			$sink->emit(new AgentExecutionEvent('token', ['text' => 'B']));
		});
		$this->assertSame("event: token\ndata: {\"text\":\"A\"}\n\nevent: token\ndata: {\"text\":\"B\"}\n\n", $wire);
	}

	public function testMalformedUtf8IsSafelySubstituted(): void {
		[$wire] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('token', ['text' => "\xC3\x28"]));
		});
		$this->assertSame("event: token\ndata: {\"text\":\"�(\"}\n\n", $wire);
	}

	public function testUnencodableRecursivePayloadBecomesValidEmptyObject(): void {
		[$wire] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$payload = [];
			$payload['self'] = &$payload;
			$sink->emit(new AgentExecutionEvent('diagnostic', $payload));
		});
		$this->assertSame("event: diagnostic\ndata: {}\n\n", $wire);
	}

	public function testLatestBufferedDoneReplacesPreviousTerminalPayload(): void {
		[$wire] = $this->buffered(static function(SseAgentEventSink $sink): void {
			$sink->emit(new AgentExecutionEvent('done', ['status' => 'started', 'marker' => 1]));
			$sink->emit(new AgentExecutionEvent('done', ['status' => 'suspended', 'marker' => 2]));
			$sink->finish();
		});
		$this->assertSame("event: done\ndata: {\"status\":\"suspended\",\"marker\":2}\n\n", $wire);
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
}
