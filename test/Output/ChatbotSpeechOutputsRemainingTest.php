<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Api\ITextToSpeechService;
use AssistantFoundation\Api\ITextToSpeechStream;
use AssistantFoundation\Dto\RealtimeSpeechToTextSession;
use AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use AssistantFoundation\Dto\TextToSpeechRequest;
use AssistantFoundation\Dto\TextToSpeechResult;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use Chatbot\Output\TextToSpeechOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotSpeechOutputsRemainingTest extends TestCase {

	public function testRealtimeSessionRequiresChatbotIdentityBeforeReadingSettings(): void {
		$settings = $this->createMock(ISettingsStore::class);
		$settings->expects($this->never())->method('get');
		$data = json_decode((new RealtimeSpeechToTextSessionOutput(
			$this->speechRequest(['config_group' => 'chatbot']),
			$settings,
			$this->createStub(IRealtimeSpeechToTextSessionService::class)
		))->getOutput(), true);
		$this->assertSame('error', $data['status']);
		$this->assertSame('Missing chatbot configuration identity.', $data['message']);
	}

	public function testRealtimeSessionNormalizesTechnicalServiceIdAndContextControlCharacters(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['speech_to_text_service' => ' Mistral RT!_v2 ']);
		$service = $this->createMock(IRealtimeSpeechToTextSessionService::class);
		$service->expects($this->once())->method('createSession')->willReturnCallback(function(RealtimeSpeechToTextSessionRequest $request): RealtimeSpeechToTextSession {
			$this->assertSame('mistralrt_v2', $request->getServiceId());
			$this->assertSame('de-DE', $request->getLanguage());
			$this->assertSame(['context' => 'A   B'], $request->getOptions());
			return $this->realtimeSession();
		});
		$output = new RealtimeSpeechToTextSessionOutput(
			$this->speechRequest(['config_group' => ' chatbot ', 'config_name' => ' sidebar ', 'language' => ' de-DE ', 'context' => " A \x01 B "]),
			$settings, $service
		);
		$data = json_decode($output->getOutput(), true);
		$this->assertSame('ok', $data['status']);
		$this->assertSame('test-token', $data['data']['session']['clientToken']);
	}

	public function testRealtimeSessionContextKeepsLastFourThousandBytes(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['speech_to_text_service' => 'speech']);
		$service = $this->createStub(IRealtimeSpeechToTextSessionService::class);
		$service->method('createSession')->willReturnCallback(function(RealtimeSpeechToTextSessionRequest $request): RealtimeSpeechToTextSession {
			$this->assertSame(['context' => str_repeat('b', 4000)], $request->getOptions());
			return $this->realtimeSession();
		});
		$output = new RealtimeSpeechToTextSessionOutput($this->speechRequest([
			'config_group' => 'chatbot', 'config_name' => 'sidebar', 'context' => str_repeat('a', 25) . str_repeat('b', 4010)
		]), $settings, $service);
		$this->assertSame('ok', json_decode($output->getOutput(), true)['status']);
	}

	public function testRealtimeSessionRuntimeFailureReturnsControlledError(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['speech_to_text_service' => 'rt']);
		$service = $this->createStub(IRealtimeSpeechToTextSessionService::class);
		$service->method('createSession')->willReturnCallback(static function(): never { throw new RuntimeException('Credential rejected'); });
		$output = new RealtimeSpeechToTextSessionOutput($this->speechRequest(['config_group' => 'chatbot', 'config_name' => 'sidebar']), $settings, $service);
		$this->assertSame(['status' => 'error', 'message' => 'Credential rejected'], json_decode($output->getOutput(), true));
	}

	public function testTextToSpeechMissingIdentityNeverCallsProvider(): void {
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->never())->method('stream');
		$data = json_decode((new TextToSpeechOutput(
			$this->speechRequest(['config_group' => 'chatbot'], ['text' => 'hello']),
			$this->createStub(ISettingsStore::class), $service
		))->getOutput(), true);
		$this->assertSame('error', $data['status']);
		$this->assertSame('Missing chatbot configuration identity.', $data['message']);
	}

	public function testTextToSpeechRejectsWhitespaceOnlyTextBeforeStartingStream(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['text_to_speech_service' => 'voice']);
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->never())->method('stream');
		$data = json_decode((new TextToSpeechOutput(
			$this->speechRequest(['config_group' => 'chatbot', 'config_name' => 'sidebar'], ['text' => " \n  "]),
			$settings, $service
		))->getOutput(), true);
		$this->assertSame('Missing text-to-speech input.', $data['message']);
	}

	public function testTextToSpeechStreamsBinaryAndPassesNormalizedArguments(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['text_to_speech_service' => ' Voice-Provider! ']);
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->once())->method('stream')->willReturnCallback(function(TextToSpeechRequest $request, ITextToSpeechStream $stream): TextToSpeechResult {
			$this->assertSame('voice-provider', $request->getServiceId());
			$this->assertSame('Hello world', $request->getText());
			$this->assertSame('en-US', $request->getLanguage());
			$this->assertSame(['voice' => 'calm'], $request->getOptions());
			$stream->start('audio/mpeg');
			$stream->write('binary-audio');
			return new TextToSpeechResult('audio/mpeg');
		});
		$output = new TextToSpeechOutput($this->speechRequest([
			'config_group' => 'chatbot', 'config_name' => 'sidebar'
		], [
			'text' => ' Hello world ', 'language' => ' en-US ', 'options' => ['voice' => 'calm']
		]), $settings, $service);
		$this->assertSame('binary-audio', $output->getOutput());
	}

	public function testTextToSpeechIgnoresNonArrayOptions(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['text_to_speech_service' => 'voice']);
		$service = $this->createStub(ITextToSpeechService::class);
		$service->method('stream')->willReturnCallback(function(TextToSpeechRequest $request, ITextToSpeechStream $stream): TextToSpeechResult {
			$this->assertSame([], $request->getOptions());
			$stream->start('audio/mpeg');
			$stream->write('sound');
			return new TextToSpeechResult('audio/mpeg');
		});
		$output = new TextToSpeechOutput($this->speechRequest([
			'config_group' => 'chatbot', 'config_name' => 'sidebar'
		], ['text' => 'Text', 'options' => 'invalid']), $settings, $service);
		$this->assertSame('sound', $output->getOutput());
	}

	public function testTextToSpeechProviderExceptionReturnsErrorWithoutPartialAudio(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturn(['text_to_speech_service' => 'voice']);
		$service = $this->createStub(ITextToSpeechService::class);
		$service->method('stream')->willReturnCallback(static function(): never { throw new RuntimeException('Audio backend unavailable'); });
		$output = new TextToSpeechOutput($this->speechRequest(['config_group' => 'chatbot', 'config_name' => 'sidebar'], ['text' => 'Text']), $settings, $service);
		$this->assertSame(['status' => 'error', 'message' => 'Audio backend unavailable'], json_decode($output->getOutput(), true));
	}

	private function speechRequest(array $values, array $json = []): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);
		$request->method('getJsonBody')->willReturn($json);
		return $request;
	}

	private function realtimeSession(): RealtimeSpeechToTextSession {
		return new RealtimeSpeechToTextSession('test', 'webrtc', 'https://example.test', 'test-token', '2026-11-04', 'model', 'pcm', 16000);
	}
}
