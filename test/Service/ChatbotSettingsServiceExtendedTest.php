<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotSettingsService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChatbotSettingsServiceExtendedTest extends TestCase {

	public function testGetTrimsIdentityBeforeReadingSettings(): void {
		$settings = $this->createMock(ISettingsStore::class);
		$settings->expects($this->once())->method('get')->with('chatbot', 'example', ['default' => true])->willReturn(['enabled' => true]);
		$this->assertSame(['enabled' => true], $this->service($settings)->get(' chatbot ', ' example ', ['default' => true]));
	}

	public function testMissingIdentityReturnsPassedDefaultWithoutAccessingStore(): void {
		$settings = $this->createMock(ISettingsStore::class);
		$settings->expects($this->never())->method('get');
		$this->assertSame(['fallback' => 'yes'], $this->service($settings)->get(' ', 'example', ['fallback' => 'yes']));
		$this->assertSame([], $this->service($settings)->get('chatbot', ' '));
	}

	public function testRequireRejectsEmptyConfigurationIdentity(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->require('chatbot', '');
	}

	public function testRequireReturnsOnlyExistingSettings(): void {
		$settings = $this->createMock(ISettingsStore::class);
		$settings->expects($this->once())->method('has')->with('chatbot', 'example')->willReturn(true);
		$settings->expects($this->once())->method('get')->with('chatbot', 'example', [])->willReturn(['memory_profile' => 'memory']);
		$this->assertSame(['memory_profile' => 'memory'], $this->service($settings)->require(' chatbot ', ' example '));
	}

	public function testExplicitAgentRuntimeTakesPrecedenceOverBackendId(): void {
		$service = $this->service();
		$this->assertSame('explicit-1', $service->getRuntimeId(['agent_runtime' => ' Explicit ! -1 ', 'chatbot_backend' => 'runtime:backup']));
		$this->assertSame('missionbay', $service->getRuntimeId(['chatbot_backend' => ' RUNTIME:MISSIONBAY ']));
		$this->assertSame('', $service->getRuntimeId(['chatbot_backend' => 'service:dummy']));
		$this->assertSame('', $service->getRuntimeId([]));
	}

	public function testRuntimeSelectorIsOnlyUsedWithoutConfiguredRuntime(): void {
		$selector = $this->createMock(IAgentRuntimeSelector::class);
		$selector->expects($this->once())->method('selectRuntimeId')->with(['chatbot_backend' => 'service:dummy'])->willReturn('default-runtime');
		$service = new ChatbotSettingsService($this->createStub(ISettingsStore::class), $selector);
		$this->assertSame('known', $service->selectRuntimeId(['agent_runtime' => 'Known']));
		$this->assertSame('default-runtime', $service->selectRuntimeId(['chatbot_backend' => 'service:dummy']));
	}

	public function testAgentConfigurationCopiesOriginalSettingsAndAddsRuntimeIfAvailable(): void {
		$settings = ['chatbot_backend' => 'runtime:missionbay', 'other' => ['value' => 1]];
		$this->assertSame($settings + ['agent_runtime' => 'missionbay'], $this->service()->getAgentConfiguration($settings));
		$this->assertSame(['other' => 1], $this->service()->getAgentConfiguration(['other' => 1]));
	}

	public function testMemoryProfileRequiresNonEmptyNormalizedTechnicalId(): void {
		$service = $this->service();
		$this->assertFalse($service->hasConversationMemory(['memory_profile' => '!!!']));
		$this->assertFalse($service->hasConversationMemory([]));
		$this->assertTrue($service->hasConversationMemory(['memory_profile' => ' Database-Memory ']));
	}

	private function service(?ISettingsStore $settings = null): ChatbotSettingsService {
		return new ChatbotSettingsService($settings ?? $this->createStub(ISettingsStore::class), $this->createStub(IAgentRuntimeSelector::class));
	}
}
