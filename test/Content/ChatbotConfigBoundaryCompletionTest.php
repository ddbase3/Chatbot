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

final class ChatbotConfigBoundaryCompletionTest extends TestCase {

	public function testRequestIdentityIsReadOnlyWhenExplicitlyPermitted(): void {
		$display = $this->display(['chatbot_config_group' => ' group-from-request ', 'chatbot_config_name' => ' instance-from-request ']);
		$this->assertSame('chatbot', $display->inspectContext(false)['group']);
		$this->assertSame('default', $display->inspectContext(false)['name']);
		$this->assertSame('group-from-request', $display->inspectContext(true)['group']);
		$this->assertSame('instance-from-request', $display->inspectContext(true)['name']);
	}

	public function testExplicitConfigurationIdentityOverridesSubmittedIdentity(): void {
		$display = $this->display(['chatbot_config_group' => 'forged', 'chatbot_config_name' => 'forged']);
		$display->setData(['group' => 'valid', 'name' => 'trusted']);
		$this->assertSame('valid', $display->inspectContext(true)['group']);
		$this->assertSame('trusted', $display->inspectContext(true)['name']);
	}

	public function testSaveDetectionRequiresActionAndMatchingConfigurationIdentity(): void {
		$display = $this->display(['chatbot_config_action' => 'save', 'chatbot_config_group' => 'tenant', 'chatbot_config_name' => 'widget']);
		$context = ['group' => 'tenant', 'name' => 'widget'];
		$this->assertTrue($display->inspectSaveRequest($context));
		$this->assertFalse($display->inspectSaveRequest(['group' => 'other', 'name' => 'widget']));
		$this->assertFalse($display->inspectSaveRequest(['group' => 'tenant', 'name' => 'other']));
		$this->assertFalse($this->display(['chatbot_config_action' => 'load', 'chatbot_config_group' => 'tenant', 'chatbot_config_name' => 'widget'])->inspectSaveRequest($context));
	}

	public function testUrlOverrideDoesNotRequireLinkTargetLookup(): void {
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects($this->never())->method('getLink');
		$display = $this->display([], $links);
		$display->setData(['save_url' => ' /config/custom ']);
		$this->assertSame('/config/custom', $display->inspectSaveUrl());
	}

	public function testDefaultSaveUrlUsesCanonicalOutputAndSaveAction(): void {
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects($this->once())->method('getLink')
			->with(['name' => 'chatbotconfigdisplay', 'out' => 'json'], ['action' => 'save'])
			->willReturn('/chatbot-settings');
		$this->assertSame('/chatbot-settings', $this->display([], $links)->inspectSaveUrl());
	}

	public function testMalformedJsonReportsErrorInsteadOfReturningScalar(): void {
		$display = $this->display();
		$errors = [];
		$this->assertSame([], $display->inspectJson('{broken', 'Flow', $errors));
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('Flow', $errors[0]);
		$errors = [];
		$this->assertSame([], $display->inspectJson('"string"', 'Reference', $errors));
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('Reference', $errors[0]);
	}

	public function testValidArrayAndObjectJsonArePreserved(): void {
		$display = $this->display();
		$errors = [];
		$this->assertSame(['a' => ['b' => 2]], $display->inspectJson('{"a":{"b":2}}', 'Reference', $errors));
		$this->assertSame([1, 2], $display->inspectJson('[1,2]', 'Flow', $errors));
		$this->assertSame([], $display->inspectReference('', $errors));
		$this->assertSame([], $errors);
	}

	public function testJsonErrorResetsMessagesToOneFailureAndDoesNotIncludeSuccess(): void {
		$display = $this->display();
		$this->assertSame('ok', json_decode($display->inspectJsonResponse(true, ['value' => 7]), true)['status']);
		$this->assertSame('error', json_decode($display->inspectJsonResponse(false), true)['status']);
		$payload = json_decode($display->inspectJsonError('Rejected.'), true);
		$this->assertSame(false, $payload['success']);
		$this->assertSame([['type' => 'danger', 'text' => 'Rejected.']], $display->inspectMessages());
		$display->setData([]);
		$this->assertSame([], $display->inspectMessages());
	}

	public function testResourcesRequireEndpointDefinitionsOnlyWhenEnabled(): void {
		$display = $this->display();
		$display->setData(['resources' => ['enabled' => false]]);
		$this->assertSame(['enabled' => false], $display->inspectResources());
		$display->setData(['resources' => ['enabled' => true, 'endpoints' => []]]);
		$this->expectException(RuntimeException::class);
		$display->inspectResources();
	}

