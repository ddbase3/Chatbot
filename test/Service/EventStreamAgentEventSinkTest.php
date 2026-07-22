<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Dto\AgentExecutionEvent;
use Chatbot\Service\EventStreamAgentEventSink;
use EventTransport\Api\IEventStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chatbot\Service\EventStreamAgentEventSink
 */
final class EventStreamAgentEventSinkTest extends TestCase {

	public function testEmitForwardsNamedEventAndPayload(): void {
		$stream = $this->createMock(IEventStream::class);
		$stream->method('isDisconnected')->willReturn(false);
		$stream->expects($this->once())
			->method('push')
			->with('token', ['text' => 'Hello']);

		$sink = new EventStreamAgentEventSink($stream);
		$sink->emit(new AgentExecutionEvent('token', ['text' => 'Hello']));
	}

	public function testEmitDoesNotPushAfterDisconnect(): void {
		$stream = $this->createMock(IEventStream::class);
		$stream->method('isDisconnected')->willReturn(true);
		$stream->expects($this->never())->method('push');

		$sink = new EventStreamAgentEventSink($stream);
		$sink->emit(new AgentExecutionEvent('token', ['text' => 'ignored']));
		$this->assertTrue($sink->isCancelled());
	}
}
