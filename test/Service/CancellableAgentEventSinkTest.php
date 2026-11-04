<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\State\Api\IStateStore;
use Chatbot\Service\CancellableAgentEventSink;
use Chatbot\Service\ChatbotTurnCancellationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CancellableAgentEventSinkTest extends TestCase {

	public function testNormalEventsReachInnerSink(): void {
		$event = new AgentExecutionEvent('token', ['text' => 'hello']);
		$inner = $this->createMock(IAgentEventSink::class);
		$inner->method('isCancelled')->willReturn(false);
		$inner->expects($this->once())->method('emit')->with($event);
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->never())->method('get');
		$sink = new CancellableAgentEventSink($inner, new ChatbotTurnCancellationService($state), '');
		$this->assertFalse($sink->isCancelled());
		$sink->emit($event);
	}

	public function testCancelledInnerSinkPreventsForwarding(): void {
		$event = new AgentExecutionEvent('token', ['text' => 'hidden']);
		$inner = $this->createMock(IAgentEventSink::class);
		$inner->method('isCancelled')->willReturn(true);
		$inner->expects($this->never())->method('emit');
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->never())->method('get');
		$sink = new CancellableAgentEventSink($inner, new ChatbotTurnCancellationService($state), 'turn-1');
		$this->assertTrue($sink->isCancelled());
		$sink->emit($event);
		$this->assertTrue($sink->isCancelled());
	}

	public function testRequestedCancellationStopsDeliveryAndRemainsSticky(): void {
		session_id('chatbot-event-sink-test');
		$inner = $this->createMock(IAgentEventSink::class);
		$inner->method('isCancelled')->willReturn(false);
		$inner->expects($this->never())->method('emit');
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->once())->method('get')->willReturn(true);
		$sink = new CancellableAgentEventSink($inner, new ChatbotTurnCancellationService($state), 'turn-1');
		$sink->emit(new AgentExecutionEvent('token', ['text' => 'private']));
		$this->assertTrue($sink->isCancelled());
		$this->assertTrue($sink->isCancelled());
	}

	public function testPendingMarkerFalseAllowsEmission(): void {
		session_id('chatbot-event-sink-test');
		$inner = $this->createMock(IAgentEventSink::class);
		$inner->method('isCancelled')->willReturn(false);
		$inner->expects($this->once())->method('emit');
		$state = $this->createStub(IStateStore::class);
		$state->method('get')->willReturn(false);
		$sink = new CancellableAgentEventSink($inner, new ChatbotTurnCancellationService($state), 'turn-1');
		$this->assertFalse($sink->isCancelled());
		$sink->emit(new AgentExecutionEvent('token'));
	}
}
