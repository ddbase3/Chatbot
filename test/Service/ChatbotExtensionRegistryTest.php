<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAssistantResponseExtension;
use Base3\Api\IClassMap;
use Chatbot\Service\ChatbotExtensionRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotExtensionRegistryTest extends TestCase {

	public function testDiscoveryNormalizesIdsAndOrdersByPriorityThenId(): void {
		$first = $this->extension('zeta', 10);
		$second = $this->extension('beta', 1);
		$third = $this->extension('alpha', 10);
		$map = $this->createMock(IClassMap::class);
		$map->expects($this->once())->method('getInstancesByInterface')->with(IAssistantResponseExtension::class)->willReturn([$first, $second, $third]);
		$registry = new ChatbotExtensionRegistry($map);
		$this->assertSame('chatbotextensionregistry', ChatbotExtensionRegistry::getName());
		$this->assertSame(['beta', 'alpha', 'zeta'], array_keys($registry->all()));
		$this->assertSame($first, $registry->all()['zeta']);
	}

	public function testEmptyDiscoveryReturnsEmptyArray(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		$this->assertSame([], (new ChatbotExtensionRegistry($map))->all());
	}

	public function testInvalidDiscoveredInstanceIsRejected(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn(['invalid']);
		$this->expectException(RuntimeException::class);
		(new ChatbotExtensionRegistry($map))->all();
	}

	public function testInvalidExtensionTechnicalIdsAreRejected(): void {
		foreach (['', ' a b ', 'Uppercase', 'bad/slash'] as $id) {
			$map = $this->createStub(IClassMap::class);
			$map->method('getInstancesByInterface')->willReturn([$this->extension($id, 1)]);
			try {
				(new ChatbotExtensionRegistry($map))->all();
				$this->fail('Invalid extension ID accepted.');
			} catch (RuntimeException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testDuplicateNormalizedIdsAreRejected(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([$this->extension('same', 1), $this->extension(' same ', 2)]);
		$this->expectException(RuntimeException::class);
		(new ChatbotExtensionRegistry($map))->all();
	}

	private function extension(string $id, int $priority): IAssistantResponseExtension {
		$extension = $this->createStub(IAssistantResponseExtension::class);
		$extension->method('id')->willReturn($id);
		$extension->method('getPriority')->willReturn($priority);
		return $extension;
	}
}
