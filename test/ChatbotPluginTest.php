<?php declare(strict_types=1);

namespace Chatbot\Test;

use PHPUnit\Framework\TestCase;
use Chatbot\ChatbotPlugin;
use Base3\Api\IContainer;

class ChatbotPluginTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('chatbotplugin', ChatbotPlugin::getName());
	}

	public function testInitRegistersPluginInContainerAsShared(): void {
		$container = new FakeContainer();
		$plugin = new ChatbotPlugin($container);

		$plugin->init();

		$this->assertTrue($container->has(ChatbotPlugin::getName()));
		$this->assertSame(IContainer::SHARED, $container->getLastFlags());
		$this->assertSame($plugin, $container->get(ChatbotPlugin::getName()));
	}

}

class FakeContainer implements IContainer {

	private array $items = [];
	private ?int $lastFlags = null;

	public function getServiceList(): array {
		return array_keys($this->items);
	}

	public function set(string $name, $classDefinition, $flags = 0): IContainer {
		$this->items[$name] = $classDefinition;
		$this->lastFlags = (int)$flags;
		return $this;
	}

	public function remove(string $name) {
		unset($this->items[$name]);
	}

	public function has(string $name): bool {
		return array_key_exists($name, $this->items);
	}

	public function get(string $name) {
		return $this->items[$name] ?? null;
	}

	public function getLastFlags(): ?int {
		return $this->lastFlags;
	}

}
