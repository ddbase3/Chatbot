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
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConfigRuntimeAndResourceEdgesTest extends TestCase {

	public function testRuntimeNormalizationDelegatesToExistingAgentFormWithCanonicalRuntime(): void {
		$forms = $this->createMock(IAgentConfigFormService::class);
		$forms->method('getDefaultSettings')->willReturn([]);
		$forms->expects($this->once())->method('normalizeSettings')->willReturnCallback(function(array $settings): array {
			$this->assertSame('missionbay', $settings['agent_runtime']);
			$this->assertSame(['nodes' => [['id' => 'assistant']]], $settings['agent_flow']);
			return ['agent_flow' => $settings['agent_flow'], 'model' => 'model-1'];
		});
		$display = $this->newConfigDisplayFixture($forms);
		$settings = $display->normalizeTestSettings([
			'chatbot_backend' => ' RUNTIME:MISSIONBAY! ',
			'agent_runtime' => 'forged-runtime',
			'agent_flow' => ['nodes' => [['id' => 'assistant']]]
		]);
		$this->assertSame('runtime:missionbay', $settings['chatbot_backend']);
		$this->assertSame('model-1', $settings['model']);
	}

	public function testDirectServiceDoesNotRequestAgentConfigurationNormalization(): void {
		$forms = $this->createMock(IAgentConfigFormService::class);
		$forms->method('getDefaultSettings')->willReturn([]);
		$forms->expects($this->never())->method('normalizeSettings');
		$settings = $this->newConfigDisplayFixture($forms)->normalizeTestSettings([
			'chatbot_backend' => 'service:dummychatbotservice',
			'transport_mode' => 'rest'
		]);
		$this->assertSame('service:dummychatbotservice', $settings['chatbot_backend']);
		$this->assertSame('rest', $settings['transport_mode']);
		$this->assertArrayNotHasKey('agent_runtime', $settings);
	}

	public function testUnknownBackendUsesSelectedHostRuntimeRatherThanStaleDefault(): void {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$selector->method('selectRuntimeId')->willReturn('neuronai');
		$settings = $this->newConfigDisplayFixture(null, $selector)->normalizeTestSettings(['chatbot_backend' => 'unsupported:foo']);
		$this->assertSame('runtime:neuronai', $settings['chatbot_backend']);
	}

	public function testBackendWithNoTechnicalIdentifierUsesSelectedHostRuntime(): void {
		$settings = $this->newConfigDisplayFixture()->normalizeTestSettings(['chatbot_backend' => 'runtime:!!!']);
		$this->assertSame('runtime:missionbay', $settings['chatbot_backend']);
	}

	public function testBlankConfiguredTitleAndSubmitLabelUseExistingTranslations(): void {
		$display = $this->newConfigDisplayFixture();
		$display->setData(['title' => ' ', 'submit_label' => ' ', 'mode' => 'embedded']);
		$context = $display->getTestContext();
		$this->assertSame('Chatbot Configuration', $context['title']);
		$this->assertSame('Save', $context['submit_label']);
		$this->assertFalse($context['render_form']);
	}

	public function testEmbeddedFormCanBeExplicitlyRenderedThroughExistingSetting(): void {
		$display = $this->newConfigDisplayFixture();
		$display->setData(['mode' => 'embedded', 'render_form' => 'yes', 'save_mode' => 'post']);
		$context = $display->getTestContext();
		$this->assertTrue($context['render_form']);
		$this->assertSame('post', $context['save_mode']);
	}

	public function testResourceDefaultsKeepExistingFileSizeAndResolvedAssets(): void {
		$display = $this->newConfigDisplayFixture();
		$display->setData(['resources' => ['enabled' => true, 'endpoints' => ['list' => '/resources/list']]]);
		$resources = $display->getTestResourcesConfig();
		$this->assertSame(50 * 1024 * 1024, $resources['max_file_size']);
		$this->assertSame('', $resources['accept']);
		$this->assertSame('/assets/plugin/ClientStack/assets/filemanager/index.js', $resources['module_url']);
		$this->assertSame('/assets/plugin/ClientStack/assets/filemanager/styles/filemanager.css', $resources['css_url']);
	}

	public function testResourceDisabledByExplicitSettingIgnoresBrokenEndpoints(): void {
		$display = $this->newConfigDisplayFixture();
		$display->setData(['resources' => ['enabled' => false, 'endpoints' => 'invalid']]);
		$this->assertSame(['enabled' => false], $display->getTestResourcesConfig());
	}

	public function testRuntimeBackendOptionsUseTechnicalIdentifierForBlankLabelAndSortTies(): void {
		$assigned = [];
		$registry = $this->createStub(IAgentRuntimeRegistry::class);
		$registry->method('getRuntimeOptions')->willReturn([
			['id' => 'zeta', 'label' => 'Same'],
			['id' => 'alpha', 'label' => 'Same'],
			['id' => 'first-runtime', 'label' => ' ']
		]);
		$display = $this->newConfigDisplayFixture(null, null, $registry, $assigned);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		$this->assertSame('RENDERED', $display->getOutput('html'));
		$this->assertSame(['runtime:first-runtime', 'runtime:alpha', 'runtime:zeta'], array_column($assigned['backend_options'], 'id'));
		$this->assertSame(['first-runtime', 'Same', 'Same'], array_column($assigned['backend_options'], 'label'));
	}

	public function testServiceLinkFailureKeepsBackendOptionButOmitsBrokenUrl(): void {
		$assigned = [];
		$registry = $this->createStub(IAgentRuntimeRegistry::class);
		$registry->method('getRuntimeOptions')->willReturn([['id' => 'missionbay', 'label' => 'MissionBay']]);
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(): never {
			throw new RuntimeException('Link resolver offline');
		});
		$display = $this->newConfigDisplayFixture(null, null, $registry, $assigned, $links);
		$display->setData(['save_url' => '/existing-save']);
		$this->assertSame('RENDERED', $display->getOutput());
		$this->assertSame('runtime:missionbay', $assigned['backend_options'][0]['id']);
		$this->assertSame('', $assigned['backend_options'][0]['url']);
		$this->assertSame('/existing-save', $assigned['save_url']);
	}

	public function testStoredStructuredReferenceIsSerializedForExistingFormWithoutModification(): void {
		$assigned = [];
		$store = $this->createStub(ISettingsStore::class);
		$store->method('get')->willReturn([
			'chatbot_backend' => 'runtime:missionbay',
			'reference' => ['course' => 17, 'context' => ['title' => 'Muenchen']]
		]);
		$display = $this->newConfigDisplayFixture(null, null, null, $assigned, null, $store);
		$display->setData(['group' => 'chatbot', 'name' => 'sidebar']);
		$display->getOutput();
		$this->assertSame(['course' => 17, 'context' => ['title' => 'Muenchen']], json_decode($assigned['values']['reference_json'], true));
	}

	/** @param array<string,mixed> $assigned */
	private function newConfigDisplayFixture(
		?IAgentConfigFormService $forms = null,
		?IAgentRuntimeSelector $selector = null,
		?IAgentRuntimeRegistry $runtimeRegistry = null,
		array &$assigned = [],
		?ILinkTargetService $links = null,
		?ISettingsStore $settings = null
	): TestableChatbotConfigDisplay {
		$view = $this->createStub(IMvcView::class);
		$view->method('getBricks')->willReturn([]);
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		$view->method('loadTemplate')->willReturn('RENDERED');
		$asset = $this->createStub(IAssetResolver::class);
		$asset->method('resolve')->willReturnCallback(static fn(string $path): string => '/assets/' . $path);
		$links ??= $this->createStub(ILinkTargetService::class);
		if ($selector === null) {
			$selector = $this->createStub(IAgentRuntimeSelector::class);
			$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
			$selector->method('selectRuntimeId')->willReturn('missionbay');
		}
		$forms ??= $this->createStub(IAgentConfigFormService::class);
		$runtimeRegistry ??= $this->createStub(IAgentRuntimeRegistry::class);
		$settings ??= $this->createStub(ISettingsStore::class);
		$settings->method('getGroup')->willReturn([]);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		$auth = $this->createStub(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('base3');
		$auth->method('decide')->willReturn(AuthorizationDecision::allow());
		return new TestableChatbotConfigDisplay(
			$view, $asset, $this->createStub(IRequest::class), $settings, $links,
			$map, $forms, $runtimeRegistry, $selector, $auth
		);
	}
}

require_once __DIR__ . '/TestableChatbotConfigDisplay.php';
