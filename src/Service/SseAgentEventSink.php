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
 * Chatbot-owned Server-Sent Events sink.
 *
 * Runtime done events are held until ChatbotTurnResponder commits the final
 * chatbot result. This keeps transport completion behind the boundary that
 * knows whether the turn completed, failed, or suspended for interaction.
 */
final class SseAgentEventSink implements IAgentEventSink {

	private bool $started = false;
	private bool $finished = false;

	/** @var array<string,mixed> */
	private array $pendingTerminalPayload = [];

	/** @var array<string,bool> */
	private array $emittedEvents = [];

	public function start(): void {
		if ($this->started) {
			return;
		}
		$this->started = true;

		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');
		@ini_set('output_buffering', 'off');

		if (!headers_sent()) {
			header_remove('Content-Type');
			header('Content-Type: text/event-stream');
			header('Cache-Control: no-cache, no-transform');
			header('X-Accel-Buffering: no');
			header('Content-Encoding: none');
			header('Connection: keep-alive');
		}

		if (function_exists('ob_implicit_flush')) {
			ob_implicit_flush(true);
		}

		@flush();
	}

	public function emit(AgentExecutionEvent $event): void {
		if ($this->isCancelled() || $this->finished) {
			return;
		}

		$name = trim($event->getName());
		if ($name === 'done') {
			$this->pendingTerminalPayload = $event->getPayload();
			return;
		}

		$this->writeEvent($name, $event->getPayload());
	}

	public function finish(?string $status = null): void {
		if ($this->isCancelled() || $this->finished) {
			return;
		}

		$this->finished = true;
		$payload = $this->pendingTerminalPayload;
		$terminalStatus = trim((string)$status);
		if ($terminalStatus !== '') {
			$payload['status'] = $terminalStatus;
		}
		elseif (trim((string)($payload['status'] ?? '')) === '') {
			$payload['status'] = 'completed';
		}
		$this->writeEvent('done', $payload);
		$this->pendingTerminalPayload = [];
	}

	public function isCancelled(): bool {
		return connection_aborted() === 1;
	}

	public function hasEmitted(string $eventName): bool {
		return isset($this->emittedEvents[$eventName]);
	}

	/** @param array<string,mixed> $payload */
	private function writeEvent(string $name, array $payload): void {
		$this->start();

		$name = trim($name);
		if ($name === '') {
			$name = 'message';
		}
		$this->emittedEvents[$name] = true;

		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
		if (!is_string($json)) {
			$json = '{}';
		}

		echo 'event: ' . $name . "\n";
		echo 'data: ' . $json . "\n\n";
		@flush();
	}
}
