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
 * Immutable input for one chatbot turn.
 */
final class ChatbotTurnRequest {

	/** @var array<string,mixed> */
	private readonly array $payload;

	/** @param array<string,mixed> $payload */
	public function __construct(array $payload) {
		$payload['reference'] = $this->normalizeReference(
			$payload['reference'] ?? null,
			(string)($payload['reference_format'] ?? '')
		);
		$payload['resume'] = $this->normalizeResume($payload);
		$payload['conversation_id'] = $this->normalizeConversationId($payload['conversation_id'] ?? null);
		$payload['conversation_owner_key'] = $this->normalizeOwnerKey($payload['conversation_owner_key'] ?? null);
		$this->payload = $payload;
	}

	/** @return array<string,mixed> */
	public function getPayload(): array {
		return $this->payload;
	}

	public function getString(string $key, string $default = ''): string {
		if (!array_key_exists($key, $this->payload)) {
			return $default;
		}

		$value = $this->payload[$key];
		if (!is_scalar($value) && $value !== null) {
			return $default;
		}

		return trim((string)$value);
	}

	/** @return array<string,mixed> */
	public function getArray(string $key): array {
		$value = $this->payload[$key] ?? null;

		return is_array($value) ? $value : [];
	}

	public function hasPromptOrResume(): bool {
		return $this->getPrompt() !== '' || $this->getResume() !== [];
	}

	public function getPrompt(): string {
		$prompt = $this->getString('prompt');

		return $prompt !== '' ? $prompt : $this->getString('user');
	}

	/** @return array<string,mixed> */
	public function getResume(): array {
		return $this->getArray('resume');
	}

	public function getResumeResponseText(): string {
		$resume = $this->getResume();

		return trim((string)($resume['response_text'] ?? ''));
	}

	public function getConfigGroup(): string {
		return $this->getString('config_group');
	}

	public function getConfigName(): string {
		return $this->getString('config_name');
	}

	public function getTransportMode(): string {
		return strtolower($this->getString('transport_mode'));
	}

	public function getConversationId(): string {
		return $this->getString('conversation_id');
	}

	public function getConversationOwnerKey(): string {
		return $this->getString('conversation_owner_key');
	}

	/** @return array<string,mixed> */
	public function getReference(): array {
		return $this->getArray('reference');
	}

	private function normalizeOwnerKey(mixed $value): string {
		if (!is_scalar($value) && $value !== null) {
			return '';
		}

		$value = strtolower(trim((string)$value));

		return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
	}

	private function normalizeConversationId(mixed $value): string {
		if (!is_scalar($value) && $value !== null) {
			return '';
		}

		$value = substr(trim((string)$value), 0, 100);

		return preg_replace('/[^A-Za-z0-9._:-]+/', '', $value) ?? '';
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function normalizeResume(array $payload): array {
		$raw = $payload['resume'] ?? null;
		$resume = [];

		if (is_array($raw)) {
			$resume = $raw;
		}
		elseif (is_string($raw) && trim($raw) !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				$resume = $decoded;
			}
		}

		$handle = trim((string)($resume['resume_handle'] ?? $payload['resume_handle'] ?? ''));
		if ($handle === '') {
			return [];
		}

		$responses = $resume['responses'] ?? $payload['resume_responses'] ?? [];
		if (is_string($responses) && trim($responses) !== '') {
			$decoded = json_decode($responses, true);
			$responses = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($responses)) {
			$responses = [];
		}

		$result = [
			'resume_handle' => $handle,
			'responses' => $responses
		];
		$responseText = trim((string)($resume['response_text'] ?? $payload['resume_response'] ?? ''));
		if ($responseText !== '') {
			$result['response_text'] = $responseText;
		}

		return $result;
	}

	/** @return array<string,mixed> */
	private function normalizeReference(mixed $raw, string $format): array {
		if (is_array($raw)) {
			return $this->normalizeArray($raw);
		}
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}

		$raw = trim($raw);
		if ($format !== 'base64json') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				return $this->normalizeArray($decoded);
			}
		}

		$base64 = strtr($raw, '-_', '+/');
		$padding = strlen($base64) % 4;
		if ($padding > 0) {
			$base64 .= str_repeat('=', 4 - $padding);
		}
		$json = base64_decode($base64, true);
		if (!is_string($json) || $json === '') {
			return [];
		}
		$decoded = json_decode($json, true);

		return is_array($decoded) ? $this->normalizeArray($decoded) : [];
	}

	/** @param array<string|int,mixed> $data @return array<string|int,mixed> */
	private function normalizeArray(array $data, int $depth = 0): array {
		if ($depth > 5) {
			return [];
		}

		$result = [];
		foreach ($data as $key => $value) {
			if (!is_string($key) && !is_int($key)) {
				continue;
			}
			if (is_scalar($value) || $value === null) {
				$result[$key] = $value;
				continue;
			}
			if (is_array($value)) {
				$result[$key] = $this->normalizeArray($value, $depth + 1);
			}
		}

		return $result;
	}
}
