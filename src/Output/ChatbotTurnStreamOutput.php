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

use AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SseAgentEventSink;
use Throwable;

/**
 * Claims one prepared chatbot turn and executes it directly over SSE.
 */
final class ChatbotTurnStreamOutput implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IChatbotTurnRequestStore $turnStore,
		private readonly ChatbotServiceRegistry $serviceRegistry,
		private readonly ChatbotTurnResponder $turnResponder
	) {}

	public static function getName(): string {
		return 'chatbotturnstream';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$id = trim((string)($this->request->get('id') ?? ''));
		$turn = null;

		try {
			$turn = $this->turnStore->claim($id);
		}
		catch (Throwable $exception) {
			return $this->emitBootstrapError($exception->getMessage());
		}

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		if ($turn === null) {
			return $this->emitBootstrapError('Invalid or expired chatbot turn id.');
		}

		try {
			$service = $this->serviceRegistry->get($turn->getServiceId());
		}
		catch (Throwable $exception) {
			return $this->emitBootstrapError($exception->getMessage());
		}

		return $this->turnResponder->respondSse($service, $turn->getRequest());
	}

	public function getHelp(): string {
		return 'Executes a prepared single-use chatbot turn through Server-Sent Events.';
	}

	private function emitBootstrapError(string $message): string {
		$sink = new SseAgentEventSink();
		$sink->start();
		$sink->emit(new AgentExecutionEvent('error', [
			'message' => $message,
			'user_message' => 'Der Chat-Stream konnte nicht gestartet werden.'
		]));
		$sink->emit(new AgentExecutionEvent('done', ['status' => 'error']));

		return '';
	}
}
