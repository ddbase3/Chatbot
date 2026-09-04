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

/**
 * Adds user-requested cancellation to an existing transport event sink.
 */
final class CancellableAgentEventSink implements IAgentEventSink {

	private const CHECK_INTERVAL_SECONDS = 0.10;

	private bool $cancelled = false;
	private float $lastCheckAt = 0.0;

	public function __construct(
		private readonly IAgentEventSink $inner,
		private readonly ChatbotTurnCancellationService $cancellationService,
		private readonly string $turnId
	) {}

	public function emit(AgentExecutionEvent $event): void {
		if ($this->isCancelled()) {
			return;
		}

		$this->inner->emit($event);
	}

	public function isCancelled(): bool {
		if ($this->cancelled) {
			return true;
		}
		if ($this->inner->isCancelled()) {
			$this->cancelled = true;
			return true;
		}
		if (trim($this->turnId) === '') {
			return false;
		}

		$now = microtime(true);
		if ($this->lastCheckAt > 0.0 && ($now - $this->lastCheckAt) < self::CHECK_INTERVAL_SECONDS) {
			return false;
		}
		$this->lastCheckAt = $now;

		$this->cancelled = $this->cancellationService->isCancellationRequested($this->turnId);
		return $this->cancelled;
	}
}