	public function testBackendNormalizationDoesNotAcceptMissingTechnicalIds(): void {
		$display = $this->display();
		$this->assertSame('', $display->inspectBackend('runtime:!!!'));
		$this->assertSame('', $display->inspectBackend('service: '));
		$this->assertSame('', $display->inspectBackend('unknown:backend'));
		$this->assertSame('', $display->inspectRuntime('service:dummychatbotservice'));
		$this->assertSame('', $display->inspectService('runtime:missionbay'));
		$this->assertSame('missionbay', $display->inspectRuntime(' RUNTIME:MissionBay '));
		$this->assertSame('dummychatbotservice', $display->inspectService(' SERVICE:DummyChatbotService '));
	}

	public function testBackendSelectionUsesExplicitValueBeforeSelector(): void {
		$selector = $this->createMock(IAgentRuntimeSelector::class);
		$selector->expects($this->never())->method('selectRuntimeId');
		$display = $this->display([], null, $selector);
		$this->assertSame('service:dummychatbotservice', $display->inspectBackendFromSettings(['chatbot_backend' => 'SERVICE:dummychatbotservice']));
	}

	public function testMalformedBackendResolvesViaConfiguredSelector(): void {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('selectRuntimeId')->willReturn('chosen-runtime');
		$display = $this->display([], null, $selector);
		$this->assertSame('runtime:chosen-runtime', $display->inspectBackendFromSettings(['chatbot_backend' => 'bogus:invalid']));
	}

	public function testMessageNormalizationRejectsNestedStructuresAndBlankRows(): void {
		$display = $this->display();
		$this->assertSame([], $display->inspectMessageList(false));
		$this->assertSame([], $display->inspectMessageList('  '));
		$this->assertSame(['first', 'second'], $display->inspectMessageList([' first ', [], ' ', new \stdClass(), 'second']));
		$this->assertSame(["One\nTwo"], $display->inspectMessageList("One\r\nTwo"));
	}

	public function testInvalidJsonArrayMessageIsPreservedWithoutGuessing(): void {
		$display = $this->display();
		$this->assertSame(['[not-json'], $display->inspectMessageList('[not-json'));
		$this->assertSame(['1'], $display->inspectMessageList('[true]'));
		$this->assertSame(['valid'], $display->inspectMessageList('["valid", "", null]'));
	}

	public function testPrettyJsonIsUnicodeSafeAndFallsBackForRecursiveData(): void {
		$display = $this->display();
		$this->assertSame('[]', $display->inspectFormatJson([], '[]'));
		$this->assertSame("{\n    \"url\": \"https://example.test/a/b\",\n    \"text\": \"Gruesse\"\n}", $display->inspectFormatJson(['url' => 'https://example.test/a/b', 'text' => 'Gruesse'], '{}'));
		$recursive = [];
		$recursive['self'] = &$recursive;
		$this->assertSame('{}', $display->inspectFormatJson($recursive, '{}'));
	}

	public function testServiceBackendViewValuesDoNotInvokeAgentFormTransformation(): void {
		$forms = $this->createMock(IAgentConfigFormService::class);
		$forms->expects($this->never())->method('settingsToViewValues');
		$forms->expects($this->never())->method('normalizeSettings');
		$forms->method('getDefaultSettings')->willReturn([]);
		$values = $this->display([], null, null, $forms)->inspectViewValues([
			'chatbot_backend' => 'service:dummychatbotservice',
			'reference' => ['resource' => 'course/7'],
			'first_message_mode' => 'none'
		]);
		$this->assertSame('service:dummychatbotservice', $values['chatbot_backend']);
		$this->assertStringContainsString('course/7', $values['reference_json']);
	}

	private function display(
		array $requestValues = [],
		?ILinkTargetService $links = null,
		?IAgentRuntimeSelector $selector = null,
		?IAgentConfigFormService $forms = null
	): ChatbotConfigBranchProbe {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $requestValues[$key] ?? $default);
		$links ??= $this->createStub(ILinkTargetService::class);
		$selector ??= $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$forms ??= $this->createStub(IAgentConfigFormService::class);
		$forms->method('getDefaultSettings')->willReturn([]);
		return new ChatbotConfigBranchProbe(
			$this->createStub(IMvcView::class), $this->createStub(IAssetResolver::class), $request,
			$this->createStub(ISettingsStore::class), $links, $this->createStub(IClassMap::class),
			$forms, $this->createStub(IAgentRuntimeRegistry::class), $selector,
			$this->createStub(IAuthorizationService::class)
		);
	}
}

require_once __DIR__ . '/ChatbotConfigBranchProbe.php';
