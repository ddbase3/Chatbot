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

namespace Chatbot\Service;

use AssistantFoundation\Dto\AgentExecutionEvent;
use AssistantRuntime\Service\CollectingAgentEventSink;
use Base3\Language\Api\ILanguage;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;
use Throwable;

/**
 * Shared REST and SSE response handling for every chatbot backend.
 */
final class ChatbotTurnResponder {

	public function __construct(
		private readonly ILanguage $language
	) {}

	public static function getName(): string {
		return 'chatbotturnresponder';
	}

	public function respondRest(
		IChatbotService $service,
		ChatbotTurnRequest $request,
		bool $final = false
	): string {
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=UTF-8');
		}

		try {
			$result = $service->executeTurn($request, new CollectingAgentEventSink());
		}
		catch (Throwable $exception) {
			$result = ChatbotTurnResult::error('[Chatbot runtime error] ' . $exception->getMessage());
		}

		$json = json_encode(
			$result->toArray(),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return is_string($json)
			? $json
			: '{"type":"error","text":"Chatbot response could not be encoded."}';
	}

	public function respondSse(IChatbotService $service, ChatbotTurnRequest $request): string {
		$sink = new SseAgentEventSink();
		$sink->start();

		try {
			$result = $service->executeTurn($request, $sink);
			$this->emitTerminalFallbacks($sink, $result);
		}
		catch (Throwable $exception) {
			$userMessage = $this->getUserMessage(
				'runtime_error',
				'A technical error occurred. The request could not be completed.'
			);
			if (!$sink->hasEmitted('token')) {
				$sink->emit(new AgentExecutionEvent('token', ['text' => $userMessage]));
			}
			if (!$sink->hasEmitted('error')) {
				$sink->emit(new AgentExecutionEvent('error', [
					'message' => $exception->getMessage(),
					'user_message' => $userMessage,
					'type' => get_class($exception),
					'code' => $exception->getCode()
				]));
			}
			$sink->finish('error');
		}

		return '';
	}

	public function getUserMessage(string $key, string $fallback): string {
		$language = strtolower(str_replace('_', '-', trim($this->language->getLanguage())));
		$language = explode('-', $language)[0] ?? 'en';
		if (!in_array($language, ['ar', 'bg', 'de', 'en', 'es', 'fr', 'hi', 'it', 'pl', 'pt', 'ru', 'zh'], true)) {
			$language = 'en';
		}

		$basePath = defined('DIR_PLUGIN') ? DIR_PLUGIN . 'Chatbot/lang/Configuration/' : '';
		$files = $basePath === ''
			? []
			: array_values(array_unique([$basePath . $language . '.ini', $basePath . 'en.ini']));

		foreach ($files as $filename) {
			if (!is_file($filename) || !is_readable($filename)) {
				continue;
			}
			$data = parse_ini_file($filename, true);
			$section = is_array($data['chatbot_configuration'] ?? null) ? $data['chatbot_configuration'] : [];
			$value = $section[$key] ?? null;
			if (is_scalar($value) && trim((string)$value) !== '') {
				return trim((string)$value);
			}
		}

		return $fallback;
	}

	private function emitTerminalFallbacks(SseAgentEventSink $sink, ChatbotTurnResult $result): void {
		$payload = $result->toArray();

		if ($result->getType() === 'message') {
			if (!$sink->hasEmitted('msgid')) {
				$sink->emit(new AgentExecutionEvent('msgid', ['id' => $result->getId()]));
			}
			if (!$sink->hasEmitted('token') && $result->getText() !== '') {
				$sink->emit(new AgentExecutionEvent('token', ['text' => $result->getText()]));
			}
		}
		elseif ($result->getType() === 'interaction_required' && !$sink->hasEmitted('agent.interaction.required')) {
			$sink->emit(new AgentExecutionEvent('agent.interaction.required', $payload));
		}
		elseif ($result->getType() === 'error' && !$sink->hasEmitted('error')) {
			$sink->emit(new AgentExecutionEvent('error', [
				'message' => $result->getText(),
				'user_message' => $result->getText()
			]));
		}

		$sink->finish($this->resolveTerminalStatus($result, $payload));
	}

	/** @param array<string,mixed> $payload */
	private function resolveTerminalStatus(ChatbotTurnResult $result, array $payload): ?string {
		if ($result->getType() === 'error') {
			return 'error';
		}

		if ($result->getType() === 'interaction_required') {
			$status = trim((string)($payload['status'] ?? ''));
			return $status !== '' ? $status : 'interaction_required';
		}

		return null;
	}
}
