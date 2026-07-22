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
 * One prepared, single-use chatbot turn waiting for its SSE connection.
 */
final class PendingChatbotTurn {

	public function __construct(
		private readonly string $serviceId,
		private readonly ChatbotTurnRequest $request
	) {}

	public function getServiceId(): string {
		return $this->serviceId;
	}

	public function getRequest(): ChatbotTurnRequest {
		return $this->request;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'service_id' => $this->serviceId,
			'payload' => $this->request->getPayload()
		];
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): ?self {
		$serviceId = trim((string)($data['service_id'] ?? ''));
		$payload = $data['payload'] ?? null;

		if ($serviceId === '' || !is_array($payload)) {
			return null;
		}

		return new self($serviceId, new ChatbotTurnRequest($payload));
	}
}
