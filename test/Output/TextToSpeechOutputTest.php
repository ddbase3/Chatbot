<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\ITextToSpeechService;
use AssistantFoundation\Api\ITextToSpeechStream;
use AssistantFoundation\Dto\TextToSpeechRequest;
use AssistantFoundation\Dto\TextToSpeechResult;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Output\TextToSpeechOutput;
use PHPUnit\Framework\TestCase;

final class TextToSpeechOutputTest extends TestCase {

	public function testOutputResolvesServiceFromChatbotConfiguration(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('request')->willReturnCallback(
			static fn(string $key, mixed $default = null): mixed => match($key) {
				'config_group' => 'chatbot-two',
				'config_name' => 'sidebar',
				default => $default
			}
		);
		$request->method('getJsonBody')->willReturn([
			'text' => 'Hallo Welt',
			'language' => 'de-DE'
		]);
		$settingsStore = $this->createMock(ISettingsStore::class);
		$settingsStore
			->expects($this->once())
			->method('get')
			->with('chatbot-two', 'sidebar', [])
			->willReturn([
				'text_to_speech_service' => 'openai-default'
			]);
		$service = new class implements ITextToSpeechService {
			public ?TextToSpeechRequest $request = null;

			public function synthesize(
				TextToSpeechRequest $request,
				ITextToSpeechStream $stream
			): TextToSpeechResult {
				$this->request = $request;
				$stream->start('audio/mpeg', ['provider' => 'test']);
				$stream->write('audio-');
				$stream->write('data');

				return new TextToSpeechResult('audio/mpeg', ['provider' => 'test']);
			}
		};
		$output = new TextToSpeechOutput($request, $settingsStore, $service);

		$this->assertSame('audio-data', $output->getOutput('audio'));
		$this->assertSame('openai-default', $service->request?->getServiceId());
		$this->assertSame('Hallo Welt', $service->request?->getText());
		$this->assertSame('de-DE', $service->request?->getLanguage());
	}

	public function testOutputRejectsChatbotWithoutConfiguredService(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('request')->willReturnCallback(
			static fn(string $key, mixed $default = null): mixed => match($key) {
				'config_group' => 'chatbot-two',
				'config_name' => 'sidebar',
				default => $default
			}
		);
		$request->method('getJsonBody')->willReturn([
			'text' => 'Hallo Welt'
		]);
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn([]);
		$service = $this->createStub(ITextToSpeechService::class);
		$output = new TextToSpeechOutput($request, $settingsStore, $service);

		$data = json_decode($output->getOutput('audio'), true);

		$this->assertSame('error', $data['status'] ?? null);
		$this->assertSame('No text-to-speech service is configured for this chatbot.', $data['message'] ?? null);
	}
}
