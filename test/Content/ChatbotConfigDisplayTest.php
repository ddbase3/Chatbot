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

	public function testDefaultSettingsUseCanonicalConversationFields(): void {
		$defaults = $this->createDisplay()->getTestDefaultSettings();

		$this->assertSame([], $defaults['main_headings'] ?? null);
		$this->assertSame('none', $defaults['first_message_mode'] ?? null);
		$this->assertSame([], $defaults['first_messages'] ?? null);
		$this->assertFalse($defaults['chat_history_enabled'] ?? true);
		$this->assertFalse($defaults['automatic_chat_titles'] ?? true);
		$this->assertSame('responsive', $defaults['chat_history_panel_mode'] ?? null);
		$this->assertNotSame('', trim((string)($defaults['ai_notice_text'] ?? '')));
		$this->assertArrayNotHasKey('base_prompts', $defaults);
		$this->assertArrayNotHasKey('use_threads', $defaults);
	}

	public function testNormalizationDoesNotPreserveRemovedUiFields(): void {
		$settings = $this->createDisplay()->normalizeTestSettings([
			'chatbot_backend' => 'runtime:missionbay',
			'base_prompts' => ['Legacy'],
			'use_threads' => true,
			'start_mode' => 'fixed',
			'start_messages' => ['Legacy start'],
			'main_headings' => ['Headline'],
			'first_message_mode' => 'random',
			'first_messages' => ['Hello'],
			'ai_notice_text' => 'AI can make mistakes.'
		]);

		$this->assertSame(['Headline'], $settings['main_headings'] ?? null);
		$this->assertSame('random', $settings['first_message_mode'] ?? null);
		$this->assertSame(['Hello'], $settings['first_messages'] ?? null);
		$this->assertArrayNotHasKey('start_mode', $settings);
		$this->assertArrayNotHasKey('start_messages', $settings);
		$this->assertArrayNotHasKey('base_prompts', $settings);
		$this->assertArrayNotHasKey('use_threads', $settings);
	}

	public function testRemovedFixedFirstMessageModeNormalizesToUserStartsChatAndPreservesMessages(): void {
		$settings = $this->createDisplay()->normalizeTestSettings([
			'chatbot_backend' => 'runtime:missionbay',
			'first_message_mode' => 'fixed',
			'first_messages' => ['Legacy fixed message'],
			'ai_notice_text' => 'AI can make mistakes.'
		]);

		$this->assertSame('none', $settings['first_message_mode'] ?? null);
		$this->assertSame(['Legacy fixed message'], $settings['first_messages'] ?? null);
	}

	public function testNormalizationPreservesPreparedFirstMessagesForInactiveModes(): void {
		$display = $this->createDisplay();

		$userStarts = $display->normalizeTestSettings([
			'first_message_mode' => 'none',
			'first_messages' => ['Prepared greeting']
		]);
		$contextual = $display->normalizeTestSettings([
			'first_message_mode' => 'contextual_ai',
			'first_messages' => ['Prepared greeting']
		]);

		$this->assertSame(['Prepared greeting'], $userStarts['first_messages'] ?? null);
		$this->assertSame(['Prepared greeting'], $contextual['first_messages'] ?? null);
	}

	public function testLoadsExistingInstanceSettingsIncludingSpeechServices(): void {
		$settingsStore = $this->createMock(ISettingsStore::class);
		$settingsStore
			->expects($this->once())
			->method('get')
			->with('uihk-chatbot', 'default', $this->isType('array'))
			->willReturn([
				'chatbot_backend' => 'service:dummychatbotservice',
				'speech_to_text_service' => 'mistral-default',
				'text_to_speech_service' => 'openai-default',
				'use_voice' => true
			]);

		$display = $this->createDisplay($settingsStore);
		$settings = $display->loadTestSettings([
			'group' => 'uihk-chatbot',
			'name' => 'default'
		]);

		$this->assertSame('service:dummychatbotservice', $settings['chatbot_backend'] ?? null);
		$this->assertSame('mistral-default', $settings['speech_to_text_service'] ?? null);
		$this->assertSame('openai-default', $settings['text_to_speech_service'] ?? null);
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

	/** @return array<string,mixed> */
	public function getTestDefaultSettings(): array {
		return $this->getDefaultSettings();
	}

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	public function normalizeTestSettings(array $settings): array {
		return $this->normalizeSettings($settings);
	}
}
