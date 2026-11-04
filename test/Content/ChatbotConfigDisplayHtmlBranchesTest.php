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
use RuntimeException;

final class ChatbotConfigDisplayHtmlBranchesTest extends TestCase {

	public function testHtmlRenderAssignsCanonicalContextAndReturnsExistingTemplateOutput(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form);
		$display->setData([
			'group' => ' tenant ', 'name' => ' sidebar ',
			'title' => ' Chat settings ', 'description' => ' Configure tenant ',
			'submit_label' => ' Save this ', 'form_action' => '/admin/form'
		]);

		$this->assertSame('RENDERED_CHATBOT_SETTINGS', $display->getOutput('html'));
		$this->assertSame('tenant', $assigned['group']);
		$this->assertSame('sidebar', $assigned['name']);
		$this->assertSame('Chat settings', $assigned['title']);
		$this->assertSame('Configure tenant', $assigned['description']);
		$this->assertSame('Save this', $assigned['submit_label']);
		$this->assertSame('/admin/form', $assigned['form_action']);
		$this->assertSame('base3_chatbot_config_' . md5('tenant/sidebar'), $assigned['form_id']);
		$this->assertSame('/endpoint/chatbotconfigdisplay?action=save', $assigned['save_url']);
		$this->assertSame('runtime:missionbay', $form['settings']['chatbot_backend']);
	}

	public function testBackendOptionsMergeServiceAndRuntimeAndSortByCaseInsensitiveLabel(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [
			['id' => 'runtime-z', 'label' => 'Zebra'],
			['id' => 'runtime-a', 'label' => 'alpha'],
			['id' => '!!!', 'label' => 'invalid'],
			['id' => 'Runtime A!', 'label' => 'beta']
		], [], $assigned, $form, [new ChatbotServiceRegistryTestDouble()]);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		$this->assertSame('RENDERED_CHATBOT_SETTINGS', $display->getOutput());
		$options = $assigned['backend_options'];
		$this->assertSame(['runtime:runtime-a', 'runtime:runtimea', 'service:chatbotserviceregistrytestdouble', 'runtime:runtime-z'], array_column($options, 'id'));
		$this->assertSame(['alpha', 'beta', 'Test service', 'Zebra'], array_column($options, 'label'));
		$this->assertSame('/endpoint/chatbotservice?config_group=chatbot&config_name=sidebar', $options[0]['url']);
		$this->assertSame('/endpoint/chatbotserviceregistrytestdouble?config_group=chatbot&config_name=sidebar', $options[2]['url']);
	}

	public function testBackendOptionsIgnoreInstancesThatAreNotChatbotServices(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form, [new \stdClass(), new ChatbotServiceRegistryTestDouble()]);
		$display->getOutput();
		$this->assertSame(['service:chatbotserviceregistrytestdouble'], array_column($assigned['backend_options'], 'id'));
	}

	public function testBackendDiscoveryFailureDoesNotBlockSettingsRendering(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form, null, true);
		$this->assertSame('RENDERED_CHATBOT_SETTINGS', $display->getOutput());
		$this->assertSame([], $assigned['backend_options']);
		$this->assertSame('danger', $assigned['messages'][0]['type']);
		$this->assertStringContainsString('Discovery offline', $assigned['messages'][0]['text']);
	}

	public function testSpeechServiceDropdownsFilterDisabledWrongTypeAndMalformedEntries(): void {
		$assigned = [];
		$form = [];
		$groups = [
			'service-stt' => [
				'zeta' => ['serviceType' => ' STT ', 'name' => 'Zeta', 'model' => 'model-z'],
				'alpha' => ['serviceType' => 'stt', 'enabled' => 'on', 'id' => ' Alpha! Provider ', 'name' => ' alpha ', 'driver' => 'cloud'],
				'disabled' => ['serviceType' => 'stt', 'enabled' => 'off'],
				'wrong' => ['serviceType' => 'tts'],
				'junk' => 'invalid',
				'!!!' => ['serviceType' => 'stt', 'id' => '!!!']
			],
			'service-tts' => [
				'voice-z' => ['serviceType' => 'tts', 'name' => 'Zeta Voice', 'options' => ['voice' => ' German ']],
				'voice-a' => ['serviceType' => 'TTS', 'id' => 'Voice A!', 'name' => 'Alpha Voice', 'model' => 'voice-model'],
				'voice-off' => ['serviceType' => 'tts', 'enabled' => false],
				'incorrect' => ['serviceType' => 'stt'],
				'empty' => ['serviceType' => 'tts', 'id' => '!!!']
			]
		];
		$display = $this->display([], [], $groups, $assigned, $form);
		$display->getOutput('html');
		$this->assertSame(['alphaprovider', 'zeta'], array_column($assigned['speech_to_text_services'], 'id'));
		$this->assertSame(['alpha', 'Zeta'], array_column($assigned['speech_to_text_services'], 'name'));
		$this->assertSame('cloud', $assigned['speech_to_text_services'][0]['driver']);
		$this->assertSame(['voicea', 'voice-z'], array_column($assigned['text_to_speech_services'], 'id'));
		$this->assertSame('', $assigned['text_to_speech_services'][0]['voice']);
		$this->assertSame('German', $assigned['text_to_speech_services'][1]['voice']);
	}

	public function testSpeechDropdownLookupFailureReturnsEmptyListsInsteadOfThrowing(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form, [], false, true);
		$this->assertSame('RENDERED_CHATBOT_SETTINGS', $display->getOutput());
		$this->assertSame([], $assigned['speech_to_text_services']);
		$this->assertSame([], $assigned['text_to_speech_services']);
	}

	public function testRuntimeBackendIsPassedToTheExistingAgentConfigurationForm(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([
			'chatbot_backend' => 'runtime:missionbay', 'agent_flow' => ['nodes' => [['id' => 'answer']]],
			'memory_profile' => 'memory', 'use_markdown' => false
		], [], [], $assigned, $form);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		$display->getOutput();
		$this->assertSame('missionbay', $form['settings']['agent_runtime']);
		$this->assertSame(['nodes' => [['id' => 'answer']]], $form['settings']['agent_flow']);
		$this->assertSame('missionbay', $form['options']['selected_runtime']);
		$this->assertTrue($form['options']['runtime_active']);
		$this->assertFalse($form['options']['show_runtime_selector']);
		$this->assertSame(false, $assigned['values']['use_markdown']);
	}

	public function testDirectServiceBackendPreservesFormSettingsWithoutInjectingAgentRuntime(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([
			'chatbot_backend' => 'service:chatbotserviceregistrytestdouble',
			'first_message_mode' => 'random', 'first_messages' => ['Hello']
		], [], [], $assigned, $form);
		$display->getOutput();
		$this->assertFalse($form['options']['runtime_active']);
		$this->assertSame('missionbay', $form['options']['selected_runtime']);
		$this->assertArrayNotHasKey('agent_runtime', $form['settings']);
		$this->assertSame(['Hello'], $assigned['values']['first_messages']);
	}

	public function testEmbeddedRenderingPassesResourcesAndDoesNotForceOuterForm(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form);
		$display->setData([
			'group' => 'course', 'name' => 'assistant',
			'mode' => 'embedded', 'save_mode' => 'post',
			'resources' => [
				'enabled' => true, 'endpoints' => ['list' => '/files/list'],
				'max_file_size' => 0, 'accept' => ' .pdf,.txt '
			]
		]);
		$display->getOutput();
		$this->assertFalse($assigned['render_form']);
		$this->assertSame('embedded', $assigned['mode']);
		$this->assertSame('post', $assigned['save_mode']);
		$this->assertSame(1, $form['options']['chatbot_resources']['max_file_size']);
		$this->assertSame(['list' => '/files/list'], $form['options']['chatbot_resources']['endpoints']);
		$this->assertSame('.pdf,.txt', $form['options']['chatbot_resources']['accept']);
		$this->assertSame('/assets/plugin/ClientStack/assets/filemanager/index.js', $form['options']['chatbot_resources']['module_url']);
	}

	public function testNoResourcesArePassedAsDisabledToExistingAgentForm(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form);
		$display->getOutput();
		$this->assertSame(['enabled' => false], $form['options']['chatbot_resources']);
	}

	public function testInvalidResourcesCannotBeSilentlyEnabledWithoutEndpoints(): void {
		$assigned = [];
		$form = [];
		$display = $this->display([], [], [], $assigned, $form);
		$display->setData(['resources' => ['enabled' => true, 'endpoints' => []]]);
		$this->expectException(RuntimeException::class);
		$display->getOutput();
	}

	public function testUnsupportedOutputFormatDoesNotQueryStoresOrRender(): void {
		$assigned = [];
		$form = [];
		$store = $this->createStub(ISettingsStore::class);
		$settingsCalls = 0;
		$store->method('get')->willReturnCallback(static function() use (&$settingsCalls): array { $settingsCalls++; return []; });
		$store->method('getGroup')->willReturnCallback(static function() use (&$settingsCalls): array { $settingsCalls++; return []; });
		$display = $this->display([], [], [], $assigned, $form, [], false, false, $store);
		$this->assertSame('', $display->getOutput('xml'));
		$this->assertSame([], $assigned);
		$this->assertSame([], $form);
		$this->assertSame(0, $settingsCalls);
	}

	public function testAuthorizationDenialPreventsAnyViewAndSettingsLookup(): void {
		$assigned = [];
		$form = [];
		$store = $this->createStub(ISettingsStore::class);
		$settingsCalls = 0;
		$store->method('get')->willReturnCallback(static function() use (&$settingsCalls): array { $settingsCalls++; return []; });
		$store->method('getGroup')->willReturnCallback(static function() use (&$settingsCalls): array { $settingsCalls++; return []; });
		$display = $this->display([], [], [], $assigned, $form, [], false, false, $store, false);
		$previous = http_response_code();
		try {
			$this->assertSame('', $display->getOutput('html'));
			$this->assertSame(403, http_response_code());
			$this->assertSame([], $assigned);
			$this->assertSame([], $form);
			$this->assertSame(0, $settingsCalls);
		}
		finally {
			http_response_code(is_int($previous) && $previous > 0 ? $previous : 200);
		}
	}

	public function testMissingMatchingSaveIdentityDoesNotWriteDuringHtmlRendering(): void {
		$assigned = [];
		$form = [];
		$store = $this->createMock(ISettingsStore::class);
		$store->expects($this->never())->method('set');
		$store->expects($this->never())->method('save');
		$display = $this->display([], [], [], $assigned, $form, [], false, false, $store, true, [
			'chatbot_config_action' => 'save',
			'chatbot_config_group' => 'forged', 'chatbot_config_name' => 'sidebar'
		]);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		$this->assertSame('RENDERED_CHATBOT_SETTINGS', $display->getOutput());
		$this->assertSame('chatbot', $assigned['group']);
	}

	/**
	 * @param array<string,mixed> $stored
	 * @param array<int,array<string,mixed>> $runtimes
	 * @param array<string,array<string,mixed>> $speechGroups
	 * @param array<string,mixed> $assigned
	 * @param array<string,mixed> $form
	 * @param array<int,mixed>|null $services
	 * @param array<string,mixed> $requestValues
	 */
	private function display(
		array $stored,
		array $runtimes,
		array $speechGroups,
		array &$assigned,
		array &$form,
		?array $services = [],
		bool $failDiscovery = false,
		bool $failSpeech = false,
		?ISettingsStore $store = null,
		bool $authorized = true,
		array $requestValues = []
	): ChatbotConfigDisplay {
		$view = $this->createStub(IMvcView::class);
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		$view->method('getBricks')->willReturn([]);
		$view->method('loadTemplate')->willReturn('RENDERED_CHATBOT_SETTINGS');

		$assets = $this->createStub(IAssetResolver::class);
		$assets->method('resolve')->willReturnCallback(static fn(string $path): string => '/assets/' . $path);

		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(array $target, array $params = []): string {
			$name = (string)($target['name'] ?? '');
			return '/endpoint/' . $name . ($params === [] ? '' : '?' . http_build_query($params));
		});

		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);

		$store ??= $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn($stored);
		$store->method('getGroup')->willReturnCallback(static function(string $group) use ($speechGroups, $failSpeech): array {
			if ($failSpeech) {
				throw new RuntimeException('Speech storage offline');
			}
			return $speechGroups[$group] ?? [];
		});

		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturnCallback(static function() use ($services, $failDiscovery): array {
			if ($failDiscovery) {
				throw new RuntimeException('Discovery offline');
			}
			return $services ?? [];
		});

		$runtime = $this->createStub(IAgentRuntimeRegistry::class);
		$runtime->method('getRuntimeOptions')->willReturn($runtimes);
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$selector->method('selectRuntimeId')->willReturn('missionbay');

		$agentForm = $this->createStub(IAgentConfigFormService::class);
		$agentForm->method('getDefaultSettings')->willReturn([]);
		$agentForm->method('normalizeSettings')->willReturnCallback(static function(array $settings): array {
			return array_intersect_key($settings, ['agent_flow' => true, 'memory_profile' => true]);
		});
		$agentForm->method('settingsToViewValues')->willReturn([]);
		$agentForm->method('assignViewData')->willReturnCallback(static function(IMvcView $view, array $settings, array $options) use (&$form): void {
			$form = ['settings' => $settings, 'options' => $options];
		});

		$auth = $this->createStub(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('base3-test');
		$auth->method('decide')->willReturn($authorized ? AuthorizationDecision::allow() : AuthorizationDecision::deny());

		return new ChatbotConfigDisplay($view, $assets, $request, $store, $links, $map, $agentForm, $runtime, $selector, $auth);
	}
}

require_once dirname(__DIR__) . '/Service/ChatbotServiceRegistryTestDouble.php';
