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

namespace Base3\Chatbot\Test;

use Base3\CoreFoundation\Api\IContainer;
use Base3\Chatbot\Api\IChatbotAppearanceProvider;
use Base3\Chatbot\Api\IChatbotTurnRequestStore;
use Base3\Chatbot\ChatbotPlugin;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use Base3\Chatbot\Service\ChatbotServiceRegistry;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\SessionChatbotTurnRequestStore;
use Base3\Chatbot\Service\StaticChatbotAppearanceProvider;
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
