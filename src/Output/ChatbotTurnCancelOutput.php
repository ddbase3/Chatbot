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
use Base3\Api\IRequest;
use Chatbot\Service\ChatbotTurnCancellationService;
use Throwable;

/**
 * Requests safe termination of one active chatbot turn.
 */
final class ChatbotTurnCancelOutput implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly ChatbotTurnCancellationService $cancellationService
	) {}

	public static function getName(): string {
		return 'chatbotturncancel';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		try {
			$turnId = $this->readTurnId();
			if ($turnId === '') {
				return $this->encode(['ok' => false, 'error' => 'Missing chatbot turn id.']);
			}

			$this->cancellationService->requestCancellation($turnId);
			return $this->encode([
				'ok' => true,
				'turn_id' => $turnId,
				'status' => 'cancellation_requested'
			]);
		}
		catch (Throwable $exception) {
			return $this->encode([
				'ok' => false,
				'error' => $exception->getMessage()
			]);
		}
	}

	public function getHelp(): string {
		return 'Requests cancellation of one active chatbot turn.';
	}

	private function readTurnId(): string {
		$data = $this->request->getJsonBody();
		$value = is_array($data) ? ($data['turn_id'] ?? null) : null;
		if ($value === null) {
			$value = $this->request->request('turn_id');
		}
		if (!is_scalar($value) && $value !== null) {
			return '';
		}

		return trim((string)$value);
	}

	/** @param array<string,mixed> $payload */
	private function encode(array $payload): string {
		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return is_string($json) ? $json : '{"ok":false,"error":"Response encoding failed."}';
	}
}
