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
 * Normalized terminal chatbot result used by REST and SSE responders.
 */
final class ChatbotTurnResult {

	/** @param array<string,mixed> $payload */
	private function __construct(
		private readonly string $type,
		private readonly array $payload
	) {}

	public static function message(string $id, string $text): self {
		return new self('message', [
			'id' => $id,
			'type' => 'message',
			'text' => $text,
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);
	}

	/** @param array<int,array<string,mixed>> $interactionRequests */
	public static function interactionRequired(
		string $status,
		string $resumeHandle,
		array $interactionRequests
	): self {
		return new self('interaction_required', [
			'id' => uniqid('msg_', true),
			'type' => 'interaction_required',
			'status' => $status,
			'resume_handle' => $resumeHandle,
			'interaction_requests' => $interactionRequests,
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);
	}

	public static function error(string $message): self {
		return new self('error', [
			'id' => uniqid('msg_', true),
			'type' => 'error',
			'text' => $message,
			'meta' => [
				'timestamp' => gmdate('c')
			]
		]);
	}

	public function getType(): string {
		return $this->type;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return $this->payload;
	}

	public function getId(): string {
		return trim((string)($this->payload['id'] ?? ''));
	}

	public function getText(): string {
		return (string)($this->payload['text'] ?? '');
	}
}
