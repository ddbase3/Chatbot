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
use ReflectionMethod;
use RuntimeException;

final class ChatbotDisplayProtectedContractsTest extends TestCase {

	public function testStoredSettingsAreSkippedWhenIdentityIsIncomplete(): void {
		$settings = $this->createMock(ISettingsStore::class);
		$settings->expects($this->never())->method('get');
		$display = $this->display($settings);
		foreach ([[], ['config_group' => 'tenant'], ['config_name' => 'sidebar'], ['config_group' => '  ', 'config_name' => 'sidebar']] as $config) {
			$this->assertSame([], $this->invoke($display, 'loadStoredConfig', $config));
		}
	}

	public function testStoredSettingsUseTrimmedServerConfigurationIdentity(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturnCallback(function(string $group, string $name): array {
			$this->assertSame('chatbot', $group);
			$this->assertSame('sidebar', $name);
			return ['use_voice' => false, 'chatbot_backend' => 'service:dummychatbotservice'];
		});
		$this->assertSame(
			['use_voice' => false, 'chatbot_backend' => 'service:dummychatbotservice'],
			$this->invoke($this->display($settings), 'loadStoredConfig', ['config_group' => ' chatbot ', 'config_name' => ' sidebar '])
		);
	}

	public function testStorageFailureCannotLeakIntoClientRendering(): void {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('get')->willReturnCallback(static function(): never { throw new RuntimeException('settings unavailable'); });
		$this->assertSame([], $this->invoke($this->display($settings), 'loadStoredConfig', ['config_group' => 'chatbot', 'config_name' => 'sidebar']));
	}

	public function testServiceUrlNeverInventsMissingServiceOrConfigurationIdentity(): void {
		$display = $this->display();
		$this->assertSame('', $this->invoke($display, 'buildServiceUrl', ['service' => '   ']));
		$this->assertSame('/service/dummychatbotservice', $this->invoke($display, 'buildServiceUrl', ['service' => 'dummychatbotservice']));
		$this->assertSame('/service/dummychatbotservice?config_group=chatbot&config_name=sidebar', $this->invoke($display, 'buildServiceUrl', [
			'service' => ' dummychatbotservice ', 'config_group' => ' chatbot ', 'config_name' => ' sidebar '
		]));
	}

	public function testTurnEndpointsKeepDistinctTechnicalNames(): void {
		$display = $this->display();
		$this->assertSame('/service/chatbotturnprepare', $this->invoke($display, 'buildTurnPrepareUrl'));
		$this->assertSame('/service/chatbotturncancel', $this->invoke($display, 'buildTurnCancelUrl'));
	}

	public function testSpeechUrlsAreDisabledForMissingServiceOrDisabledFlag(): void {
		$display = $this->display();
		foreach (['buildSpeechToTextSessionUrl' => 'speech_to_text', 'buildTextToSpeechUrl' => 'text_to_speech'] as $method => $key) {
			foreach ([
				[], [$key . '_enabled' => true],
				[$key . '_enabled' => true, $key . '_service' => 'off'],
				[$key . '_enabled' => false, $key . '_service' => 'provider'],
				[$key . '_enabled' => true, $key . '_service' => 'provider', 'config_group' => 'chatbot']
			] as $config) {
				$this->assertSame('', $this->invoke($display, $method, $config));
			}
		}
	}

	public function testSpeechUrlsAreScopedToCompleteConfigurationIdentity(): void {
		$display = $this->display();
		$this->assertSame('/service/realtimespeechtotextsession?config_group=chatbot&config_name=sidebar', $this->invoke($display, 'buildSpeechToTextSessionUrl', [
			'speech_to_text_enabled' => true, 'speech_to_text_service' => ' STT-1 ', 'config_group' => ' chatbot ', 'config_name' => 'sidebar'
		]));
		$this->assertSame('/service/texttospeech?config_group=chatbot&config_name=sidebar', $this->invoke($display, 'buildTextToSpeechUrl', [
			'text_to_speech_enabled' => true, 'text_to_speech_service' => 'tts-1', 'config_group' => 'chatbot', 'config_name' => 'sidebar'
		]));
	}

