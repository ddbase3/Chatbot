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

use Base3\CoreFoundation\Api\IClassMap;
use Base3\Chatbot\Service\ChatbotServiceRegistry;
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
