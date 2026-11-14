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

use Base3\AssistantFoundation\Api\IAgentEventSink;
use Base3\AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Service\CancellableAgentEventSink;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
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
