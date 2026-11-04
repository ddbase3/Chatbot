<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotSettingsServiceStoreShapeTest extends TestCase {

	public function testDefaultSettingsArePassedUnchangedToTheOwningStore(): void {
		$default = ['chatbot_backend' => 'service:dummychatbotservice', 'voice_enabled' => false];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('get')->with('chatbot', 'example', $default)->willReturn($default);
		$this->assertSame($default, $this->service($store)->get(' chatbot ', ' example ', $default));
	}

	public function testNestedStoreSettingsAreNotSilentlyRewrittenOnRead(): void {
		$settings = ['extensions' => ['enabled' => ['response']], 'agent_flow' => ['nodes' => [['id' => 'assistant']]]];
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($settings);
		$this->assertSame($settings, $this->service($store)->get('chatbot', 'example'));
	}

	public function testRequireExistingEmptyConfigurationDoesNotInventSettings(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('has')->with('chatbot', 'instance')->willReturn(true);
		$store->expects($this->once())->method('get')->with('chatbot', 'instance', [])->willReturn([]);
		$this->assertSame([], $this->service($store)->require(' chatbot ', ' instance '));
	}

	public function testMissingRequiredConfigurationNeverFallsBackToDefaultRuntimeSettings(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('has')->with('chatbot', 'missing')->willReturn(false);
		$store->expects($this->never())->method('get');
		try {
			$this->service($store)->require('chatbot', 'missing');
			$this->fail('Missing configuration was treated as an available default.');
		} catch (RuntimeException $exception) {
			$this->assertStringContainsString('chatbot/missing', $exception->getMessage());
		}
	}

	public function testMalformedExplicitRuntimeFallsBackOnlyToValidConfiguredRuntime(): void {
		$settings = [
			'agent_runtime' => '?!',
			'chatbot_backend' => ' RuNtImE:MissionBay_2! '
		];
		$this->assertSame('missionbay_2', $this->service()->getRuntimeId($settings));
		$this->assertSame('missionbay_2', $this->service()->getAgentConfiguration($settings)['agent_runtime']);
	}

	public function testSelectorIsOnlyConsultedIfBothExplicitAndBackendRuntimeAreInvalid(): void {
		$selector = $this->createMock(IAgentRuntimeSelector::class);
		$selector->expects($this->once())->method('selectRuntimeId')
			->with(['agent_runtime' => '???', 'chatbot_backend' => 'service:dummychatbotservice'])
			->willReturn('selected-runtime');
		$service = new ChatbotSettingsService($this->createStub(ISettingsStore::class), $selector);
		$this->assertSame('selected-runtime', $service->selectRuntimeId([
			'agent_runtime' => '???',
			'chatbot_backend' => 'service:dummychatbotservice'
		]));
	}

	private function service(?ISettingsStore $store = null): ChatbotSettingsService {
		return new ChatbotSettingsService(
			$store ?? $this->createStub(ISettingsStore::class),
			$this->createStub(IAgentRuntimeSelector::class)
		);
	}
}
