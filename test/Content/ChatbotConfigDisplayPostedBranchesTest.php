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
use Chatbot\Content\ChatbotConfigDisplay;
use Chatbot\Test\Service\ChatbotServiceRegistryTestDouble;
use PHPUnit\Framework\TestCase;

final class ChatbotConfigDisplayPostedBranchesTest extends TestCase {

	public function testDirectServiceCanSaveWithoutConsultingTheAgentRuntimeForm(): void {
		$stored = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('set')->willReturnCallback(static function(string $group, string $name, array $settings) use (&$stored): void {
			$stored = $settings;
		});
		$store->expects($this->once())->method('save');
		$form = $this->createMock(IAgentConfigFormService::class);
		$form->expects($this->never())->method('getPostedSettings');
		$input = [
			'action' => 'save', 'chatbot_backend' => 'service:chatbotserviceregistrytestdouble',
			'first_message_mode' => 'random', 'first_messages' => [' Hello ', ' World '],
			'reference' => '{"course":17}', 'transport_mode' => 'rest'
		];
		$result = $this->postJson($input, $store, $form);
		$this->assertTrue($result['success']);
		$this->assertSame('service:chatbotserviceregistrytestdouble', $stored['chatbot_backend']);
		$this->assertSame(['Hello', 'World'], $stored['first_messages']);
		$this->assertSame(['course' => 17], $stored['reference']);
		$this->assertSame('rest', $stored['transport_mode']);
		$this->assertArrayNotHasKey('agent_runtime', $stored);
	}

	public function testRuntimeSaveUsesExistingAgentFormForProfileAndRuntimeSpecificOptions(): void {
		$stored = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('set')->willReturnCallback(static function(string $group, string $name, array $settings) use (&$stored): void {
			$stored = $settings;
		});
		$store->expects($this->once())->method('save');
		$form = $this->createMock(IAgentConfigFormService::class);
		$form->expects($this->once())->method('getPostedSettings')->willReturnCallback(function(array &$errors, ?string $runtimeId): array {
			$this->assertSame([], $errors);
			$this->assertSame('missionbay', $runtimeId);
			return ['memory_profile' => 'memory-1', 'agent_components_assistant_node' => 'answer'];
		});
		$result = $this->postJson([
			'action' => 'save', 'chat_history_enabled' => 'on',
			'chatbot_backend' => 'runtime:missionbay', 'reference' => '{"lesson":4}'
		], $store, $form);
		$this->assertTrue($result['success']);
		$this->assertTrue($stored['chat_history_enabled']);
		$this->assertSame('memory-1', $stored['memory_profile']);
		$this->assertSame('answer', $stored['agent_components_assistant_node']);
		$this->assertSame(['lesson' => 4], $stored['reference']);
	}

	public function testRuntimeFormCannotEnableHistoryWithAnInvalidMemoryProfile(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$store->expects($this->never())->method('save');
		$form = $this->createStub(IAgentConfigFormService::class);
		$form->method('getPostedSettings')->willReturn(['memory_profile' => '!!!']);
		$result = $this->postJson(['action' => 'save', 'chat_history_enabled' => 'on'], $store, $form);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('conversation-memory profile', $result['messages'][0]['text']);
		$this->assertSame('danger', $result['messages'][0]['type']);
	}

	public function testContextualOpeningMessageIsPermittedForAnExistingRuntime(): void {
		$settings = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('set')->willReturnCallback(static function(string $group, string $name, array $data) use (&$settings): void {
			$settings = $data;
		});
		$store->expects($this->once())->method('save');
		$result = $this->postJson([
			'action' => 'save', 'first_message_mode' => 'contextual_ai',
			'first_messages' => [' Welcome '], 'ai_notice_text' => 'Assistant is automated.'
		], $store);
		$this->assertTrue($result['success']);
		$this->assertSame('contextual_ai', $settings['first_message_mode']);
		$this->assertSame(['Welcome'], $settings['first_messages']);
	}

	public function testAjaxSaveActionMustBePresentInExistingCanonicalFormField(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$result = $this->postJson(['action' => 'save', 'chatbot_config_action' => ''], $store);
		$this->assertFalse($result['success']);
		$this->assertSame('Configuration identity does not match.', $result['messages'][0]['text']);
	}

	public function testMultipleInvalidSettingsReturnAllErrorsAndRetainRawFormData(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$store->expects($this->never())->method('save');
		$result = $this->postJson([
			'action' => 'save', 'chatbot_backend' => 'runtime:missing-runtime',
			'first_message_mode' => 'random', 'first_messages' => [],
			'ai_notice_text' => ' ', 'reference' => '{bad-json'
		], $store);
		$this->assertFalse($result['success']);
		$this->assertCount(4, $result['messages']);
		$this->assertStringContainsString('does not exist', $result['messages'][0]['text']);
		$this->assertStringContainsString('valid JSON', $result['messages'][1]['text']);
		$this->assertStringContainsString('at least one message', $result['messages'][2]['text']);
		$this->assertStringContainsString('AI notice text', $result['messages'][3]['text']);
		$this->assertSame('{bad-json', $result['values']['reference_json']);
		$this->assertSame('runtime:missing-runtime', $result['values']['chatbot_backend']);
	}

