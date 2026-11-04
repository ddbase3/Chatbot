<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Api\IClassMap;
use Chatbot\Api\IChatbotService;
use Chatbot\Service\ChatbotServiceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotServiceRegistryNegativeDiscoveryTest extends TestCase {

	public function testEmptyServiceListIsCachedAndDoesNotInvokeDiscoveryRepeatedly(): void {
		$map = $this->createMock(IClassMap::class);
		$map->expects($this->once())->method('getInstancesByInterface')
			->with(IChatbotService::class)->willReturn([]);
		$registry = new ChatbotServiceRegistry($map);
		$this->assertFalse($registry->has('unregistered'));
		$this->assertFalse($registry->has('another'));
		try {
			$registry->get('unregistered');
			$this->fail('An unregistered service was resolved.');
		} catch (RuntimeException $exception) {
			$this->assertStringContainsString('unregistered', $exception->getMessage());
		}
	}

	public function testInvalidIdentifierIsRejectedBeforeScanningClassMap(): void {
		$map = $this->createMock(IClassMap::class);
		$map->expects($this->never())->method('getInstancesByInterface');
		$registry = new ChatbotServiceRegistry($map);
		$this->assertFalse($registry->has(' ! ? '));
		try {
			$registry->get(' ! ? ');
			$this->fail('Empty normalized service ID was accepted.');
		} catch (RuntimeException $exception) {
			$this->assertStringContainsString('[empty]', $exception->getMessage());
		}
	}

	public function testClassmapDiscoveryFailureIsPropagatedWithoutChoosingAnotherBackend(): void {
		$map = $this->createMock(IClassMap::class);
		$map->expects($this->once())->method('getInstancesByInterface')
			->with(IChatbotService::class)->willReturnCallback(static function(): never {
				throw new RuntimeException('Service discovery unavailable');
			});
		$registry = new ChatbotServiceRegistry($map);
		try {
			$registry->get('dummychatbotservice');
			$this->fail('Classmap failure was hidden.');
		} catch (RuntimeException $exception) {
			$this->assertSame('Service discovery unavailable', $exception->getMessage());
		}
	}

	public function testDiscoveredServiceWithEmptyTechnicalNameIsRejected(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceWithoutNameFixture()]);
		try {
			(new ChatbotServiceRegistry($map))->has('anything');
			$this->fail('A service without a technical name was accepted.');
		} catch (RuntimeException $exception) {
			$this->assertSame('Chatbot service returned an empty technical name.', $exception->getMessage());
		}
	}

	public function testUnknownServiceErrorUsesCanonicalIdentifier(): void {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceRegistryTestDouble()]);
		$registry = new ChatbotServiceRegistry($map);
		try {
			$registry->get('  UNREGISTERED ! SERVICE  ');
			$this->fail('Unknown service resolved unexpectedly.');
		} catch (RuntimeException $exception) {
			$this->assertSame('Unknown chatbot service: unregisteredservice', $exception->getMessage());
		}
	}
}

require_once __DIR__ . '/ChatbotServiceWithoutNameFixture.php';

require_once __DIR__ . '/ChatbotServiceRegistryTestDouble.php';
