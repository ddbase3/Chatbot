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
use Base3\Settings\Api\ISettingsStore;
use RuntimeException;
use Throwable;

/**
 * Creates one short-lived browser session for the speech-to-text service
 * selected for a chatbot instance.
 */
final class RealtimeSpeechToTextSessionOutput implements IOutput {

	private const DISABLED_SERVICE = 'off';

	public function __construct(
		private readonly IRequest $request,
		private readonly ISettingsStore $settingsStore,
		private readonly IRealtimeSpeechToTextSessionService $sessionService
	) {}

	public static function getName(): string {
		return 'realtimespeechtotextsession';
	}

	public function getOutput(string $out = 'json', bool $final = false): string {
		if($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
			header('Cache-Control: no-store, private');
		}

		$language = trim((string)$this->request->request('language', ''));
		$context = $this->normalizeContext((string)$this->request->request('context', ''));

		try {
			$serviceId = $this->getConfiguredServiceId();
			$options = $context !== '' ? ['context' => $context] : [];
			$session = $this->sessionService->createSession(
				new RealtimeSpeechToTextSessionRequest($serviceId, $language, $options)
			);

			return $this->encode([
				'status' => 'ok',
				'data' => [
					'session' => $session->toArray()
				]
			]);
		}
		catch(Throwable $exception) {
			return $this->encode([
				'status' => 'error',
				'message' => $exception->getMessage()
			]);
		}
	}

	public function getHelp(): string {
		return 'Creates a short-lived realtime speech-to-text browser session for one chatbot instance.';
	}

	private function getConfiguredServiceId(): string {
		$group = trim((string)$this->request->request('config_group', ''));
		$name = trim((string)$this->request->request('config_name', ''));
		if($group === '' || $name === '') {
			throw new RuntimeException('Missing chatbot configuration identity.');
		}

		$settings = $this->settingsStore->get($group, $name, []);
		$serviceId = $this->normalizeTechnicalKey((string)($settings['speech_to_text_service'] ?? ''));
		if($serviceId === '' || $serviceId === self::DISABLED_SERVICE) {
			throw new RuntimeException('Speech-to-text is disabled for this chatbot.');
		}

		return $serviceId;
	}


	private function normalizeContext(string $context): string {
		$context = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $context) ?? '';
		$context = trim($context);
		if(strlen($context) > 4000) {
			$context = substr($context, -4000);
		}

		return $context;
	}

	private function normalizeTechnicalKey(string $value): string {
		$value = strtolower(trim($value));
		return preg_replace('/[^a-z0-9._-]+/', '', $value) ?? '';
	}

	/** @param array<string,mixed> $payload */
	private function encode(array $payload): string {
		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return is_string($json) ? $json : '{"status":"error","message":"Response encoding failed."}';
	}
}
