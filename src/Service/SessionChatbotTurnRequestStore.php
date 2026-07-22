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

use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Dto\PendingChatbotTurn;
use RuntimeException;

/**
 * Session-backed, one-time store for large chatbot turn payloads.
 */
final class SessionChatbotTurnRequestStore implements IChatbotTurnRequestStore {

	private const SESSION_KEY = 'chatbot_pending_turns';
	private const TTL_SECONDS = 300;

	public static function getName(): string {
		return 'sessionchatbotturnrequeststore';
	}

	public function store(PendingChatbotTurn $turn): string {
		$this->ensureSession();
		$this->removeExpiredEntries();

		$id = bin2hex(random_bytes(24));
		$_SESSION[self::SESSION_KEY][$id] = [
			'created_at' => time(),
			'expires_at' => time() + self::TTL_SECONDS,
			'turn' => $turn->toArray()
		];

		return $id;
	}

	public function claim(string $id): ?PendingChatbotTurn {
		$id = strtolower(trim($id));
		if (preg_match('/^[a-f0-9]{48}$/', $id) !== 1) {
			return null;
		}

		$this->ensureSession();
		$this->removeExpiredEntries();

		$entry = $_SESSION[self::SESSION_KEY][$id] ?? null;
		unset($_SESSION[self::SESSION_KEY][$id]);

		if (!is_array($entry) || !is_array($entry['turn'] ?? null)) {
			return null;
		}

		return PendingChatbotTurn::fromArray($entry['turn']);
	}

	private function ensureSession(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
				$_SESSION[self::SESSION_KEY] = [];
			}
			return;
		}

		if (headers_sent()) {
			throw new RuntimeException('Chatbot turn session cannot be started after headers were sent.');
		}

		if (!session_start()) {
			throw new RuntimeException('Chatbot turn session could not be started.');
		}

		if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
			$_SESSION[self::SESSION_KEY] = [];
		}
	}

	private function removeExpiredEntries(): void {
		$now = time();

		foreach ($_SESSION[self::SESSION_KEY] as $id => $entry) {
			$expiresAt = is_array($entry) ? (int)($entry['expires_at'] ?? 0) : 0;
			if ($expiresAt <= $now) {
				unset($_SESSION[self::SESSION_KEY][$id]);
			}
		}
	}
}
