<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Api\ITextToSpeechService;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Chatbot\Output\ChatbotTurnCancelOutput;
use Chatbot\Output\ChatbotTurnPrepareOutput;
use Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use Chatbot\Output\TextToSpeechOutput;
use Chatbot\Service\ChatbotTurnCancellationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class ChatbotTransportOutputPrivateContractsTest extends TestCase {

	public function testPrepareNormalizesCaseAndRemovesUnsupportedServiceCharacters(): void {
		$this->assertSame('provider.v2_1-test', $this->invoke($this->blank(ChatbotTurnPrepareOutput::class), 'normalizeServiceId', ' Provider.V2_1 - Test!? '));
	}

	public function testPrepareRejectsServiceNameWithOnlyUnsupportedCharacters(): void {
		$this->assertSame('', $this->invoke($this->blank(ChatbotTurnPrepareOutput::class), 'normalizeServiceId', ' /// !!! '));
	}

	public function testPrepareErrorBodyIsStructuredAndJsonSafe(): void {
		$output = $this->blank(ChatbotTurnPrepareOutput::class);
		$this->assertSame(['ok' => false, 'error' => 'Unknown "service"'], json_decode($this->invoke($output, 'encodeError', 'Unknown "service"'), true));
	}

	public function testPrepareEncoderRetainsUnicodeAndHttpUrls(): void {
		$json = $this->invoke($this->blank(ChatbotTurnPrepareOutput::class), 'encode', ['name' => 'Gruesse', 'url' => 'https://example.test/a/b']);
		$this->assertSame(['name' => 'Gruesse', 'url' => 'https://example.test/a/b'], json_decode($json, true));
		$this->assertStringContainsString('https://example.test/a/b', $json);
	}

	public function testPrepareEncoderSubstitutesInvalidUtf8(): void {
		$json = $this->invoke($this->blank(ChatbotTurnPrepareOutput::class), 'encode', ['text' => "a\xC3\x28"]);
		$this->assertSame('a' . "\u{FFFD}" . '(', json_decode($json, true)['text']);
	}

	public function testPrepareEncoderHandlesCircularPayloadWithStableError(): void {
		$recursive = [];
		$recursive['self'] = &$recursive;
		$this->assertSame(['ok' => false, 'error' => 'Response encoding failed.'], json_decode($this->invoke($this->blank(ChatbotTurnPrepareOutput::class), 'encode', $recursive), true));
	}

	public function testCancelUsesJsonTurnIdBeforeRequestParameter(): void {
		$output = $this->cancelEndpoint(['turn_id' => ' json-turn '], 'browser-turn');
		$this->assertSame('json-turn', $this->invoke($output, 'readTurnId'));
	}

	public function testCancelFallsBackToRequestWhenJsonDoesNotHaveTurnId(): void {
		$output = $this->cancelEndpoint(['other' => 'value'], ' form-turn ');
		$this->assertSame('form-turn', $this->invoke($output, 'readTurnId'));
	}

	public function testCancelRejectsArrayInsteadOfCastingToString(): void {
		$output = $this->cancelEndpoint(['turn_id' => ['forged']], 'fallback');
		$this->assertSame('', $this->invoke($output, 'readTurnId'));
	}

	public function testCancelAcceptsNumericZeroAsScalarTurnId(): void {
		$this->assertSame('0', $this->invoke($this->cancelEndpoint(['turn_id' => 0], null), 'readTurnId'));
	}

	public function testCancelEncoderReturnsPredictableErrorForCircularResponse(): void {
		$recursive = [];
		$recursive['self'] = &$recursive;
		$this->assertSame(['ok' => false, 'error' => 'Response encoding failed.'], json_decode($this->invoke($this->cancelEndpoint([], null), 'encode', $recursive), true));
	}

	public function testCancelEncoderNeverEmitsInvalidUtf8(): void {
		$json = $this->invoke($this->cancelEndpoint([], null), 'encode', ['message' => "bad\xFF"]);
		$this->assertSame('bad' . "\u{FFFD}", json_decode($json, true)['message']);
	}

	public function testRealtimeContextDropsAsciiControlsButPreservesReadableText(): void {
		$this->assertSame("hello   world\nsecond", $this->invoke($this->blank(RealtimeSpeechToTextSessionOutput::class), 'normalizeContext', " hello\x00\x01\x7Fworld\nsecond "));
	}

	public function testRealtimeContextKeepsOnlyLastFourThousandBytes(): void {
		$this->assertSame(str_repeat('b', 4000), $this->invoke($this->blank(RealtimeSpeechToTextSessionOutput::class), 'normalizeContext', str_repeat('a', 23) . str_repeat('b', 4001)));
	}

	public function testRealtimeInvalidUtf8ContextIsRejectedRatherThanPartiallyRendered(): void {
		$this->assertSame('', $this->invoke($this->blank(RealtimeSpeechToTextSessionOutput::class), 'normalizeContext', "secret\xFF"));
	}

	public function testRealtimeTechnicalServiceKeyIsStrictlySanitized(): void {
		$this->assertSame('voice_rt-1.0', $this->invoke($this->blank(RealtimeSpeechToTextSessionOutput::class), 'normalizeTechnicalKey', ' VOICE_RT-1.0!? '));
	}

	public function testRealtimeEncoderHandlesRecursiveResponse(): void {
		$recursive = [];
		$recursive['self'] = &$recursive;
		$this->assertSame(['status' => 'error', 'message' => 'Response encoding failed.'], json_decode($this->invoke($this->blank(RealtimeSpeechToTextSessionOutput::class), 'encode', $recursive), true));
	}

	public function testRealtimeEncoderRetainsUnicodeInSuccessfulResponse(): void {
		$this->assertSame(['status' => 'ok', 'language' => 'de'], json_decode($this->invoke($this->blank(RealtimeSpeechToTextSessionOutput::class), 'encode', ['status' => 'ok', 'language' => 'de']), true));
	}

	public function testRealtimeConfiguredServiceRejectsDisabledProvider(): void {
		$output = $this->realtimeEndpoint(['speech_to_text_service' => ' OFF ']);
		$this->expectException(RuntimeException::class);
		$this->invoke($output, 'getConfiguredServiceId');
	}

	public function testRealtimeConfiguredServiceTrimsIdentityAndNormalizesProvider(): void {
		$this->assertSame('provider-stt', $this->invoke($this->realtimeEndpoint(['speech_to_text_service' => ' PROVIDER-STT!? ']), 'getConfiguredServiceId'));
	}

	public function testTextToSpeechNormalizerDiscardsUntrustedKeyCharacters(): void {
		$this->assertSame('voice_service.v2', $this->invoke($this->blank(TextToSpeechOutput::class), 'normalizeTechnicalKey', ' Voice_Service.V2? '));
	}

	public function testTextToSpeechErrorResponseContainsExactMessage(): void {
		$json = $this->invoke($this->blank(TextToSpeechOutput::class), 'error', 'Audio provider failed: "unavailable"', 503, false);
		$this->assertSame(['status' => 'error', 'message' => 'Audio provider failed: "unavailable"'], json_decode($json, true));
	}

	public function testTextToSpeechErrorResponseSubstitutesMalformedUtf8(): void {
		$json = $this->invoke($this->blank(TextToSpeechOutput::class), 'error', "bad\xFF", 500, false);
		$this->assertSame('bad' . "\u{FFFD}", json_decode($json, true)['message']);
	}

	public function testTextToSpeechConfiguredServiceNormalizesProviderKey(): void {
		$this->assertSame('provider-tts', $this->invoke($this->ttsEndpoint(['text_to_speech_service' => ' PROVIDER-TTS!? ']), 'getConfiguredServiceId'));
	}

	public function testTextToSpeechConfiguredServiceRejectsDisabledProvider(): void {
		$this->expectException(RuntimeException::class);
		$this->invoke($this->ttsEndpoint(['text_to_speech_service' => 'off']), 'getConfiguredServiceId');
	}

	private function blank(string $type): object {
		return (new ReflectionClass($type))->newInstanceWithoutConstructor();
	}

	private function invoke(object $output, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod($output, $method))->invoke($output, ...$args);
	}

	private function cancelEndpoint(array $json, mixed $fallback): ChatbotTurnCancelOutput {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn($json);
		$request->method('request')->willReturn($fallback);
		return new ChatbotTurnCancelOutput($request, new ChatbotTurnCancellationService($this->createStub(IStateStore::class)));
	}

	private function realtimeEndpoint(array $stored): RealtimeSpeechToTextSessionOutput {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => match ($key) {
			'config_group' => ' chatbot ',
			'config_name' => ' sidebar ',
			default => $default
		});
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturnCallback(function(string $group, string $name) use ($stored): array {
			$this->assertSame('chatbot', $group);
			$this->assertSame('sidebar', $name);
			return $stored;
		});
		return new RealtimeSpeechToTextSessionOutput($request, $settings, $this->createStub(IRealtimeSpeechToTextSessionService::class));
	}

	private function ttsEndpoint(array $stored): TextToSpeechOutput {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => match ($key) {
			'config_group' => ' chatbot ',
			'config_name' => ' sidebar ',
			default => $default
		});
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturnCallback(function(string $group, string $name) use ($stored): array {
			$this->assertSame('chatbot', $group);
			$this->assertSame('sidebar', $name);
			return $stored;
		});
		return new TextToSpeechOutput($request, $settings, $this->createStub(ITextToSpeechService::class));
	}
}
