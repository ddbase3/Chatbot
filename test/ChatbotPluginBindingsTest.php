<?php declare(strict_types=1);

namespace Chatbot\Test;

use Base3\Api\IContainer;
use Chatbot\Api\IChatbotAppearanceProvider;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\ChatbotPlugin;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use Chatbot\Service\StaticChatbotAppearanceProvider;
use Closure;
use PHPUnit\Framework\TestCase;

final class ChatbotPluginBindingsTest extends TestCase {

	public function testAllRegistrationsUseSharedServicesAndPreserveHostOverrides(): void {
		$entries = $this->captureRegistrations();
		$this->assertCount(15, $entries);
		$this->assertSame(IContainer::SHARED, $entries[ChatbotPlugin::getName()]['flags']);
		foreach ($entries as $name => $entry) {
			if ($name === ChatbotPlugin::getName()) {
				continue;
			}
			$this->assertSame(IContainer::SHARED | IContainer::NOOVERWRITE, $entry['flags']);
		}
	}

	public function testTurnStoreInterfaceUsesExistingSessionStoreInstance(): void {
		$entries = $this->captureRegistrations();
		$store = $this->createStub(IChatbotTurnRequestStore::class);
		$container = $this->createMock(IContainer::class);
		$container->expects($this->once())->method('get')->with(SessionChatbotTurnRequestStore::class)->willReturn($store);
		$this->assertInstanceOf(Closure::class, $entries[IChatbotTurnRequestStore::class]['value']);
		$this->assertSame($store, $entries[IChatbotTurnRequestStore::class]['value']($container));
	}

	public function testAppearanceDefaultIsStaticProviderWithoutHostDependencies(): void {
		$entries = $this->captureRegistrations();
		$this->assertInstanceOf(Closure::class, $entries[IChatbotAppearanceProvider::class]['value']);
		$appearance = $entries[IChatbotAppearanceProvider::class]['value']();
		$this->assertInstanceOf(StaticChatbotAppearanceProvider::class, $appearance);
		$this->assertSame('', $appearance->getStylesheet());
	}

	public function testExtensionAndServiceRegistryHaveSeparateDiscoveryContracts(): void {
		$entries = $this->captureRegistrations();
		$this->assertArrayHasKey(ChatbotServiceRegistry::class, $entries);
		$this->assertArrayHasKey(ChatbotExtensionRegistry::class, $entries);
		$this->assertArrayHasKey(ChatbotExtensionService::class, $entries);
		$this->assertArrayHasKey(ChatbotTurnCancellationService::class, $entries);
		$this->assertInstanceOf(Closure::class, $entries[ChatbotExtensionService::class]['value']);
	}

	private function captureRegistrations(): array {
		$entries = [];
		$container = $this->createMock(IContainer::class);
		$container->expects($this->exactly(15))->method('set')->willReturnCallback(function(string $name, mixed $definition, int $flags) use (&$entries, $container): IContainer {
			$entries[$name] = ['value' => $definition, 'flags' => $flags];
			return $container;
		});
		(new ChatbotPlugin($container))->init();
		return $entries;
	}
}
