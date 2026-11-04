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
use Chatbot\Content\ChatbotConfigDisplay;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class ChatbotConfigDisplayMethodContractsTest extends TestCase {

	public function testDefaultSettingsAreMergedWithCanonicalAgentFormDefaults(): void {
		$forms = $this->createStub(IAgentConfigFormService::class);
		$forms->method('getDefaultSettings')->willReturn(['memory_profile' => 'memory-1', 'agent_runtime' => 'runtime-1']);
		$settings = $this->invoke($this->display(null, null, null, $forms), 'getDefaultSettings');
		$this->assertSame('runtime:missionbay', $settings['chatbot_backend']);
		$this->assertSame('memory-1', $settings['memory_profile']);
		$this->assertSame('runtime-1', $settings['agent_runtime']);
	}

	public function testSetDataResetsOldMessagesAndCachedSettings(): void {
		$display = $this->display();
		$this->invoke($display, 'addMessage', 'danger', 'Invalid first request');
		$this->assertCount(1, (new ReflectionProperty(ChatbotConfigDisplay::class, 'messages'))->getValue($display));
		$display->setData(['group' => 'chatbot', 'name' => 'fresh']);
		$this->assertSame([], (new ReflectionProperty(ChatbotConfigDisplay::class, 'messages'))->getValue($display));
		$this->assertNull((new ReflectionProperty(ChatbotConfigDisplay::class, 'postedSettings'))->getValue($display));
		$this->assertNull((new ReflectionProperty(ChatbotConfigDisplay::class, 'postedValues'))->getValue($display));
	}

	public function testTranslationsUseTrimmedValuesAndFallbackForEmptyOrNonScalarValues(): void {
		$display = $this->display();
		(new ReflectionProperty(ChatbotConfigDisplay::class, 'translations'))->setValue($display, [
			'title' => '  Configuración  ', 'blank' => '  ', 'invalid' => ['array']
		]);
		$this->assertSame('Configuración', $this->invoke($display, 'translate', 'title', 'Fallback'));
		$this->assertSame('Fallback', $this->invoke($display, 'translate', 'blank', 'Fallback'));
		$this->assertSame('Fallback', $this->invoke($display, 'translate', 'invalid', 'Fallback'));
		$this->assertSame('Fallback', $this->invoke($display, 'translate', 'missing', 'Fallback'));
	}

	public function testViewTranslationsComeOnlyFromExistingChatbotConfigurationBricks(): void {
		$view = $this->createStub(IMvcView::class);
		$view->method('getBricks')->willReturn(['save' => '  Speichern  ', 'empty' => '']);
		$display = $this->display(null, null, $view);
		$this->invoke($display, 'prepareTranslations');
		$this->assertSame('Speichern', $this->invoke($display, 'translate', 'save', 'Save'));
		$this->assertSame('Fallback', $this->invoke($display, 'translate', 'empty', 'Fallback'));
	}

	public function testReferenceJsonPreservesUnicodeAndSlashes(): void {
		$display = $this->display();
		$this->assertSame('{}', $this->invoke($display, 'formatReferenceJson', []));
		$formatted = $this->invoke($display, 'formatReferenceJson', ['uri' => 'https://example.test/de/a/b', 'label' => 'Grüße']);
		$this->assertSame(['uri' => 'https://example.test/de/a/b', 'label' => 'Grüße'], json_decode($formatted, true));
		$this->assertStringContainsString('https://example.test/de/a/b', $formatted);
	}

	public function testReferenceJsonRejectsRecursiveStructuresWithoutExposingPartialData(): void {
		$display = $this->display();
		$reference = [];
		$reference['self'] = &$reference;
		$this->assertSame('{}', $this->invoke($display, 'formatReferenceJson', $reference));
	}

	public function testEnumNormalizationUsesOnlyExactAllowedKeys(): void {
		$display = $this->display();
		$this->assertSame('rest', $this->invoke($display, 'normalizeEnum', 'rest', ['auto', 'sse', 'rest'], 'auto'));
		$this->assertSame('auto', $this->invoke($display, 'normalizeEnum', 'REST', ['auto', 'sse', 'rest'], 'auto'));
		$this->assertSame('auto', $this->invoke($display, 'normalizeEnum', 'socket', ['auto', 'sse', 'rest'], 'auto'));
	}

	public function testTextBlocksKeepParagraphsAndNormalizeLineEndings(): void {
		$display = $this->display();
		$this->assertSame("First\n\nSecond\nThird", $this->invoke($display, 'normalizeTextBlock', "First\r\n\r\nSecond\rThird"));
		$this->assertSame('  Indented  ', $this->invoke($display, 'normalizeTextBlock', '  Indented  '));
	}

	public function testTechnicalKeysNormalizeWithoutInventingUnknownIdentifiers(): void {
		$display = $this->display();
		$this->assertSame('runtime_1.service-2', $this->invoke($display, 'normalizeTechnicalKey', ' RUNTIME_1.Service-2!! '));
		$this->assertSame('', $this->invoke($display, 'normalizeTechnicalKey', '???'));
	}

	public function testBooleanNormalizationDistinguishesIntegerOneFromOtherNonzeroValues(): void {
		$display = $this->display();
		foreach ([true, 1, '1', 'true', 'YES', 'On'] as $value) {
			$this->assertTrue($this->invoke($display, 'toBool', $value));
		}
		foreach ([false, 0, 2, -1, 'off', 'false', '2', ''] as $value) {
			$this->assertFalse($this->invoke($display, 'toBool', $value));
		}
	}

	public function testServiceLinkFailureDoesNotExposeInternalLinkException(): void {
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(): never { throw new RuntimeException('internal routing failed'); });
		$this->assertSame('', $this->invoke($this->display(null, $links), 'buildServiceUrl', 'chatbotservice', ['group' => 'chatbot', 'name' => 'sidebar']));
	}

	public function testServiceLinkReceivesInstanceIdentityWithoutAdditionalContext(): void {
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(function(array $target, array $params): string {
			$this->assertSame(['name' => 'chatbotservice'], $target);
			$this->assertSame(['config_group' => 'tenant', 'config_name' => 'sidebar'], $params);
			return '/chatbot/stream';
		});
		$this->assertSame('/chatbot/stream', $this->invoke($this->display(null, $links), 'buildServiceUrl', 'chatbotservice', [
			'group' => 'tenant', 'name' => 'sidebar', 'browser_id' => 'untrusted'
		]));
	}

	public function testEmptyBackendValidationProducesOneErrorWithoutCallingRegistry(): void {
		$runtime = $this->createMock(IAgentRuntimeRegistry::class);
		$runtime->expects($this->never())->method('hasRuntime');
		$errors = [];
		$display = $this->display(null, null, null, null, $runtime);
		$this->assertSame([], $this->invokeWithErrors($display, '', $errors));
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('select', strtolower($errors[0]));
	}

	public function testUnknownRuntimeProducesDistinctConfigurationError(): void {
		$runtime = $this->createStub(IAgentRuntimeRegistry::class);
		$runtime->method('hasRuntime')->willReturn(false);
		$errors = [];
		$display = $this->display(null, null, null, null, $runtime);
		$this->assertSame([], $this->invokeWithErrors($display, 'runtime:absent', $errors));
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('absent', $errors[0]);
	}

	public function testKnownRuntimeValidationReturnsItsCanonicalTypeAndId(): void {
		$runtime = $this->createStub(IAgentRuntimeRegistry::class);
		$runtime->method('hasRuntime')->willReturn(true);
		$errors = [];
		$this->assertSame(['type' => 'runtime', 'id' => 'known'], $this->invokeWithErrors($this->display(null, null, null, null, $runtime), 'runtime:known', $errors));
		$this->assertSame([], $errors);
	}

	private function invokeWithErrors(ChatbotConfigDisplay $display, string $backend, array &$errors): array {
		$method = new ReflectionMethod(ChatbotConfigDisplay::class, 'validateBackend');
		return $method->invokeArgs($display, [$backend, &$errors]);
	}

	private function invoke(ChatbotConfigDisplay $display, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod(ChatbotConfigDisplay::class, $method))->invoke($display, ...$args);
	}

	private function display(
		?ISettingsStore $store = null,
		?ILinkTargetService $links = null,
		?IMvcView $view = null,
		?IAgentConfigFormService $forms = null,
		?IAgentRuntimeRegistry $registry = null
	): ChatbotConfigDisplay {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$selector->method('getDefaultRuntimeId')->willReturn('missionbay');
		if ($forms === null) {
			$forms = $this->createStub(IAgentConfigFormService::class);
			$forms->method('getDefaultSettings')->willReturn([]);
		}
		return new ChatbotConfigDisplay(
			$view ?? $this->createStub(IMvcView::class),
			$this->createStub(IAssetResolver::class),
			$this->createStub(IRequest::class),
			$store ?? $this->createStub(ISettingsStore::class),
			$links ?? $this->createStub(ILinkTargetService::class),
			$this->createStub(IClassMap::class),
			$forms,
			$registry ?? $this->createStub(IAgentRuntimeRegistry::class),
			$selector,
			$this->createStub(IAuthorizationService::class)
		);
	}
}
