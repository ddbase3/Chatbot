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

namespace Base3\Chatbot\Output;

use Base3\CoreFoundation\Api\IOutput;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\Chatbot\Api\IChatbotTurnRequestStore;
use Base3\Chatbot\Dto\ChatbotTurnRequest;
use Base3\Chatbot\Dto\PendingChatbotTurn;
use Base3\Chatbot\Service\ChatbotServiceRegistry;
use Throwable;

/**
 * Accepts a large chatbot payload through POST and returns a single-use SSE URL.
 */
final class ChatbotTurnPrepareOutput implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IChatbotTurnRequestStore $turnStore,
		private readonly ChatbotServiceRegistry $serviceRegistry,
		private readonly ILinkTargetService $linkTargetService
	) {}

	public static function getName(): string {
		return 'chatbotturnprepare';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		try {
			$data = $this->request->getJsonBody();
			if (!is_array($data)) {
				return $this->encodeError('Invalid JSON payload.');
			}

			$serviceId = $this->normalizeServiceId((string)($data['service'] ?? ''));
			$payload = $data['payload'] ?? null;
			if ($serviceId === '' || !is_array($payload)) {
				return $this->encodeError('Missing chatbot service or payload.');
			}
			if (!$this->serviceRegistry->has($serviceId)) {
				return $this->encodeError('Unknown chatbot service: ' . $serviceId);
			}

			$turn = new ChatbotTurnRequest($payload);
			if (!$turn->hasPromptOrResume()) {
				return $this->encodeError('Chatbot turn requires a prompt or resume payload.');
			}

			$id = $this->turnStore->store(new PendingChatbotTurn($serviceId, $turn));
			$streamUrl = $this->linkTargetService->getLink(
				['name' => ChatbotTurnStreamOutput::getName()],
				['id' => $id]
			);

			return $this->encode([
				'ok' => true,
				'id' => $id,
				'stream' => $streamUrl
			]);
		}
		catch (Throwable $exception) {
			return $this->encodeError($exception->getMessage());
		}
	}

	public function getHelp(): string {
		return 'Stores one chatbot turn and returns its single-use SSE stream URL.';
	}

	/** @param array<string,mixed> $payload */
	private function encode(array $payload): string {
		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return is_string($json) ? $json : '{"ok":false,"error":"Response encoding failed."}';
	}

	private function encodeError(string $message): string {
		return $this->encode([
			'ok' => false,
			'error' => $message
		]);
	}

	private function normalizeServiceId(string $serviceId): string {
		$serviceId = strtolower(trim($serviceId));

		return preg_replace('/[^a-z0-9._-]+/', '', $serviceId) ?? '';
	}
}
