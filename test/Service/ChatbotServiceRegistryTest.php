<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Api\IClassMap;
use Chatbot\Service\ChatbotServiceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotServiceRegistryTest extends TestCase {

	public function testRegisteredServiceIsResolvedByCanonicalTechnicalId(): void {
		$service = new ChatbotServiceRegistryTestDouble();
		$map = $this->createMock(IClassMap::class);
		$map->expects($this->once())->method('getInstancesByInterface')->willReturn(['invalid', $service]);
		$registry = new ChatbotServiceRegistry($map);
		$this->assertSame('chatbotserviceregistry', ChatbotServiceRegistry::getName());
		$this->assertTrue($registry->has(' ChatbotServiceRegistryTestDouble '));
		$this->assertTrue($registry->has('chatbotserviceregistrytestdouble'));
		$this->assertSame($service, $registry->get('ChatbotServiceRegistryTestDouble'));
		$this->assertFalse($registry->has('missing'));
	}

	public function testInvalidOrMissingServiceNeverFallsBackToAnother(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceRegistryTestDouble()]);
		$registry = new ChatbotServiceRegistry($map);
		$this->assertFalse($registry->has('!!!'));
		foreach (['!!!', '', 'other'] as $id) {
			try {
				$registry->get($id);
				$this->fail('Unknown service should not be resolved.');
			} catch (RuntimeException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testDuplicateServicesRejectAmbiguousRegistration(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceRegistryTestDouble(), new ChatbotServiceRegistryTestDouble()]);
		$this->expectException(RuntimeException::class);
		(new ChatbotServiceRegistry($map))->has('chatbotserviceregistrytestdouble');
	}
}
