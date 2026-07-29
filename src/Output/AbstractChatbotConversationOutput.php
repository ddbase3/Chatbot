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

namespace Chatbot\Output;

use Base3\Api\IOutput;
use Base3\Api\IOutputSchemaProvider;
use Base3\Api\IRequest;
use Chatbot\Service\ChatbotConversationService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Shared JSON boundary for chatbot conversation endpoints.
 */
abstract class AbstractChatbotConversationOutput implements IOutput, IOutputSchemaProvider {

	public function __construct(
		protected readonly IRequest $request,
		protected readonly ChatbotConversationService $conversationService
	) {}

	public function getOutput(string $out = 'json', bool $final = false): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
			header('Cache-Control: no-store, private');
			header('X-Content-Type-Options: nosniff');
		}

		try {
			$this->assertRequestMethod();
			$data = $this->handle($this->readInput());
			return $this->encode([
				'ok' => true,
				'data' => $data
			]);
		}
		catch (Throwable $exception) {
			return $this->encode([
				'ok' => false,
				'error' => [
					'code' => $this->getErrorCode($exception),
					'message' => $exception->getMessage()
				]
			]);
		}
	}

	public function getHelp(): string {
		return 'JSON endpoint for chatbot conversation state.';
	}

	public function getOutputSchemas(): array {
		return [
			'json' => [
				'type' => 'object',
				'required' => ['ok'],
				'properties' => [
					'ok' => ['type' => 'boolean'],
					'data' => ['type' => 'object'],
					'error' => [
						'type' => 'object',
						'properties' => [
							'code' => ['type' => 'string'],
							'message' => ['type' => 'string']
						]
					]
				]
			]
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	abstract protected function handle(array $input): array;

	/** @return array<int,string> */
	protected function getAllowedMethods(): array {
		return ['POST'];
	}

	/** @param array<string,mixed> $input */
	protected function requireString(array $input, string $key): string {
		$value = $input[$key] ?? null;
		if (!is_scalar($value) && $value !== null) {
			throw new InvalidArgumentException('Invalid request field: ' . $key);
		}
		$value = trim((string)$value);
		if ($value === '') {
			throw new InvalidArgumentException('Missing request field: ' . $key);
		}
		return $value;
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	protected function getReference(array $input): array {
		$reference = $input['reference'] ?? [];
		if (is_string($reference) && trim($reference) !== '') {
			$decoded = json_decode($reference, true);
			$reference = is_array($decoded) ? $decoded : [];
		}
		return is_array($reference) ? $this->normalizeArray($reference) : [];
	}

	/** @return array<string,mixed> */
	private function readInput(): array {
		$input = $this->request->allRequest();
		$json = $this->request->getJsonBody();
		if (is_array($json) && $json !== []) {
			$input = array_replace($input, $json);
		}

		foreach (['config_group', 'config_name'] as $identityKey) {
			$queryValue = $this->request->get($identityKey);
			if (is_scalar($queryValue) && trim((string)$queryValue) !== '') {
				$input[$identityKey] = trim((string)$queryValue);
			}
		}

		return is_array($input) ? $input : [];
	}

	private function assertRequestMethod(): void {
		$method = strtoupper(trim((string)$this->request->server('REQUEST_METHOD', '')));
		if ($method !== '' && !in_array($method, $this->getAllowedMethods(), true)) {
			throw new InvalidArgumentException('Unsupported request method: ' . $method);
		}
	}

	/** @param array<string,mixed> $payload */
	private function encode(array $payload): string {
		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
		return is_string($json)
			? $json
			: '{"ok":false,"error":{"code":"encoding_error","message":"Response encoding failed."}}';
	}

	private function getErrorCode(Throwable $exception): string {
		if ($exception instanceof InvalidArgumentException) {
			return 'invalid_request';
		}
		$message = strtolower($exception->getMessage());
		if (str_contains($message, 'not configured') || str_contains($message, 'requires a configured memory_profile')) {
			return 'conversation_unavailable';
		}
		if (str_contains($message, 'not found') || str_contains($message, 'does not exist')) {
			return 'conversation_not_found';
		}
		if ($exception instanceof RuntimeException) {
			return 'conversation_error';
		}
		return 'internal_error';
	}

	/** @param array<string|int,mixed> $value @return array<string|int,mixed> */
	private function normalizeArray(array $value, int $depth = 0): array {
		if ($depth > 5) {
			return [];
		}
		$result = [];
		foreach ($value as $key => $item) {
			if (!is_string($key) && !is_int($key)) {
				continue;
			}
			if (is_scalar($item) || $item === null) {
				$result[$key] = $item;
			}
			elseif (is_array($item)) {
				$result[$key] = $this->normalizeArray($item, $depth + 1);
			}
		}
		return $result;
	}
}
