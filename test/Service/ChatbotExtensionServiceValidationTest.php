<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAssistantResponseExtension;
use AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\Api\IClassMap;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotExtensionServiceValidationTest extends TestCase {

	public function testStoredBooleanValuesOverrideExtensionDefaults(): void {
		$extensions = [
			$this->extension('first', 10, true),
			$this->extension('second', 20, false)
		];
		foreach ([
			['enabled' => ['first' => 'off', 'second' => 'YES']],
			['enabled' => ['first' => 0, 'second' => 1]],
			['enabled' => ['first' => false, 'second' => true]]
		] as $settings) {
			$states = $this->newExtensionService($extensions, $settings)->getStates();
			$this->assertSame(['first', 'second'], array_column($states, 'id'));
			$this->assertSame([false, true], array_column($states, 'enabled'));
		}
	}

	public function testUnconfiguredEnabledMapKeepsCodeOwnedDefaults(): void {
		$extensions = [$this->extension('first', 20, true), $this->extension('second', 10, false)];
		$states = $this->newExtensionService($extensions, ['enabled' => 'invalid'])->getStates();
		$this->assertSame(['second', 'first'], array_column($states, 'id'));
		$this->assertSame([false, true], array_column($states, 'enabled'));
	}

	public function testInvalidPersistedExtensionIdentifierIsRejected(): void {
		foreach (['Invalid Extension', ' ', 'Ümlaut'] as $invalidId) {
			try {
				$this->newExtensionService([$this->extension('valid', 10, true)], ['enabled' => [$invalidId => true]])->getStates();
				$this->fail('Invalid stored extension id was accepted.');
			}
			catch (RuntimeException $exception) {
				$this->assertStringContainsString('invalid id', $exception->getMessage());
			}
		}
	}

	public function testSaveEnabledRejectsUnknownIdsWithoutWriting(): void {
		foreach (['missing', 'Invalid ID', ''] as $invalidId) {
			$store = $this->createMock(ISettingsStore::class);
			$store->expects($this->never())->method('set');
			$store->expects($this->never())->method('save');
			try {
				$this->newExtensionService([$this->extension('known', 10, false)], [], $store)->saveEnabled([$invalidId]);
				$this->fail('Unknown extension id was accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('Unknown assistant response extension', $exception->getMessage());
			}
		}
	}

	public function testSaveEnabledDeduplicatesSelectionAndDisablesEverythingElse(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('set')->with('chatbot-extensions', 'default', [
			'enabled' => ['first' => true, 'second' => false]
		]);
		$store->expects($this->once())->method('save');
		$this->newExtensionService([
			$this->extension('first', 10, false), $this->extension('second', 20, true)
		], [], $store)->saveEnabled(['first', 'first']);
	}

	public function testClientConfigurationCombinesNamesAndRecursiveOptionsInPriorityOrder(): void {
		$first = $this->extension('first', 10, true, 'First block',
			new AssistantResponseClientPlugin('renderer-one', '/first.js', 'FirstRenderer'),
			['markdown' => ['safe' => true, 'mode' => 'first'], 'other' => ['id' => 1]]);
		$second = $this->extension('second', 20, true, 'Second block',
			new AssistantResponseClientPlugin('renderer-two', '/second.js', 'SecondRenderer'),
			['markdown' => ['mode' => 'second', 'extra' => true]]);
		$service = $this->newExtensionService([$second, $first]);
		$this->assertSame("Base\n\nFirst block\n\nSecond block", $service->composeSystemPrompt('Base  '));
		$config = $service->getClientConfiguration();
		$this->assertSame(['renderer-one', 'renderer-two'], array_column($config['plugins'], 'name'));
		$this->assertSame([
			'markdown' => ['safe' => true, 'mode' => 'second', 'extra' => true],
			'other' => ['id' => 1]
		], $config['plugin_options']);
	}

	public function testDisabledExtensionsCannotContributePromptsOrClientCode(): void {
		$extension = $this->extension('disabled', 10, true, 'Should not appear', new AssistantResponseClientPlugin('renderer', '/renderer.js', 'Renderer'));
		$service = $this->newExtensionService([$extension], ['enabled' => ['disabled' => 'no']]);
		$this->assertSame('Keep original  ', $service->composeSystemPrompt('Keep original  '));
		$this->assertSame(['plugins' => [], 'plugin_options' => []], $service->getClientConfiguration());
	}

	public function testClientPluginRequiresValidNameUrlAndExport(): void {
		foreach ([
			new AssistantResponseClientPlugin('Invalid Name', '/renderer.js', 'Renderer'),
			new AssistantResponseClientPlugin('renderer', '  ', 'Renderer'),
			new AssistantResponseClientPlugin('renderer', '/renderer.js', 'Invalid-Export'),
			new AssistantResponseClientPlugin('renderer', '/renderer.js', '1Invalid')
		] as $plugin) {
			try {
				$this->newExtensionService([$this->extension('first', 10, true, '', $plugin)])->getClientConfiguration();
				$this->fail('Invalid client plugin was accepted.');
			}
			catch (RuntimeException $exception) {
				$this->assertStringContainsString('client plugin', $exception->getMessage());
			}
		}
	}

	public function testDuplicateClientPluginNameIsNotSilentlyReplaced(): void {
		$service = $this->newExtensionService([
			$this->extension('first', 10, true, '', new AssistantResponseClientPlugin('same', '/a.js', 'A')),
			$this->extension('second', 20, true, '', new AssistantResponseClientPlugin('same', '/b.js', 'B'))
		]);
		$this->expectException(RuntimeException::class);
		$service->getClientConfiguration();
	}

	public function testEnabledExtensionWithoutClientPluginCanStillContributeOptions(): void {
		$service = $this->newExtensionService([
			$this->extension('options-only', 10, true, '', null, ['markdown' => ['source' => 'plugin']])
		]);
		$this->assertSame(['plugins' => [], 'plugin_options' => ['markdown' => ['source' => 'plugin']]], $service->getClientConfiguration());
	}

	private function extension(
		string $id, int $priority, bool $enabled,
		string $prompt = '', ?AssistantResponseClientPlugin $plugin = null, array $options = []
	): IAssistantResponseExtension {
		$extension = $this->createStub(IAssistantResponseExtension::class);
		$extension->method('id')->willReturn($id);
		$extension->method('getPriority')->willReturn($priority);
		$extension->method('isEnabledByDefault')->willReturn($enabled);
		$extension->method('getSystemPrompt')->willReturn($prompt);
		$extension->method('getClientPlugin')->willReturn($plugin);
		$extension->method('getClientPluginOptions')->willReturn($options);
		return $extension;
	}

	private function newExtensionService(array $extensions, array $settings = [], ?ISettingsStore $store = null): ChatbotExtensionService {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn($extensions);
		$store ??= $this->createStub(ISettingsStore::class);
		if ($settings !== []) {
			$store->method('get')->willReturn($settings);
		}
		return new ChatbotExtensionService(new ChatbotExtensionRegistry($map), $store);
	}
}
