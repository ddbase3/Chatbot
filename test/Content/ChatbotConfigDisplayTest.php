<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentConfigFormService;
use AssistantFoundation\Api\IAgentRuntimeRegistry;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IClassMap;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Content\ChatbotConfigDisplay;
use PHPUnit\Framework\TestCase;

final class ChatbotConfigDisplayTest extends TestCase {

	public function testContextUsesConfiguredSettingsStoreIdentity(): void {
		$display = $this->createDisplay();
		$display->setData([
			'group' => 'uihk-chatbot',
			'name' => 'default'
		]);

		$context = $display->getTestContext();

		$this->assertSame('uihk-chatbot', $context['group'] ?? null);
		$this->assertSame('default', $context['name'] ?? null);
		$this->assertSame('base3_chatbot_config_' . md5('uihk-chatbot/default'), $context['form_id'] ?? null);
	}

	public function testContextUsesExplicitDefaultIdentity(): void {
		$display = $this->createDisplay();

		$context = $display->getTestContext();

		$this->assertSame('chatbot', $context['group'] ?? null);
		$this->assertSame('default', $context['name'] ?? null);
	}

	public function testLoadsExistingInstanceSettingsIncludingSpeechServices(): void {
		$settingsStore = $this->createMock(ISettingsStore::class);
		$settingsStore
			->expects($this->once())
			->method('get')
			->with('uihk-chatbot', 'default', $this->isType('array'))
			->willReturn([
				'chatbot_backend' => 'service:dummychatbotservice',
				'speech_to_text_service' => 'mistral-realtime',
				'text_to_speech_service' => 'openai-default',
				'use_mathjax' => true,
				'use_voice' => true
			]);

		$display = $this->createDisplay($settingsStore);
		$settings = $display->loadTestSettings([
			'group' => 'uihk-chatbot',
			'name' => 'default'
		]);

		$this->assertSame('service:dummychatbotservice', $settings['chatbot_backend'] ?? null);
		$this->assertSame('mistral-realtime', $settings['speech_to_text_service'] ?? null);
		$this->assertSame('openai-default', $settings['text_to_speech_service'] ?? null);
		$this->assertTrue($settings['use_mathjax'] ?? false);
		$this->assertTrue($settings['use_voice'] ?? false);
	}

	private function createDisplay(?ISettingsStore $settingsStore = null): TestableChatbotConfigDisplay {
		$linkTargetService = $this->createStub(ILinkTargetService::class);
		$linkTargetService->method('getLink')->willReturn('/service/chatbotconfigdisplay');

		$agentConfigFormService = $this->createStub(IAgentConfigFormService::class);
		$agentConfigFormService->method('getDefaultSettings')->willReturn([]);

		$agentRuntimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$agentRuntimeSelector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$agentRuntimeSelector->method('selectRuntimeId')->willReturn('missionbay');

		return new TestableChatbotConfigDisplay(
			$this->createStub(IMvcView::class),
			$this->createStub(IRequest::class),
			$settingsStore ?? $this->createStub(ISettingsStore::class),
			$linkTargetService,
			$this->createStub(IClassMap::class),
			$agentConfigFormService,
			$this->createStub(IAgentRuntimeRegistry::class),
			$agentRuntimeSelector
		);
	}
}

final class TestableChatbotConfigDisplay extends ChatbotConfigDisplay {

	/** @return array<string,mixed> */
	public function getTestContext(): array {
		return $this->getContext(false);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function loadTestSettings(array $context): array {
		return $this->loadSettings($context);
	}
}
