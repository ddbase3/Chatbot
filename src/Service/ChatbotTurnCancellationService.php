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

use Base3\State\Api\IStateStore;
use RuntimeException;

/**
 * Stores short-lived cancellation markers for active chatbot turns.
 *
 * Markers are scoped to the current PHP session and one browser-generated
 * turn id. The running request reads the same marker through its event sink.
 */
final class ChatbotTurnCancellationService {

	private const TTL_SECONDS = 600;
	private const KEY_PREFIX = 'chatbot.turn.cancel.';

	public function __construct(
		private readonly IStateStore $stateStore
	) {}

	public static function getName(): string {
		return 'chatbotturncancellationservice';
	}

	public function requestCancellation(string $turnId): void {
		$key = $this->buildKey($turnId);
		$this->stateStore->set($key, true, self::TTL_SECONDS);
		$this->stateStore->flush();
	}

	public function isCancellationRequested(string $turnId): bool {
		return $this->stateStore->get($this->buildKey($turnId), false) === true;
	}

	public function clear(string $turnId): void {
		if (trim($turnId) === '') {
			return;
		}

		$this->stateStore->delete($this->buildKey($turnId));
		$this->stateStore->flush();
	}

	private function buildKey(string $turnId): string {
		$turnId = $this->normalizeTurnId($turnId);
		if ($turnId === '') {
			throw new RuntimeException('Chatbot cancellation requires a valid turn id.');
		}

		$sessionId = trim(session_id());
		if ($sessionId === '') {
			throw new RuntimeException('Chatbot cancellation requires an active session identity.');
		}

		return self::KEY_PREFIX . hash('sha256', $sessionId) . '.' . $turnId;
	}

	private function normalizeTurnId(string $turnId): string {
		$turnId = substr(trim($turnId), 0, 100);

		return preg_replace('/[^A-Za-z0-9._:-]+/', '', $turnId) ?? '';
	}
}
