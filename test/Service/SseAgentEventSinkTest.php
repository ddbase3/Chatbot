<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Dto\AgentExecutionEvent;
use Chatbot\Service\SseAgentEventSink;
use PHPUnit\Framework\TestCase;

final class SseAgentEventSinkTest extends TestCase {

	public function testRuntimeDoneIsNotCommittedBeforeTheChatbotResponderFinishesTheTurn(): void {
		$sink = new SseAgentEventSink();
		$sink->emit(new AgentExecutionEvent('done', ['status' => 'completed']));

		$this->assertFalse($sink->hasEmitted('done'));
	}
}
