<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IClassMap;
use Base3\Language\Api\ILanguage;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Content\ChatbotDisplay;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\StaticChatbotAppearanceProvider;
use PHPUnit\Framework\TestCase;

final class ChatbotDisplayEdgeCasesTest extends TestCase {

	public function testDisabledVoiceHidesSpeechEndpointsEvenWhenProvidersAreConfigured(): void {
		$config = $this->render([
			'config_group' => 'chatbot', 'config_name' => 'sidebar',
			'use_voice' => false, 'use_dialog' => true,
			'speech_to_text_service' => 'provider-stt', 'text_to_speech_service' => 'provider-tts'
		]);
		$this->assertFalse($config['speech_to_text_enabled']);
		$this->assertFalse($config['text_to_speech_enabled']);
		$this->assertFalse($config['use_dialog']);
		$this->assertSame('', $config['speech_to_text_session_url']);
		$this->assertSame('', $config['text_to_speech_url']);
	}

	public function testSpeechEndpointsRequireBothConfigurationIdentifiers(): void {
		$config = $this->render([
			'config_group' => 'chatbot', 'config_name' => '',
			'speech_to_text_service' => 'speech', 'text_to_speech_service' => 'voice'
		]);
		$this->assertTrue($config['speech_to_text_enabled']);
		$this->assertTrue($config['text_to_speech_enabled']);
		$this->assertSame('', $config['speech_to_text_session_url']);
		$this->assertSame('', $config['text_to_speech_url']);
	}

	public function testInvalidBackendUsesConfiguredAgentRuntimeInstead(): void {
		$config = $this->render([
			'chatbot_backend' => 'not-a-backend', 'agent_runtime' => ' MissionBay! '
		]);
		$this->assertSame('runtime:missionbay', $config['chatbot_backend']);
		$this->assertSame('chatbotservice', $config['service']);
	}

	public function testMissingDefaultRuntimeUsesExistingDummyBackend(): void {
		$config = $this->render([], [], '');
		$this->assertSame('service:dummychatbotservice', $config['chatbot_backend']);
		$this->assertSame('dummychatbotservice', $config['service']);
		$this->assertSame('/service/dummychatbotservice', $config['service_url']);
	}

	public function testBackendPrefixAndIdentifierAreCanonicalized(): void {
		$config = $this->render(['chatbot_backend' => '  SERVICE:DummyChatbotService  ']);
		$this->assertSame('service:dummychatbotservice', $config['chatbot_backend']);
		$this->assertSame('dummychatbotservice', $config['service']);
	}

	public function testUnsupportedModesRevertToTheirDocumentedDefaults(): void {
		$config = $this->render([
			'chat_history_panel_mode' => 'sideways', 'first_message_mode' => 'fixed',
			'ai_notice_position' => 'hidden', 'transport_mode' => 'unknown', 'reference_mode' => 'external'
		]);
		$this->assertSame('responsive', $config['chat_history_panel_mode']);
		$this->assertSame('none', $config['first_message_mode']);
		$this->assertSame('above_composer', $config['ai_notice_position']);
		$this->assertSame('auto', $config['transport_mode']);
		$this->assertSame('url', $config['reference_mode']);
	}

	public function testClientReferenceRejectsNonArrayValues(): void {
		$config = $this->render(['reference' => '{"injected":true}', 'reference_provider' => ' course-provider ']);
		$this->assertSame([], $config['reference']);
		$this->assertSame('course-provider', $config['reference_provider']);
	}

	public function testConversationCapabilityDoesNotRequireHistoryPanel(): void {
		$config = $this->render([
			'config_group' => 'chatbot', 'config_name' => 'sidebar',
			'memory_profile' => 'memory', 'chat_history_enabled' => false,
			'automatic_chat_titles' => true
		]);
		$this->assertTrue($config['conversation_enabled']);
		$this->assertFalse($config['chat_history_enabled']);
		$this->assertTrue($config['automatic_chat_titles']);
		$this->assertStringContainsString('chatbotconversationstate', $config['conversation_state_url']);
		$this->assertStringContainsString('chatbotconversationtitle', $config['conversation_title_url']);
	}

	public function testConversationEndpointsNeedIdentityEvenWithMemoryConfigured(): void {
		$config = $this->render(['memory_profile' => 'memory', 'chat_history_enabled' => true]);
		$this->assertFalse($config['conversation_enabled']);
		$this->assertFalse($config['chat_history_enabled']);
		$this->assertFalse($config['automatic_chat_titles']);
		$this->assertSame('', $config['conversation_state_url']);
	}

	public function testStoredConfigurationWinsOverLocallyProvidedWidgetFields(): void {
		$config = $this->render([
			'config_group' => 'chatbot', 'config_name' => 'sidebar',
			'chatbot_backend' => 'service:dummychatbotservice', 'transport_mode' => 'rest'
		], [
			'chatbot_backend' => 'runtime:missionbay', 'transport_mode' => 'sse', 'use_icons' => false
		]);
		$this->assertSame('runtime:missionbay', $config['chatbot_backend']);
		$this->assertSame('sse', $config['transport_mode']);
		$this->assertFalse($config['use_icons']);
	}

	public function testWhitespaceNoticeUsesTranslatedFallback(): void {
		$config = $this->render(['ai_notice_text' => "  \n  "], [], 'missionbay', 'de-DE');
		$this->assertNotEmpty($config['ai_notice_text']);
		$this->assertStringContainsString('KI', $config['ai_notice_text']);
	}

	public function testTechnicalSpeechProviderIdsAreCleanedBeforeUrlBuilding(): void {
		$config = $this->render([
			'config_group' => 'chatbot', 'config_name' => 'sidebar',
			'speech_to_text_service' => ' Speech Provider! ',
			'text_to_speech_service' => ' Voice.Provider! '
		]);
		$this->assertSame('speechprovider', $config['speech_to_text_service']);
		$this->assertSame('voice.provider', $config['text_to_speech_service']);
		$this->assertSame('/service/realtimespeechtotextsession?config_group=chatbot&config_name=sidebar', $config['speech_to_text_session_url']);
		$this->assertSame('/service/texttospeech?config_group=chatbot&config_name=sidebar', $config['text_to_speech_url']);
	}

	/** @param array<string,mixed> $values @param array<string,mixed> $stored */
	private function render(array $values = [], array $stored = [], string $runtimeId = 'missionbay', string $languageId = 'en'): array {
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(array $target, array $params = []): string {
			$url = '/service/' . (string)($target['name'] ?? '');
			return $params === [] ? $url : $url . '?' . http_build_query($params);
		});
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($stored);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn($runtimeId);
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn($languageId);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		$ui = new FakeChatbotDisplay();
		$display = new ChatbotDisplay(
			$ui,
			$links,
			new ChatbotSettingsService($store, $selector),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($map), $store),
			$selector,
			$language,
			new StaticChatbotAppearanceProvider()
		);
		$display->setData($values);
		$this->assertSame('FAKE_CHATBOT_OUTPUT', $display->getOutput('html'));
		return $ui->getData();
	}
}

require_once __DIR__ . '/FakeChatbotDisplay.php';
