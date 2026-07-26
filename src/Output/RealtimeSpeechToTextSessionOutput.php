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

use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Throwable;

/**
 * Creates one short-lived browser session for realtime speech transcription.
 */
final class RealtimeSpeechToTextSessionOutput implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IRealtimeSpeechToTextSessionService $sessionService
	) {}

	public static function getName(): string {
		return 'realtimespeechtotextsession';
	}

	public function getOutput(string $out = 'json', bool $final = false): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		$serviceId = trim((string)$this->request->request('service', ''));
		$language = trim((string)$this->request->request('language', ''));

		try {
			$session = $this->sessionService->createSession(
				new RealtimeSpeechToTextSessionRequest($serviceId, $language)
			);

			return $this->encode([
				'status' => 'ok',
				'data' => [
					'session' => $session->toArray()
				]
			]);
		}
		catch (Throwable $exception) {
			return $this->encode([
				'status' => 'error',
				'message' => $exception->getMessage()
			]);
		}
	}

	public function getHelp(): string {
		return 'Creates a short-lived realtime speech-to-text browser session.';
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function encode(array $payload): string {
		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return is_string($json) ? $json : '{"status":"error","message":"Response encoding failed."}';
	}
}
