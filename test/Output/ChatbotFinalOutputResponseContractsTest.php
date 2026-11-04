<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Api\ITextToSpeechService;
use AssistantFoundation\Dto\RealtimeSpeechToTextSession;
use AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use Base3\Api\IClassMap;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Output\ChatbotTurnCancelOutput;
use Chatbot\Output\ChatbotTurnPrepareOutput;
use Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use Chatbot\Output\TextToSpeechOutput;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnCancellationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChatbotFinalOutputResponseContractsTest extends TestCase {

	public function testFinalPreparationRejectsMissingServiceWithoutCreatingTicket(): void {
		$turns = $this->createMock(IChatbotTurnRequestStore::class);
		$turns->expects($this->never())->method('store');
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects($this->never())->method('getLink');
		$request = $this->request([], []);
		$response = json_decode((new ChatbotTurnPrepareOutput(
			$request, $turns, new ChatbotServiceRegistry($this->createStub(IClassMap::class)), $links
		))->getOutput('json', true), true);
		$this->assertSame(['ok' => false, 'error' => 'Missing chatbot service or payload.'], $response);
	}

	public function testFinalCancellationRejectsMissingIdWithoutChangingState(): void {
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->never())->method('set');
		$state->expects($this->never())->method('flush');
		$response = json_decode((new ChatbotTurnCancelOutput(
			$this->request([], []), new ChatbotTurnCancellationService($state)
		))->getOutput('json', true), true);
		$this->assertSame(['ok' => false, 'error' => 'Missing chatbot turn id.'], $response);
	}

	public function testFinalRealtimeSessionRejectsMissingConfigurationIdentity(): void {
		$settings = $this->createMock(ISettingsStore::class);
		$settings->expects($this->never())->method('get');
		$service = $this->createMock(IRealtimeSpeechToTextSessionService::class);
		$service->expects($this->never())->method('createSession');
		$response = json_decode((new RealtimeSpeechToTextSessionOutput(
			$this->request([], []), $settings, $service
		))->getOutput('json', true), true);
		$this->assertSame(['status' => 'error', 'message' => 'Missing chatbot configuration identity.'], $response);
	}

	public function testFinalRealtimeSessionRejectsDisabledProviderWithoutCreatingSession(): void {
		$service = $this->createMock(IRealtimeSpeechToTextSessionService::class);
		$service->expects($this->never())->method('createSession');
		$response = json_decode((new RealtimeSpeechToTextSessionOutput(
			$this->request($this->identity(), []), $this->settings(['speech_to_text_service' => 'off']), $service
		))->getOutput('json', true), true);
		$this->assertSame(['status' => 'error', 'message' => 'Speech-to-text is disabled for this chatbot.'], $response);
	}

	public function testFinalRealtimeSessionReturnsOnlyConfiguredSessionPayload(): void {
		$service = $this->createMock(IRealtimeSpeechToTextSessionService::class);
		$service->expects($this->once())->method('createSession')->willReturnCallback(function(RealtimeSpeechToTextSessionRequest $request): RealtimeSpeechToTextSession {
			$this->assertSame('provider-rt', $request->getServiceId());
			$this->assertSame('de-DE', $request->getLanguage());
			$this->assertSame(['context' => 'Course 17'], $request->getOptions());
			return new RealtimeSpeechToTextSession('test', 'webrtc', 'https://example.test', 'one-time-token', '2026-11-04', 'model', 'pcm', 16000);
		});
		$response = json_decode((new RealtimeSpeechToTextSessionOutput(
			$this->request(array_replace($this->identity(), ['language' => ' de-DE ', 'context' => ' Course 17 ']), []),
			$this->settings(['speech_to_text_service' => ' Provider-RT!? ']),
			$service
		))->getOutput('json', true), true);
		$this->assertSame('ok', $response['status']);
		$this->assertSame('one-time-token', $response['data']['session']['clientToken']);
		$this->assertSame(['status', 'data'], array_keys($response));
	}

	public function testFinalTextToSpeechRejectsMissingTextWithClientErrorStatus(): void {
		http_response_code(200);
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->never())->method('stream');
		$response = json_decode((new TextToSpeechOutput(
			$this->request($this->identity(), ['text' => " \n "]),
			$this->settings(['text_to_speech_service' => 'voice']), $service
		))->getOutput('audio', true), true);
		$this->assertSame(['status' => 'error', 'message' => 'Missing text-to-speech input.'], $response);
		$this->assertSame(400, http_response_code());
	}

	public function testFinalTextToSpeechProviderFailureIsServerErrorBeforeStreamStarts(): void {
		http_response_code(200);
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->once())->method('stream')->willReturnCallback(static function(): never {
			throw new RuntimeException('Audio service unavailable');
		});
		$response = json_decode((new TextToSpeechOutput(
			$this->request($this->identity(), ['text' => 'Speak this']),
			$this->settings(['text_to_speech_service' => 'voice']), $service
		))->getOutput('audio', true), true);
		$this->assertSame(['status' => 'error', 'message' => 'Audio service unavailable'], $response);
		$this->assertSame(500, http_response_code());
	}

	public function testFinalTextToSpeechDisabledProviderNeverStartsStream(): void {
		http_response_code(200);
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->never())->method('stream');
		$response = json_decode((new TextToSpeechOutput(
			$this->request($this->identity(), ['text' => 'Speak this']),
			$this->settings(['text_to_speech_service' => 'off']), $service
		))->getOutput('audio', true), true);
		$this->assertSame('Text-to-speech is disabled for this chatbot.', $response['message']);
		$this->assertSame(500, http_response_code());
	}

	private function identity(): array {
		return ['config_group' => 'chatbot', 'config_name' => 'sidebar'];
	}

	private function request(array $values, array $json): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);
		$request->method('getJsonBody')->willReturn($json);
		return $request;
	}

	private function settings(array $values): ISettingsStore {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($values);
		return $store;
	}
}
