<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Api\IAssistantResponseExtension;
use AssistantFoundation\Api\IAssistantResponseExtensionExamples;
use AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\Api\IClassMap;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
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

final class TestAssistantResponseExtension implements IAssistantResponseExtension, IAssistantResponseExtensionExamples {

	public static function getName(): string {
		return 'testassistantresponseextension';
	}

	public function id(): string {
		return 'test-extension';
	}

	public function getLabel(): string {
		return 'Test extension';
	}

	public function getDescription(): string {
		return 'Test extension description.';
	}

	public function getPriority(): int {
		return 100;
	}

	public function isEnabledByDefault(): bool {
		return false;
	}

	public function getRequirements(): array {
		return [];
	}

	public function getExamplePrompts(): array {
		return ['Show a test response.'];
	}

	public function getSystemPrompt(array $context): string {
		return 'Extension prompt.';
	}

	public function getClientPlugin(array $context): ?AssistantResponseClientPlugin {
		return new AssistantResponseClientPlugin(
			'test-renderer',
			'/test-renderer.js',
			'TestRendererPlugin',
			['mode' => 'stable']
		);
	}

	public function getClientPluginOptions(array $context): array {
		return [
			'markdown' => [
				'preserveTest' => true
			]
		];
	}
}
