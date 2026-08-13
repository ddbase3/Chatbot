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

use AssistantFoundation\Api\ITextToSpeechService;
use AssistantFoundation\Dto\TextToSpeechRequest;
use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\HttpTextToSpeechStream;
use RuntimeException;
use Throwable;

/**
 * Streams one audio response through the service selected for a chatbot instance.
 */
final class TextToSpeechOutput implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly ISettingsStore $settingsStore,
		private readonly ITextToSpeechService $textToSpeechService
	) {}

	public static function getName(): string {
		return 'texttospeech';
	}

	public function getOutput(string $out = 'audio', bool $final = false): string {
		$stream = new HttpTextToSpeechStream($final);

		try {
			$data = $this->request->getJsonBody();
			$serviceId = $this->getConfiguredServiceId();
			$text = trim((string)($data['text'] ?? ''));
			$language = trim((string)($data['language'] ?? ''));
			$options = is_array($data['options'] ?? null) ? $data['options'] : [];

			if($text === '') {
				return $this->error('Missing text-to-speech input.', 400, $final);
			}

			$this->textToSpeechService->stream(
				new TextToSpeechRequest($serviceId, $text, $language, $options),
				$stream
			);

			return $stream->getOutput();
		}
		catch(Throwable $exception) {
			if($final && $stream->hasStarted()) {
				return '';
			}

			return $this->error($exception->getMessage(), 500, $final);
		}
	}

	public function getHelp(): string {
		return 'Streams audio through the text-to-speech service selected for one chatbot instance.';
	}

	private function getConfiguredServiceId(): string {
		$group = trim((string)$this->request->request('config_group', ''));
		$name = trim((string)$this->request->request('config_name', ''));
		if($group === '' || $name === '') {
			throw new RuntimeException('Missing chatbot configuration identity.');
		}

		$settings = $this->settingsStore->get($group, $name, []);
		$serviceId = $this->normalizeTechnicalKey((string)($settings['text_to_speech_service'] ?? ''));
		if($serviceId === '') {
			throw new RuntimeException('No text-to-speech service is configured for this chatbot.');
		}

		return $serviceId;
	}

	private function normalizeTechnicalKey(string $value): string {
		$value = strtolower(trim($value));

		return preg_replace('/[^a-z0-9._-]+/', '', $value) ?? '';
	}

	private function error(string $message, int $statusCode, bool $final): string {
		if($final && !headers_sent()) {
			http_response_code($statusCode);
			header('Content-Type: application/json; charset=UTF-8');
			header('Cache-Control: no-store, private');
		}

		$json = json_encode([
			'status' => 'error',
			'message' => $message
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

		return is_string($json) ? $json : '{"status":"error","message":"Response encoding failed."}';
	}
}
