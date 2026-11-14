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

use Base3\AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use Base3\AssistantFoundation\Dto\RealtimeSpeechToTextSession;
use Base3\AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use PHPUnit\Framework\TestCase;

final class RealtimeSpeechToTextSessionOutputTest extends TestCase {

	public function testOutputUsesServiceFromChatbotConfiguration(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(
			static fn(string $key, mixed $default = null): mixed => match($key) {
				'config_group' => 'chatbot-two',
				'config_name' => 'sidebar',
				'language' => 'de-DE',
				default => $default
			}
		);
		$settingsStore = $this->createMock(ISettingsStore::class);
		$settingsStore
			->expects($this->once())
			->method('get')
			->with('chatbot-two', 'sidebar', [])
			->willReturn([
				'speech_to_text_service' => 'mistral-default'
			]);
		$service = new RealtimeSpeechToTextSessionOutputTestDouble1();
		$output = new RealtimeSpeechToTextSessionOutput($request, $settingsStore, $service);

		$data = json_decode($output->getOutput('json'), true);

		$this->assertSame('ok', $data['status'] ?? null);
		$this->assertSame('mistral', $data['data']['session']['provider'] ?? null);
		$this->assertSame('rt_test', $data['data']['session']['clientToken'] ?? null);
		$this->assertSame('mistral-default', $service->request?->getServiceId());
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
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn([]);
		$service = $this->createStub(IRealtimeSpeechToTextSessionService::class);
		$output = new RealtimeSpeechToTextSessionOutput($request, $settingsStore, $service);

		$data = json_decode($output->getOutput('json'), true);

		$this->assertSame('error', $data['status'] ?? null);
		$this->assertSame('Speech-to-text is disabled for this chatbot.', $data['message'] ?? null);
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
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn([
			'speech_to_text_service' => 'off'
		]);
		$service = $this->createMock(IRealtimeSpeechToTextSessionService::class);
		$service->expects($this->never())->method('createSession');
		$output = new RealtimeSpeechToTextSessionOutput($request, $settingsStore, $service);

		$data = json_decode($output->getOutput('json'), true);

		$this->assertSame('error', $data['status'] ?? null);
		$this->assertSame('Speech-to-text is disabled for this chatbot.', $data['message'] ?? null);
	}

}

require_once __DIR__ . '/RealtimeSpeechToTextSessionOutputTestDouble1.php';
