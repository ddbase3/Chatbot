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

namespace Chatbot\Dto;

/**
 * Transient, session-scoped conversation draft that has not been persisted as a chat yet.
 */
final class ChatbotConversationDraft {

	/** @param array<int,array<string,mixed>> $messages */
	public function __construct(
		private readonly string $id,
		private readonly string $conversationId,
		private readonly string $openingMessage,
		private readonly array $messages,
		private readonly int $createdAt
	) {
		if (preg_match('/^[a-f0-9]{48}$/', $this->id) !== 1) {
			throw new \InvalidArgumentException('Conversation draft requires a valid id.');
		}
		if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $this->conversationId) !== 1) {
			throw new \InvalidArgumentException('Conversation draft requires a valid conversation id.');
		}
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		return new self(
			trim((string)($data['id'] ?? '')),
			trim((string)($data['conversation_id'] ?? '')),
			(string)($data['opening_message'] ?? ''),
			is_array($data['messages'] ?? null) ? array_values($data['messages']) : [],
			(int)($data['created_at'] ?? 0)
		);
	}

	public function getId(): string {
		return $this->id;
	}

	public function getConversationId(): string {
		return $this->conversationId;
	}

	public function getOpeningMessage(): string {
		return $this->openingMessage;
	}

	/** @return array<int,array<string,mixed>> */
	public function getMessages(): array {
		return $this->messages;
	}

	public function getCreatedAt(): int {
		return $this->createdAt;
	}

	/** @return array<string,mixed> */
	public function toStorageArray(): array {
		return [
			'id' => $this->id,
			'conversation_id' => $this->conversationId,
			'opening_message' => $this->openingMessage,
			'messages' => $this->messages,
			'created_at' => $this->createdAt
		];
	}

	/** @return array<string,mixed> */
	public function toClientArray(): array {
		return [
			'id' => $this->id,
			'opening_message' => $this->openingMessage,
			'messages' => $this->messages
		];
	}
}
