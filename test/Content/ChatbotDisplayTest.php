<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IAssetResolver;
use Base3\Api\IMvcView;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Content\ChatbotDisplay;
use PHPUnit\Framework\TestCase;

class ChatbotDisplayTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('chatbotdisplay', ChatbotDisplay::getName());
	}

	public function testGetHelpReturnsString(): void {
		$display = $this->createDisplay(new FakeMvcView());

		$this->assertSame('Display a configurable Chatbot widget.', $display->getHelp());
	}

	public function testGetOutputUsesHostDefaultRuntime(): void {
		$view = new FakeMvcView();
		$display = $this->createDisplay($view, 'neuronai');

		$html = $display->getOutput('html');

		$this->assertSame(DIR_PLUGIN . 'Chatbot', $view->getLastPath());
		$this->assertSame('Content/ChatbotDisplay.php', $view->getLastTemplate());
		$this->assertSame('runtime:neuronai', $view->getAssigned('chatbot_backend'));
		$this->assertSame('chatbotservice', $view->getAssigned('service'));
		$this->assertSame('/service/chatbotservice', $view->getAssigned('service_url'));
		$this->assertSame('/service/chatbotturnprepare', $view->getAssigned('turn_prepare_url'));
		$this->assertTrue($view->getAssigned('use_markdown'));
		$this->assertTrue($view->getAssigned('use_icons'));
		$this->assertTrue($view->getAssigned('use_voice'));
		$this->assertSame('auto', $view->getAssigned('transport_mode'));
		$this->assertSame('auto', $view->getAssigned('default_lang'));

		$resolve = $view->getAssigned('resolve');
		$this->assertIsCallable($resolve);
		$this->assertSame('/resolved/foo.js', $resolve('foo.js'));
		$this->assertSame('FAKE_TEMPLATE_OUTPUT', $html);
	}

	public function testStoredBackendOverridesHostDefaultRuntime(): void {
		$view = new FakeMvcView();
		$display = $this->createDisplay($view, 'missionbay', [
			'chatbot_backend' => 'service:dummychatbotservice',
			'transport_mode' => 'rest'
		]);
		$display->setData([
			'config_group' => 'chatbot',
			'config_name' => 'stored-dummy'
		]);

		$display->getOutput('html');

		$this->assertSame('service:dummychatbotservice', $view->getAssigned('chatbot_backend'));
		$this->assertSame('dummychatbotservice', $view->getAssigned('service'));
		$this->assertSame('/service/dummychatbotservice', $view->getAssigned('service_url'));
		$this->assertSame('rest', $view->getAssigned('transport_mode'));
	}

	public function testLegacyDirectServiceIsResolvedBeforeDefaultBackend(): void {
		$view = new FakeMvcView();
		$display = $this->createDisplay($view, 'missionbay');
		$display->setData([
			'service' => 'dummychatbotservice'
		]);

		$display->getOutput('html');

		$this->assertSame('service:dummychatbotservice', $view->getAssigned('chatbot_backend'));
		$this->assertSame('dummychatbotservice', $view->getAssigned('service'));
	}

	public function testDirectBackendOverridesAgentRuntime(): void {
		$view = new FakeMvcView();
		$display = $this->createDisplay($view);
		$display->setData([
			'chatbot_backend' => 'service:dummychatbotservice',
			'use_markdown' => false,
			'transport_mode' => 'rest',
			'default_lang' => 'de-DE'
		]);

		$display->getOutput('html');

		$this->assertSame('service:dummychatbotservice', $view->getAssigned('chatbot_backend'));
		$this->assertSame('dummychatbotservice', $view->getAssigned('service'));
		$this->assertSame('/service/dummychatbotservice', $view->getAssigned('service_url'));
		$this->assertFalse($view->getAssigned('use_markdown'));
		$this->assertSame('rest', $view->getAssigned('transport_mode'));
		$this->assertSame('de-DE', $view->getAssigned('default_lang'));
		$this->assertTrue($view->getAssigned('use_icons'));
		$this->assertTrue($view->getAssigned('use_voice'));
	}

	public function testGetSchemaUsesHostDefaultBackend(): void {
		$display = $this->createDisplay(new FakeMvcView(), 'neuronai');
		$schema = $display->getSchema();
		$properties = $schema['properties'] ?? [];

		$this->assertSame('https://json-schema.org/draft-2020-12/schema', $schema['$schema'] ?? null);
		$this->assertSame('object', $schema['type'] ?? null);
		$this->assertArrayHasKey('chatbot_backend', $properties);
		$this->assertSame('runtime:neuronai', $properties['chatbot_backend']['default'] ?? null);
		$this->assertSame(['auto', 'sse', 'rest'], $properties['transport_mode']['enum'] ?? null);
		$this->assertContains('chatbot_backend', $schema['required'] ?? []);
	}

	/** @param array<string,mixed> $storedSettings */
	private function createDisplay(
		FakeMvcView $view,
		string $defaultRuntime = 'missionbay',
		array $storedSettings = []
	): ChatbotDisplay {
		$linkTargetService = $this->createStub(ILinkTargetService::class);
		$linkTargetService->method('getLink')->willReturnCallback(
			static fn(array $target): string => '/service/' . (string)($target['name'] ?? '')
		);
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn($storedSettings);
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('getDefaultRuntimeId')->willReturn($defaultRuntime);

		return new ChatbotDisplay(
			$view,
			new FakeAssetResolver(),
			$linkTargetService,
			$settingsStore,
			$runtimeSelector
		);
	}
}

class FakeAssetResolver implements IAssetResolver {

	public function resolve(string $path): string {
		return '/resolved/' . $path;
	}
}

class FakeMvcView implements IMvcView {

	private ?string $lastPath = null;
	private ?string $lastTemplate = null;
	private array $assigned = [];

	public function setPath(string $path = '.'): void {
		$this->lastPath = $path;
	}

	public function assign(string $key, $value): void {
		$this->assigned[$key] = $value;
	}

	public function setTemplate(string $template = 'default'): void {
		$this->lastTemplate = $template;
	}

	public function loadTemplate(): string {
		return 'FAKE_TEMPLATE_OUTPUT';
	}

	public function loadBricks(string $set, string $language = ''): void {}

	public function getBricks(string $set): ?array {
		return null;
	}

	public function getLastPath(): ?string {
		return $this->lastPath;
	}

	public function getLastTemplate(): ?string {
		return $this->lastTemplate;
	}

	public function getAssigned(string $tag): mixed {
		return $this->assigned[$tag] ?? null;
	}
}
