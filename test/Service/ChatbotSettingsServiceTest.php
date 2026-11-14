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

use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;

final class ChatbotSettingsServiceTest extends TestCase {

	public function testRuntimeBackendAndMemoryProfileEnableConversationMemory(): void {
		$store = $this->createStub(ISettingsStore::class);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$service = new ChatbotSettingsService($store, $selector);
		$settings = [
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'database-memory'
		];

		$this->assertSame('missionbay', $service->getRuntimeId($settings));
		$this->assertTrue($service->hasConversationMemory($settings));
		$this->assertSame('missionbay', $service->getAgentConfiguration($settings)['agent_runtime'] ?? null);
	}

	public function testMemoryProfileControlsConversationCapabilityIndependentlyFromBackend(): void {
		$service = new ChatbotSettingsService(
			$this->createStub(ISettingsStore::class),
			$this->createStub(IAgentRuntimeSelector::class)
		);

		$this->assertFalse($service->hasConversationMemory([
			'chatbot_backend' => 'runtime:missionbay'
		]));
		$this->assertTrue($service->hasConversationMemory([
			'chatbot_backend' => 'service:dummychatbotservice',
			'memory_profile' => 'database-memory'
		]));
	}

	public function testRequireRejectsUnknownConfiguration(): void {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('has')->willReturn(false);
		$service = new ChatbotSettingsService(
			$store,
			$this->createStub(IAgentRuntimeSelector::class)
		);

		$this->expectException(\RuntimeException::class);
		$service->require('chatbot', 'missing');
	}
}
