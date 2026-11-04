<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentConfigFormService;
use AssistantFoundation\Api\IAgentRuntimeRegistry;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IAssetResolver;
use Base3\Api\IClassMap;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConfigNormalizationEdgeTest extends TestCase {

	public function testBackendNormalizationRejectsMalformedServiceAndUsesRuntimeDefault(): void {
		$config = $this->display()->normalizeTestSettings(['chatbot_backend' => 'service:bad name']);
		$this->assertSame('service:badname', $config['chatbot_backend']);
		$config = $this->display()->normalizeTestSettings(['chatbot_backend' => 'bogus:other']);
		$this->assertSame('runtime:missionbay', $config['chatbot_backend']);
	}

	public function testBooleanFieldsAcceptOnlyDeclaredTrueValues(): void {
		$config = $this->display()->normalizeTestSettings([
			'use_markdown' => 'on', 'use_voice' => 'off', 'use_icons' => '1',
			'use_dialog' => 0, 'automatic_chat_titles' => 'true', 'chat_history_enabled' => 'no'
		]);
		$this->assertTrue($config['use_markdown']);
		$this->assertFalse($config['use_voice']);
		$this->assertTrue($config['use_icons']);
		$this->assertFalse($config['use_dialog']);
		$this->assertTrue($config['automatic_chat_titles']);
		$this->assertFalse($config['chat_history_enabled']);
	}

	public function testInvalidEnumsNormalizeIndependently(): void {
		$config = $this->display()->normalizeTestSettings([
			'chat_history_panel_mode' => 'other', 'first_message_mode' => 'fixed',
			'ai_notice_position' => 'hidden', 'transport_mode' => 'socket', 'reference_mode' => 'unknown'
		]);
		$this->assertSame('responsive', $config['chat_history_panel_mode']);
		$this->assertSame('none', $config['first_message_mode']);
		$this->assertSame('above_composer', $config['ai_notice_position']);
		$this->assertSame('auto', $config['transport_mode']);
		$this->assertSame('url', $config['reference_mode']);
	}

	public function testJsonEncodedListsAreParsedAndNormalized(): void {
		$config = $this->display()->normalizeTestSettings([
			'first_messages' => '[" One ","Two", "", "Three"]',
			'main_headings' => '[" Topic ", "Section"]'
		]);
		$this->assertSame(['One', 'Two', 'Three'], $config['first_messages']);
		$this->assertSame(['Topic', 'Section'], $config['main_headings']);
	}

	public function testMessageListsIgnoreComplexItemsAndPreserveMultilineContent(): void {
		$config = $this->display()->normalizeTestSettings([
			'first_messages' => [[], new \stdClass(), "  First\r\nSecond  ", 42, false, ' ']
		]);
		$this->assertSame(["First\nSecond", '42'], $config['first_messages']);
	}

	public function testMalformedJsonListIsPreservedAsPlainTextInsteadOfDropped(): void {
		$config = $this->display()->normalizeTestSettings(['main_headings' => '[invalid JSON']);
		$this->assertSame(['[invalid JSON'], $config['main_headings']);
	}

	public function testReferenceAndSpeechProviderValuesRemainStructuredAndNormalized(): void {
		$config = $this->display()->normalizeTestSettings([
			'reference' => ['course' => 17], 'reference_provider' => '  provider-one  ',
			'default_lang' => ' de-DE ', 'speech_to_text_service' => ' SPEECH!_1 ',
			'text_to_speech_service' => ' VOICE Provider '
		]);
		$this->assertSame(['course' => 17], $config['reference']);
		$this->assertSame('provider-one', $config['reference_provider']);
		$this->assertSame('de-DE', $config['default_lang']);
		$this->assertSame('speech_1', $config['speech_to_text_service']);
		$this->assertSame('voiceprovider', $config['text_to_speech_service']);
	}

	public function testEmptyNoticeUsesCanonicalDefault(): void {
		$config = $this->display()->normalizeTestSettings(['ai_notice_text' => " \r\n \r "]);
		$this->assertNotEmpty($config['ai_notice_text']);
		$this->assertStringContainsString('AI-generated responses', $config['ai_notice_text']);
	}

	public function testContextHonorsEmbeddedModeAndExplicitRenderingOptions(): void {
		$display = $this->display();
		$display->setData([
			'group' => '  tenant-one ', 'name' => '  sidebar ',
			'mode' => 'embedded', 'save_mode' => 'post', 'title' => ' Widget settings ',
			'render_form' => false, 'save_url' => '/custom/save'
		]);
		$context = $display->getTestContext();
		$this->assertSame('tenant-one', $context['group']);
		$this->assertSame('sidebar', $context['name']);
		$this->assertSame('embedded', $context['mode']);
		$this->assertSame('post', $context['save_mode']);
		$this->assertFalse($context['render_form']);
		$this->assertSame('Widget settings', $context['title']);
		$this->assertSame('/custom/save', $context['save_url']);
		$this->assertSame('base3_chatbot_config_' . md5('tenant-one/sidebar'), $context['form_id']);
	}

	public function testInvalidContextModesRevertToStandaloneAjax(): void {
		$display = $this->display();
		$display->setData(['mode' => 'unknown', 'save_mode' => 'invalid', 'render_form' => 'no']);
		$context = $display->getTestContext();
		$this->assertSame('standalone', $context['mode']);
		$this->assertSame('ajax', $context['save_mode']);
		$this->assertFalse($context['render_form']);
	}

	public function testEnabledResourceConfigurationBoundsSizeAndResolvesAssets(): void {
		$display = $this->display();
		$display->setData(['resources' => [
			'enabled' => true, 'endpoints' => ['list' => '/resource/list'],
			'max_file_size' => -100, 'accept' => ' .pdf,.txt '
		]]);
		$config = $display->getTestResourcesConfig();
		$this->assertSame(1, $config['max_file_size']);
		$this->assertSame('.pdf,.txt', $config['accept']);
		$this->assertSame('/assets/plugin/ClientStack/assets/filemanager/index.js', $config['module_url']);
		$this->assertSame('/assets/plugin/ClientStack/assets/filemanager/styles/filemanager.css', $config['css_url']);
	}

	public function testSettingsStorageReadFailureReturnsDefaultsWithoutThrowing(): void {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturnCallback(static function(): never { throw new RuntimeException('Filesystem unavailable'); });
		$config = $this->display($store)->loadTestSettings(['group' => 'chatbot', 'name' => 'default']);
		$this->assertSame('runtime:missionbay', $config['chatbot_backend']);
		$this->assertSame('none', $config['first_message_mode']);
		$this->assertFalse($config['chat_history_enabled']);
	}

	private function display(?ISettingsStore $store = null): TestableChatbotConfigDisplay {
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/config/save');
		$asset = $this->createStub(IAssetResolver::class);
		$asset->method('resolve')->willReturnCallback(static fn(string $path): string => '/assets/' . $path);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$selector->method('selectRuntimeId')->willReturn('missionbay');
		$forms = $this->createStub(IAgentConfigFormService::class);
		$forms->method('getDefaultSettings')->willReturn([]);
		$forms->method('normalizeSettings')->willReturn([]);
		return new TestableChatbotConfigDisplay(
			$this->createStub(IMvcView::class), $asset, $this->createStub(IRequest::class),
			$store ?? $this->createStub(ISettingsStore::class), $links,
			$this->createStub(IClassMap::class), $forms,
			$this->createStub(IAgentRuntimeRegistry::class), $selector,
			$this->createStub(IAuthorizationService::class)
		);
	}
}

require_once __DIR__ . '/TestableChatbotConfigDisplay.php';
