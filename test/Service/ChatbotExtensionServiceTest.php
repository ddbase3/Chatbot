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

namespace Base3\Chatbot\Test\Service;

use Base3\AssistantFoundation\Api\IAssistantResponseExtension;
use Base3\AssistantFoundation\Api\IAssistantResponseExtensionExamples;
use Base3\AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use PHPUnit\Framework\TestCase;

final class ChatbotExtensionServiceTest extends TestCase {

	public function testExtensionIsDisabledByDefault(): void {
		$service = $this->createService([], $this->createStub(ISettingsStore::class));

		$this->assertFalse($service->getStates()[0]['enabled'] ?? true);
		$this->assertSame(['Show a test response.'], $service->getStates()[0]['example_prompts'] ?? []);
		$this->assertSame('Base prompt', $service->composeSystemPrompt('Base prompt'));
		$this->assertSame([], $service->getClientConfiguration()['plugins']);
	}

	public function testEnabledExtensionContributesPromptAndClientPlugin(): void {
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn([
			'enabled' => ['test-extension' => true]
		]);
		$service = $this->createService([], $settingsStore);

		$this->assertSame(
			"Base prompt\n\nExtension prompt.",
			$service->composeSystemPrompt('Base prompt')
		);
		$this->assertSame([
			[
				'name' => 'test-renderer',
				'module_url' => '/test-renderer.js',
				'export_name' => 'TestRendererPlugin',
				'options' => ['mode' => 'stable']
			]
		], $service->getClientConfiguration()['plugins']);
		$this->assertSame(
			['markdown' => ['preserveTest' => true]],
			$service->getClientConfiguration()['plugin_options']
		);
	}

	public function testSaveStoresExplicitStateForEveryDiscoveredExtension(): void {
		$settingsStore = $this->createMock(ISettingsStore::class);
		$settingsStore->expects($this->once())
			->method('set')
			->with('chatbot-extensions', 'default', [
				'enabled' => ['test-extension' => true]
			]);
		$settingsStore->expects($this->once())->method('save');
		$service = $this->createService([], $settingsStore);

		$service->saveEnabled(['test-extension']);
	}

	public function testRegistryRejectsDuplicateTechnicalIds(): void {
		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstancesByInterface')->willReturn([
			new TestAssistantResponseExtension(),
			new TestAssistantResponseExtension()
		]);

		$this->expectException(\RuntimeException::class);
		(new ChatbotExtensionRegistry($classMap))->all();
	}

	/** @param array<int,IAssistantResponseExtension> $extensions */
	private function createService(array $extensions, ISettingsStore $settingsStore): ChatbotExtensionService {
		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstancesByInterface')->willReturn(
			$extensions !== [] ? $extensions : [new TestAssistantResponseExtension()]
		);

		return new ChatbotExtensionService(
			new ChatbotExtensionRegistry($classMap),
			$settingsStore
		);
	}
}

require_once __DIR__ . '/TestAssistantResponseExtension.php';
