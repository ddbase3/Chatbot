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

namespace Base3\Chatbot\Test\Output;

use Base3\AssistantFoundation\Api\ITextToSpeechService;
use Base3\AssistantFoundation\Api\ITextToSpeechStream;
use Base3\AssistantFoundation\Dto\TextToSpeechRequest;
use Base3\AssistantFoundation\Dto\TextToSpeechResult;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Output\TextToSpeechOutput;
use Base3\MediaFoundation\Model\AudioMedia;
use PHPUnit\Framework\TestCase;

final class TextToSpeechOutputTest extends TestCase {

	public function testOutputStreamsThroughServiceFromChatbotConfiguration(): void {
		$request = $this->createStub(IRequest::class);
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
		$service = new TextToSpeechOutputTestDouble1();
		$output = new TextToSpeechOutput($request, $settingsStore, $service);

		$this->assertSame('audio-data', $output->getOutput('audio'));
		$this->assertSame(1, $service->streamCalls);
		$this->assertSame('openai-default', $service->request?->getServiceId());
		$this->assertSame('Hallo Welt', $service->request?->getText());
		$this->assertSame('de-DE', $service->request?->getLanguage());
	}

	public function testOutputRejectsChatbotWithoutConfiguredService(): void {
		$request = $this->createStub(IRequest::class);
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
		$this->assertSame('Text-to-speech is disabled for this chatbot.', $data['message'] ?? null);
	}

	public function testOutputRejectsExplicitlyDisabledService(): void {
		$request = $this->createStub(IRequest::class);
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
		$settingsStore->method('get')->willReturn([
			'text_to_speech_service' => 'off'
		]);
		$service = $this->createMock(ITextToSpeechService::class);
		$service->expects($this->never())->method('stream');
		$output = new TextToSpeechOutput($request, $settingsStore, $service);

		$data = json_decode($output->getOutput('audio'), true);

		$this->assertSame('error', $data['status'] ?? null);
		$this->assertSame('Text-to-speech is disabled for this chatbot.', $data['message'] ?? null);
	}

}

require_once __DIR__ . '/TextToSpeechOutputTestDouble1.php';