	public function testInvalidDirectServiceSelectionCannotBeSaved(): void {
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$result = $this->postJson([
			'action' => 'save', 'chatbot_backend' => 'service:unknownservice'
		], $store);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Selected chatbot service does not exist', $result['messages'][0]['text']);
	}

	public function testJsonArrayReferenceRemainsStructuredWhenSaved(): void {
		$stored = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->once())->method('set')->willReturnCallback(static function(string $group, string $name, array $settings) use (&$stored): void {
			$stored = $settings;
		});
		$result = $this->postJson([
			'action' => 'save', 'reference' => '[{"type":"course","id":7},{"type":"page","id":9}]'
		], $store);
		$this->assertTrue($result['success']);
		$this->assertSame([['type' => 'course', 'id' => 7], ['type' => 'page', 'id' => 9]], $stored['reference']);
	}

	public function testHtmlFormSaveKeepsOriginalValuesAndReportsValidationErrors(): void {
		$assigned = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$display = $this->display([
			'chatbot_config_action' => 'save', 'chatbot_backend' => 'runtime:missing',
			'reference' => '{invalid', 'ai_notice_text' => 'Displayed notice'
		], $store, null, $assigned);
		$this->assertSame('RENDERED_POSTED_FORM', $display->getOutput('html'));
		$this->assertSame('runtime:missing', $assigned['values']['chatbot_backend']);
		$this->assertSame('{invalid', $assigned['values']['reference_json']);
		$this->assertSame('danger', $assigned['messages'][0]['type']);
		$this->assertStringContainsString('does not exist', $assigned['messages'][0]['text']);
		$this->assertStringContainsString('valid JSON', $assigned['messages'][1]['text']);
	}

	/** @param array<string,mixed> $input */
	private function postJson(array $input, ?ISettingsStore $store = null, ?IAgentConfigFormService $form = null): array {
		$assigned = [];
		$result = json_decode($this->display($input, $store, $form, $assigned)->getOutput('json'), true);
		$this->assertTrue(is_array($result));
		return $result;
	}

	/** @param array<string,mixed> $overrides @param array<string,mixed> $assigned */
	private function display(
		array $overrides,
		?ISettingsStore $store,
		?IAgentConfigFormService $form,
		array &$assigned
	): ChatbotConfigDisplay {
		$values = array_replace([
			'chatbot_config_action' => 'save',
			'chatbot_config_group' => 'chatbot',
			'chatbot_config_name' => 'sidebar',
			'chatbot_backend' => 'runtime:missionbay',
			'ai_notice_text' => 'This is AI.'
		], $overrides);
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);

		$view = $this->createStub(IMvcView::class);
		$view->method('getBricks')->willReturn([]);
		$view->method('assign')->willReturnCallback(static function(string $name, mixed $value) use (&$assigned): void {
			$assigned[$name] = $value;
		});
		$view->method('loadTemplate')->willReturn('RENDERED_POSTED_FORM');

		$assets = $this->createStub(IAssetResolver::class);
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/settings');

		$store ??= $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn([]);
		$store->method('getGroup')->willReturn([]);

		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceRegistryTestDouble()]);
		$map->method('getInstanceByInterfaceName')->willReturnCallback(static function(string $interface, string $name): ?object {
			return $name === 'chatbotserviceregistrytestdouble' ? new ChatbotServiceRegistryTestDouble() : null;
		});

		$runtime = $this->createStub(IAgentRuntimeRegistry::class);
		$runtime->method('hasRuntime')->willReturnCallback(static fn(string $runtime): bool => $runtime === 'missionbay');
		$runtime->method('getRuntimeOptions')->willReturn([]);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$selector->method('selectRuntimeId')->willReturn('missionbay');

		$form ??= $this->createStub(IAgentConfigFormService::class);
		$form->method('getDefaultSettings')->willReturn([]);
		$form->method('getPostedViewValues')->willReturn([]);
		$form->method('settingsToViewValues')->willReturn([]);
		$form->method('normalizeSettings')->willReturnCallback(static function(array $settings): array {
			return array_intersect_key($settings, [
				'memory_profile' => true, 'agent_components_assistant_node' => true
			]);
		});

		$auth = $this->createStub(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('base3-test');
		$auth->method('decide')->willReturn(AuthorizationDecision::allow());

		$display = new ChatbotConfigDisplay($view, $assets, $request, $store, $links, $map, $form, $runtime, $selector, $auth);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		return $display;
	}
}

require_once dirname(__DIR__) . '/Service/ChatbotServiceRegistryTestDouble.php';
