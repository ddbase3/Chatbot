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
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Test\Service\ChatbotServiceRegistryTestDouble;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConfigDisplayWorkflowTest extends TestCase {

	public function testUnsupportedJsonActionDoesNotWriteSettings(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$result = $this->postJson([], $store);
		$this->assertSame('error', $result['status']);
		$this->assertFalse($result['success']);
		$this->assertSame('Unknown action.', $result['messages'][0]['text']);
	}

	public function testRequestIdentityMustMatchConfiguredInstanceBeforeSave(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		foreach ([
			['chatbot_config_group' => 'other'],
			['chatbot_config_name' => 'other'],
			['chatbot_config_group' => ''],
			['chatbot_config_name' => '']
		] as $overrides) {
			$result = $this->postJson($overrides + ['action' => 'save'], $store);
			$this->assertFalse($result['success']);
			$this->assertSame('Configuration identity does not match.', $result['messages'][0]['text']);
		}
	}

	public function testMissingBackendIsRejectedWithoutPersistence(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$result = $this->postJson(['action' => 'save', 'chatbot_backend' => ''], $store);
		$this->assertFalse($result['success']);
		$this->assertSame('Please select a chatbot backend.', $result['messages'][0]['text']);
	}

	public function testUnknownRuntimeIsRejectedWithoutPersistence(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$result = $this->postJson(['action' => 'save', 'chatbot_backend' => 'runtime:unknown'], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('does not exist', $result['messages'][0]['text']);
	}

	public function testRandomOpeningMessagesRequireAtLeastOneNonemptyEntry(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$result = $this->postJson([
			'action' => 'save', 'first_message_mode' => 'random', 'first_messages' => ["   ", '', []]
		], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('requires at least one message', $result['messages'][0]['text']);
	}

	public function testContextualOpeningMessageRequiresRuntimeBackend(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$result = $this->postJson([
			'action' => 'save',
			'chatbot_backend' => 'service:chatbotserviceregistrytestdouble',
			'first_message_mode' => 'contextual_ai'
		], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('agent runtime backend', $result['messages'][0]['text']);
	}

	public function testAiNoticeCannotBeEmpty(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$result = $this->postJson(['action' => 'save', 'ai_notice_text' => " \n "], $store);
		$this->assertFalse($result['success']);
		$this->assertSame('The AI notice text must not be empty.', $result['messages'][0]['text']);
	}

	public function testInvalidJsonReferenceIsRejectedWithoutStoringSettings(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$result = $this->postJson(['action' => 'save', 'reference' => '{invalid-json'], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('valid JSON', $result['messages'][0]['text']);
	}

	public function testScalarJsonReferenceIsRejected(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$result = $this->postJson(['action' => 'save', 'reference' => 'false'], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('JSON object or array', $result['messages'][0]['text']);
	}

	public function testHistoryRequiresMemoryProfile(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$result = $this->postJson(['action' => 'save', 'chat_history_enabled' => 'on'], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('conversation-memory profile', $result['messages'][0]['text']);
	}

	public function testSuccessfulSavePreservesCanonicalBackendAndSettings(): void {
		$captured = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('set')->willReturnCallback(
			function(string $group, string $name, array $settings) use (&$captured): void {
				$this->assertSame('chatbot', $group);
				$this->assertSame('sidebar', $name);
				$captured = $settings;
			}
		);
		$store->expects($this->once())->method('save');
		$result = $this->postJson([
			'action' => 'save', 'chatbot_backend' => ' RUNTIME:MISSIONBAY! ',
			'first_message_mode' => 'random', 'first_messages' => " Hello\r\nworld ",
			'ai_notice_text' => ' Important notice ', 'reference' => '{"course":17}',
			'transport_mode' => 'rest', 'use_markdown' => 'on',
			'speech_to_text_service' => ' Speech-1 ', 'text_to_speech_service' => 'Voice-1'
		], $store);
		$this->assertTrue($result['success']);
		$this->assertSame('ok', $result['status']);
		$this->assertSame('runtime:missionbay', $captured['chatbot_backend']);
		$this->assertSame("Hello\nworld", $captured['first_messages'][0]);
		$this->assertSame(['course' => 17], $captured['reference']);
		$this->assertSame('rest', $captured['transport_mode']);
		$this->assertSame('speech-1', $captured['speech_to_text_service']);
		$this->assertSame('voice-1', $captured['text_to_speech_service']);
		$this->assertTrue($captured['use_markdown']);
		$this->assertSame('Settings saved.', $result['messages'][0]['text']);
	}

	public function testStorageFailureIsReportedAndDoesNotAcknowledgeSave(): void {
		$store = $this->createStub(ISettingsStore::class);
		$store->method('save')->willReturnCallback(static function(): never { throw new RuntimeException('write unavailable'); });
		$result = $this->postJson(['action' => 'save'], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('write unavailable', $result['messages'][0]['text']);
	}

	public function testDeniedPermissionPreventsSaveAndRendering(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('save');
		$originalStatus = http_response_code();
		try {
			$result = $this->makeDisplay(['action' => 'save'], $store, false)->getOutput('json');
			$this->assertSame('', $result);
			$this->assertSame(403, http_response_code());
		}
		finally {
			http_response_code(is_int($originalStatus) && $originalStatus > 0 ? $originalStatus : 200);
		}
	}

	/** @param array<string,mixed> $overrides */
	private function postJson(array $overrides, ?ISettingsStore $store = null): array {
		$reply = $this->makeDisplay($overrides, $store)->getOutput('json');
		$decoded = json_decode($reply, true);
		$this->assertTrue(is_array($decoded));
		return $decoded;
	}

	/** @param array<string,mixed> $overrides */
	private function makeDisplay(array $overrides, ?ISettingsStore $store = null, bool $allowed = true): TestableChatbotConfigDisplay {
		$input = array_replace([
			'chatbot_config_action' => ($overrides['action'] ?? '') === 'save' ? 'save' : '',
			'chatbot_config_group' => 'chatbot',
			'chatbot_config_name' => 'sidebar',
			'chatbot_backend' => 'runtime:missionbay',
			'ai_notice_text' => 'AI notice'
		], $overrides);
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $input[$key] ?? $default);
		$store ??= $this->createStub(ISettingsStore::class);
		$view = $this->createStub(IMvcView::class);
		$view->method('getBricks')->willReturn([]);
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/chatbot/configuration');
		$runtime = $this->createStub(IAgentRuntimeRegistry::class);
		$runtime->method('hasRuntime')->willReturnCallback(static fn(string $name): bool => $name === 'missionbay');
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$selector->method('selectRuntimeId')->willReturn('missionbay');
		$forms = $this->createStub(IAgentConfigFormService::class);
		$forms->method('getDefaultSettings')->willReturn([]);
		$forms->method('getPostedSettings')->willReturn([]);
		$forms->method('getPostedViewValues')->willReturn([]);
		$forms->method('settingsToViewValues')->willReturn([]);
		$forms->method('normalizeSettings')->willReturn([]);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstanceByInterfaceName')->willReturn(new ChatbotServiceRegistryTestDouble());
		$auth = $this->createStub(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('base3-test');
		$auth->method('decide')->willReturn($allowed ? AuthorizationDecision::allow() : AuthorizationDecision::deny());
		$display = new TestableChatbotConfigDisplay(
			$view, $this->createStub(IAssetResolver::class), $request, $store, $links,
			$map, $forms, $runtime, $selector, $auth
		);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		return $display;
	}
}

require_once __DIR__ . '/TestableChatbotConfigDisplay.php';
require_once dirname(__DIR__) . '/Service/ChatbotServiceRegistryTestDouble.php';
