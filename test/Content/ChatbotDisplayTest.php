<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use PHPUnit\Framework\TestCase;
use Chatbot\Content\ChatbotDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IAssetResolver;

class ChatbotDisplayTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('chatbotdisplay', ChatbotDisplay::getName());
	}

	public function testGetHelpReturnsString(): void {
		$view = new FakeMvcView();
		$resolver = new FakeAssetResolver();

		$display = new ChatbotDisplay($view, $resolver);

		$this->assertSame('Display a configurable Chatbot widget.', $display->getHelp());
	}

	public function testGetOutputSetsViewPathAndTemplateAndAssignsDefaults(): void {
		$view = new FakeMvcView();
		$resolver = new FakeAssetResolver();

		$display = new ChatbotDisplay($view, $resolver);

		$html = $display->getOutput('html');

		$this->assertSame(DIR_PLUGIN . 'Chatbot', $view->getLastPath());
		$this->assertSame('Content/ChatbotDisplay.php', $view->getLastTemplate());

		// Defaults
		$this->assertSame('chatbotservice.php', $view->getAssigned('service'));
		$this->assertTrue($view->getAssigned('use_markdown'));
		$this->assertTrue($view->getAssigned('use_icons'));
		$this->assertTrue($view->getAssigned('use_voice'));
		$this->assertSame('auto', $view->getAssigned('transport_mode'));
		$this->assertSame('auto', $view->getAssigned('default_lang'));

		// Resolve callback
		$resolve = $view->getAssigned('resolve');
		$this->assertIsCallable($resolve);
		$this->assertSame('/resolved/foo.js', $resolve('foo.js'));

		// Our fake renderer returns a deterministic string so we can assert it was called
		$this->assertSame('FAKE_TEMPLATE_OUTPUT', $html);
	}

	public function testSetDataOverridesDefaults(): void {
		$view = new FakeMvcView();
		$resolver = new FakeAssetResolver();

		$display = new ChatbotDisplay($view, $resolver);

		$display->setData([
			'service' => '/api/chat',
			'use_markdown' => false,
			'transport_mode' => 'sse',
			'default_lang' => 'de'
		]);

		$display->getOutput('html');

		$this->assertSame('/api/chat', $view->getAssigned('service'));
		$this->assertFalse($view->getAssigned('use_markdown'));
		$this->assertSame('sse', $view->getAssigned('transport_mode'));
		$this->assertSame('de', $view->getAssigned('default_lang'));

		// Defaults that were not overridden must stay intact
		$this->assertTrue($view->getAssigned('use_icons'));
		$this->assertTrue($view->getAssigned('use_voice'));
	}

	public function testGetSchemaContainsExpectedShape(): void {
		$view = new FakeMvcView();
		$resolver = new FakeAssetResolver();

		$display = new ChatbotDisplay($view, $resolver);

		$schema = $display->getSchema();

		$this->assertIsArray($schema);
		$this->assertSame('https://json-schema.org/draft-2020-12/schema', $schema['$schema'] ?? null);
		$this->assertSame('object', $schema['type'] ?? null);

		$properties = $schema['properties'] ?? [];
		$this->assertIsArray($properties);

		$this->assertArrayHasKey('service', $properties);
		$this->assertSame('string', $properties['service']['type'] ?? null);

		$this->assertArrayHasKey('transport_mode', $properties);
		$this->assertSame(['auto', 'sse', 'websocket', 'rest'], $properties['transport_mode']['enum'] ?? null);

		$required = $schema['required'] ?? [];
		$this->assertContains('service', $required);
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

	public function loadBricks(string $set, string $language = ''): void {
		// Not needed for this display test
	}

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