	public function testConversationUrlsRequireServerSideEnablementAndCompleteIdentity(): void {
		$display = $this->display();
		foreach ([
			[],
			['conversation_enabled' => true],
			['conversation_enabled' => true, 'config_group' => 'chatbot'],
			['conversation_enabled' => false, 'config_group' => 'chatbot', 'config_name' => 'sidebar']
		] as $config) {
			$urls = $this->invoke($display, 'buildConversationUrls', $config);
			$this->assertCount(7, $urls);
			$this->assertSame(array_fill_keys(array_keys($urls), ''), $urls);
		}
	}

	public function testAllSevenConversationLinksUseTheSameTrustedIdentity(): void {
		$display = $this->display();
		$urls = $this->invoke($display, 'buildConversationUrls', ['conversation_enabled' => true, 'config_group' => 'chatbot', 'config_name' => 'sidebar']);
		$this->assertSame([
			'conversation_state_url' => '/service/chatbotconversationstate?config_group=chatbot&config_name=sidebar',
			'conversation_create_url' => '/service/chatbotconversationcreate?config_group=chatbot&config_name=sidebar',
			'conversation_materialize_url' => '/service/chatbotconversationmaterialize?config_group=chatbot&config_name=sidebar',
			'conversation_activate_url' => '/service/chatbotconversationactivate?config_group=chatbot&config_name=sidebar',
			'conversation_rename_url' => '/service/chatbotconversationrename?config_group=chatbot&config_name=sidebar',
			'conversation_delete_url' => '/service/chatbotconversationdelete?config_group=chatbot&config_name=sidebar',
			'conversation_title_url' => '/service/chatbotconversationtitle?config_group=chatbot&config_name=sidebar'
		], $urls);
	}

	public function testConfigIdentityParamsRequireBothTrimmedParts(): void {
		$display = $this->display();
		$this->assertSame([], $this->invoke($display, 'getConfigIdentityParams', ['config_group' => 'chatbot', 'config_name' => '  ']));
		$this->assertSame([], $this->invoke($display, 'getConfigIdentityParams', ['config_group' => ' ', 'config_name' => 'sidebar']));
		$this->assertSame(['config_group' => 'chatbot', 'config_name' => 'sidebar'], $this->invoke($display, 'getConfigIdentityParams', ['config_group' => ' chatbot ', 'config_name' => ' sidebar ']));
	}

	public function testValidBackendIsCanonicalAndDoesNotConsultRuntimeSelector(): void {
		$selector = $this->createMock(IAgentRuntimeSelector::class);
		$selector->expects($this->never())->method('getDefaultRuntimeId');
		$display = $this->display(null, $selector);
		$this->assertSame('service:dummy.chatbot-1', $this->invoke($display, 'resolveBackend', ['chatbot_backend' => ' Service:Dummy.Chatbot-1 ']));
		$this->assertSame('runtime:missionbay', $this->invoke($display, 'resolveBackend', ['chatbot_backend' => 'RUNTIME:MissionBay']));
	}

	public function testInvalidBackendPrefersExplicitNormalizedRuntime(): void {
		$selector = $this->createMock(IAgentRuntimeSelector::class);
		$selector->expects($this->never())->method('getDefaultRuntimeId');
		$display = $this->display(null, $selector);
		$this->assertSame('runtime:agent_1', $this->invoke($display, 'resolveBackend', ['chatbot_backend' => 'unexpected:one', 'agent_runtime' => ' AGENT_1!! ']));
	}

	public function testDefaultBackendUsesNormalizedHostRuntime(): void {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn(' RUN-Time_1!! ');
		$display = $this->display(null, $selector);
		$this->assertSame('runtime:run-time_1', $this->invoke($display, 'getDefaultBackend'));
		$this->assertSame('runtime:run-time_1', $this->invoke($display, 'resolveBackend', ['chatbot_backend' => 'invalid:value']));
	}

