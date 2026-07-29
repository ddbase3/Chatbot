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

use Base3\Session\Api\ISession;
use Chatbot\Dto\ChatbotConversationDraft;

/**
 * Session-backed transient drafts. Drafts are not part of conversation history.
 */
final class SessionChatbotConversationDraftStore {

	private const SESSION_KEY = 'chatbot_conversation_drafts';
	private const TTL_SECONDS = 3600;

	public function __construct(private readonly ISession $session) {}

	public static function getName(): string {
		return 'sessionchatbotconversationdraftstore';
	}

	public function save(string $channelId, ChatbotConversationDraft $draft): void {
		$data = $this->loadData();
		$this->removeExpired($data);
		$data[$this->channelKey($channelId)][$draft->getId()] = $draft->toStorageArray();
		$this->session->set(self::SESSION_KEY, $data);
	}

	public function get(string $channelId, string $draftId): ?ChatbotConversationDraft {
		$data = $this->loadData();
		$this->removeExpired($data);
		$this->session->set(self::SESSION_KEY, $data);
		$row = $data[$this->channelKey($channelId)][$draftId] ?? null;

		return is_array($row) ? ChatbotConversationDraft::fromArray($row) : null;
	}

	public function getLatest(string $channelId): ?ChatbotConversationDraft {
		$data = $this->loadData();
		$this->removeExpired($data);
		$this->session->set(self::SESSION_KEY, $data);
		$rows = $data[$this->channelKey($channelId)] ?? [];
		if (!is_array($rows) || $rows === []) {
			return null;
		}

		usort($rows, static fn(array $left, array $right): int =>
			(int)($right['created_at'] ?? 0) <=> (int)($left['created_at'] ?? 0)
		);
		return ChatbotConversationDraft::fromArray($rows[0]);
	}

	public function remove(string $channelId, string $draftId): void {
		$data = $this->loadData();
		$channelKey = $this->channelKey($channelId);
		unset($data[$channelKey][$draftId]);
		if (($data[$channelKey] ?? []) === []) {
			unset($data[$channelKey]);
		}
		$this->session->set(self::SESSION_KEY, $data);
	}

	/** @return array<string,array<string,array<string,mixed>>> */
	private function loadData(): array {
		$this->ensureStarted();
		$data = $this->session->get(self::SESSION_KEY, []);

		return is_array($data) ? $data : [];
	}

	/** @param array<string,array<string,array<string,mixed>>> $data */
	private function removeExpired(array &$data): void {
		$minimumCreatedAt = time() - self::TTL_SECONDS;
		foreach ($data as $channelKey => &$drafts) {
			if (!is_array($drafts)) {
				unset($data[$channelKey]);
				continue;
			}
			foreach ($drafts as $draftId => $draft) {
				if (!is_array($draft) || (int)($draft['created_at'] ?? 0) < $minimumCreatedAt) {
					unset($drafts[$draftId]);
				}
			}
			if ($drafts === []) {
				unset($data[$channelKey]);
			}
		}
		unset($drafts);
	}

	private function channelKey(string $channelId): string {
		$channelId = trim($channelId);
		if ($channelId === '') {
			throw new \InvalidArgumentException('Conversation draft requires a channel id.');
		}
		return hash('sha256', $channelId);
	}

	private function ensureStarted(): void {
		if (!$this->session->started() && !$this->session->start()) {
			throw new \RuntimeException('Chatbot conversation draft session could not be started.');
		}
	}
}
