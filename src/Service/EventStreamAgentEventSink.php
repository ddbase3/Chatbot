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

namespace Chatbot\Service;

use AssistantFoundation\Api\IAgentEventSink;
use AssistantFoundation\Dto\AgentExecutionEvent;
use EventTransport\Api\IEventStream;

/**
 * Adapts transport-neutral agent events to the current EventTransport stream.
 */
final class EventStreamAgentEventSink implements IAgentEventSink {

	public function __construct(private readonly IEventStream $stream) {}

	public function emit(AgentExecutionEvent $event): void {
		if ($this->stream->isDisconnected()) {
			return;
		}

		$this->stream->push($event->getName(), $event->getPayload());
	}

	public function isCancelled(): bool {
		return $this->stream->isDisconnected();
	}
}