	public function testDefaultBackendIsDirectServiceWhenHostRuntimeIsEmpty(): void {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('!!!');
		$this->assertSame('service:dummychatbotservice', $this->invoke($this->display(null, $selector), 'getDefaultBackend'));
	}

	public function testDefaultBackendHandlesUnavailableHostSelector(): void {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturnCallback(static function(): never { throw new RuntimeException('host unavailable'); });
		$this->assertSame('service:dummychatbotservice', $this->invoke($this->display(null, $selector), 'getDefaultBackend'));
	}

	public function testBackendToServiceMappingNeverGuessesUnknownType(): void {
		$display = $this->display();
		$this->assertSame('chatbotservice', $this->invoke($display, 'getServiceIdFromBackend', 'runtime:missionbay'));
		$this->assertSame('dummychatbotservice', $this->invoke($display, 'getServiceIdFromBackend', 'service: DUMMYChatbotService!! '));
		$this->assertSame('', $this->invoke($display, 'getServiceIdFromBackend', 'unexpected:service'));
	}

	public function testEnumNormalizationRequiresExactAllowedValue(): void {
		$display = $this->display();
		$this->assertSame('rest', $this->invoke($display, 'normalizeEnum', 'rest', ['auto', 'sse', 'rest'], 'auto'));
		$this->assertSame('auto', $this->invoke($display, 'normalizeEnum', 'REST', ['auto', 'sse', 'rest'], 'auto'));
		$this->assertSame('auto', $this->invoke($display, 'normalizeEnum', 'ftp', ['auto', 'sse', 'rest'], 'auto'));
	}

	public function testTechnicalServiceKeysDiscardUnsupportedCharacters(): void {
		$display = $this->display();
		$this->assertSame('tts-service_1.2', $this->invoke($display, 'normalizeTechnicalKey', ' TTS-Service_1.2? '));
		$this->assertSame('', $this->invoke($display, 'normalizeTechnicalKey', '!?/'));
	}

	public function testBooleanValuesDoNotUsePhpTruthyStringCoercion(): void {
		$display = $this->display();
		foreach ([true, 1, '1', 'YES', 'On', 'TRUE'] as $value) {
			$this->assertTrue($this->invoke($display, 'toBool', $value));
		}
		foreach ([false, 0, -1, 2, '0', 'false', 'off', '', 'anything'] as $value) {
			$this->assertFalse($this->invoke($display, 'toBool', $value));
		}
	}

	public function testLocalizedAiNoticeIsStableAcrossRepeatedReads(): void {
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('de-DE');
		$display = $this->display(null, null, $language);
		$first = $this->invoke($display, 'getDefaultAiNotice');
		$this->assertNotSame('', $first);
		$this->assertSame($first, $this->invoke($display, 'getDefaultAiNotice'));
	}

	public function testUnsupportedLocaleKeepsNonEmptyDefaultNotice(): void {
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('unknown-TZ');
		$display = $this->display(null, null, $language);
		$this->assertSame('en', $this->invoke($display, 'getCurrentLanguage'));
		$this->assertNotSame('', $this->invoke($display, 'getDefaultAiNotice'));
	}

	private function invoke(ChatbotDisplay $display, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod(ChatbotDisplay::class, $method))->invoke($display, ...$args);
	}

	private function display(
		?ISettingsStore $store = null,
		?IAgentRuntimeSelector $selector = null,
		?ILanguage $language = null
	): ChatbotDisplay {
		$store ??= $this->createStub(ISettingsStore::class);
		$selector ??= $this->createStub(IAgentRuntimeSelector::class);
		$language ??= $this->createStub(ILanguage::class);
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(array $target, array $params = []): string {
			$url = '/service/' . (string)($target['name'] ?? '');
			return $params === [] ? $url : $url . '?' . http_build_query($params);
		});
		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstancesByInterface')->willReturn([]);
		return new ChatbotDisplay(
			new FakeChatbotDisplay(),
			$links,
			new ChatbotSettingsService($store, $selector),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($classMap), $store),
			$selector,
			$language,
			new StaticChatbotAppearanceProvider()
		);
	}
}

require_once __DIR__ . '/FakeChatbotDisplay.php';
