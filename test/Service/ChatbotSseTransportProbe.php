<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Dto\AgentExecutionEvent;
use Base3\Core\Request;
use Base3\Core\ServiceLocator;
use Base3\State\No\NoStateStore;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\PendingChatbotTurn;
use Chatbot\Output\ChatbotTurnStreamOutput;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use Chatbot\Service\SseAgentEventSink;
use RuntimeException;

/**
 * Executes real SSE transport in a CLI child so it cannot close PHPUnit buffers.
 */
final class ChatbotSseTransportProbe {

	public static function run(string $scenario): void {
		if (str_starts_with($scenario, 'sink_')) {
			self::runSink($scenario);
			return;
		}

		self::runStream($scenario);
	}

	private static function runSink(string $scenario): void {
		$sink = new SseAgentEventSink();

		switch ($scenario) {
			case 'sink_unicode':
				$sink->emit(new AgentExecutionEvent('token', ['text' => 'Grüße 🌍', 'url' => 'https://example.test/a/b']));
				$sink->finish();
				return;
			case 'sink_pending_done':
				$sink->emit(new AgentExecutionEvent('done', ['status' => 'suspended', 'resume_handle' => 'resume-1']));
				if ($sink->hasEmitted('done')) {
					throw new RuntimeException('The terminal runtime event was sent prematurely.');
				}
				$sink->emit(new AgentExecutionEvent('token', ['text' => 'before finish']));
				$sink->finish();
				return;
			case 'sink_override_status':
				$sink->emit(new AgentExecutionEvent('done', ['status' => 'completed', 'marker' => 7]));
				$sink->finish('error');
				return;
			case 'sink_empty_status':
				$sink->emit(new AgentExecutionEvent('done', ['status' => '  ', 'marker' => true]));
				$sink->finish();
				return;
			case 'sink_cancelled':
				$sink->finish('cancelled');
				return;
			case 'sink_duplicate_finish':
				$sink->emit(new AgentExecutionEvent('token', ['text' => 'first']));
				$sink->finish();
				$sink->finish('error');
				$sink->emit(new AgentExecutionEvent('token', ['text' => 'never sent']));
				return;
			case 'sink_multitoken':
				$sink->start();
				$sink->start();
				$sink->emit(new AgentExecutionEvent(' token ', ['text' => 'A']));
				$sink->emit(new AgentExecutionEvent('token', ['text' => 'B']));
				if (!$sink->hasEmitted('token') || $sink->hasEmitted('done')) {
					throw new RuntimeException('Incorrect event delivery state.');
				}
				$sink->finish();
				if (!$sink->hasEmitted('done')) {
					throw new RuntimeException('Terminal event was not delivered.');
				}
				return;
			case 'sink_invalid_utf8':
				$sink->emit(new AgentExecutionEvent('token', ['text' => "\xC3\x28"]));
				$sink->finish();
				return;
			case 'sink_last_done':
				$sink->emit(new AgentExecutionEvent('done', ['status' => 'running', 'marker' => 'first']));
				$sink->emit(new AgentExecutionEvent('done', ['status' => 'suspended', 'marker' => 'second']));
				$sink->finish();
				return;
		}

		throw new RuntimeException('Unknown SSE sink scenario: ' . $scenario);
	}

	private static function runStream(string $scenario): void {
		$store = new SessionChatbotTurnRequestStore();
		$registry = new ChatbotServiceRegistry(new ChatbotSseProbeClassMap(new ServiceLocator()));
		$responder = new ChatbotTurnResponder(
			new ChatbotSseProbeLanguage(),
			new ChatbotTurnCancellationService(new NoStateStore()),
			new ChatbotSseProbeLogger()
		);

		if ($scenario === 'stream_invalid') {
			$id = 'invalid-stream-ticket';
		}
		else {
			$serviceId = match ($scenario) {
				'stream_unknown' => 'unregistered-service',
				'stream_normalized' => ' CHATBOTSERVICEREGISTRYTESTDOUBLE ',
				'stream_default', 'stream_replay' => 'chatbotserviceregistrytestdouble',
				default => 'chatbotsseprobeservice'
			};
			$prompt = match ($scenario) {
				'stream_runtime_error' => 'throw',
				'stream_error_after_token' => 'token-then-throw',
				'stream_runtime_done' => 'runtime-done',
				'stream_interaction' => 'interaction',
				'stream_cancelled' => 'cancelled',
				'stream_error_result' => 'error-result',
				default => 'Hello stream'
			};
			$id = $store->store(new PendingChatbotTurn(
				$serviceId,
				new ChatbotTurnRequest(['prompt' => $prompt, 'config_group' => 'chatbot', 'config_name' => 'test'])
			));
		}

		$_GET['id'] = $id;
		$output = new ChatbotTurnStreamOutput(Request::fromGlobals(), $store, $registry, $responder);
		if ($output->getOutput() !== '') {
			throw new RuntimeException('An SSE endpoint returned body text instead of emitting events.');
		}
		if ($scenario === 'stream_replay' && $output->getOutput() !== '') {
			throw new RuntimeException('A claimed stream unexpectedly returned body text.');
		}
	}
}
