<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Content\ChatbotDisplay;
use PHPUnit\Framework\TestCase;
use UiFoundation\Api\IChatbotDisplay;

class ChatbotDisplayTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('chatbotdisplay', ChatbotDisplay::getName());
	}

	public function testGetHelpReturnsString(): void {
		$display = $this->createDisplay(new FakeChatbotDisplay());

		$this->assertSame('Display a configurable Chatbot widget.', $display->getHelp());
	}

	public function testGetOutputUsesHostDefaultRuntime(): void {
		$chatbotDisplay = new FakeChatbotDisplay();
		$display = $this->createDisplay($chatbotDisplay, 'neuronai');

		$html = $display->getOutput('html', true);
		$config = $chatbotDisplay->getData();

		$this->assertSame('runtime:neuronai', $config['chatbot_backend'] ?? null);
		$this->assertSame('chatbotservice', $config['service'] ?? null);
		$this->assertSame('/service/chatbotservice', $config['service_url'] ?? null);
		$this->assertSame('/service/chatbotturnprepare', $config['turn_prepare_url'] ?? null);
		$this->assertTrue($config['use_markdown'] ?? false);
		$this->assertFalse($config['use_mathjax'] ?? true);
		$this->assertTrue($config['use_icons'] ?? false);
		$this->assertTrue($config['use_voice'] ?? false);
		$this->assertTrue($config['use_threads'] ?? false);
		$this->assertSame('auto', $config['transport_mode'] ?? null);
		$this->assertSame('auto', $config['default_lang'] ?? null);
		$this->assertSame('html', $chatbotDisplay->getLastOutputFormat());
		$this->assertTrue($chatbotDisplay->isLastOutputFinal());
		$this->assertSame('FAKE_CHATBOT_OUTPUT', $html);
	}

	public function testStoredBackendOverridesHostDefaultRuntime(): void {
		$chatbotDisplay = new FakeChatbotDisplay();
		$display = $this->createDisplay($chatbotDisplay, 'missionbay', [
			'chatbot_backend' => 'service:dummychatbotservice',
			'transport_mode' => 'rest'
		]);
		$display->setData([
			'config_group' => 'chatbot',
			'config_name' => 'stored-dummy'
		]);

		$display->getOutput('html');
		$config = $chatbotDisplay->getData();

		$this->assertSame('service:dummychatbotservice', $config['chatbot_backend'] ?? null);
		$this->assertSame('dummychatbotservice', $config['service'] ?? null);
		$this->assertSame('/service/dummychatbotservice?config_group=chatbot&config_name=stored-dummy', $config['service_url'] ?? null);
		$this->assertSame('rest', $config['transport_mode'] ?? null);
	}

	public function testLegacyDirectServiceIsResolvedBeforeDefaultBackend(): void {
		$chatbotDisplay = new FakeChatbotDisplay();
		$display = $this->createDisplay($chatbotDisplay, 'missionbay');
		$display->setData([
			'service' => 'dummychatbotservice'
		]);

		$display->getOutput('html');
		$config = $chatbotDisplay->getData();

		$this->assertSame('service:dummychatbotservice', $config['chatbot_backend'] ?? null);
		$this->assertSame('dummychatbotservice', $config['service'] ?? null);
	}

	public function testDirectBackendOverridesAgentRuntime(): void {
		$chatbotDisplay = new FakeChatbotDisplay();
		$display = $this->createDisplay($chatbotDisplay);
		$display->setData([
			'chatbot_backend' => 'service:dummychatbotservice',
			'use_markdown' => false,
			'use_mathjax' => true,
			'transport_mode' => 'rest',
			'default_lang' => 'de-DE',
			'speech_to_text_service' => 'mistral-realtime',
			'text_to_speech_service' => 'openai-default',
			'config_group' => 'chatbot-three',
			'config_name' => 'floating'
		]);

		$display->getOutput('html');
		$config = $chatbotDisplay->getData();

		$this->assertSame('service:dummychatbotservice', $config['chatbot_backend'] ?? null);
		$this->assertSame('dummychatbotservice', $config['service'] ?? null);
		$this->assertSame('/service/dummychatbotservice?config_group=chatbot-three&config_name=floating', $config['service_url'] ?? null);
		$this->assertFalse($config['use_markdown'] ?? true);
		$this->assertTrue($config['use_mathjax'] ?? false);
		$this->assertSame('rest', $config['transport_mode'] ?? null);
		$this->assertSame('de-DE', $config['default_lang'] ?? null);
		$this->assertSame('mistral-realtime', $config['speech_to_text_service'] ?? null);
		$this->assertSame('/service/realtimespeechtotextsession?service=mistral-realtime', $config['speech_to_text_session_url'] ?? null);
		$this->assertSame('openai-default', $config['text_to_speech_service'] ?? null);
		$this->assertSame('/service/texttospeech?config_group=chatbot-three&config_name=floating', $config['text_to_speech_url'] ?? null);
		$this->assertTrue($config['use_icons'] ?? false);
		$this->assertTrue($config['use_voice'] ?? false);
	}

	public function testGetSchemaUsesHostDefaultBackend(): void {
		$display = $this->createDisplay(new FakeChatbotDisplay(), 'neuronai');
		$schema = $display->getSchema();
		$properties = $schema['properties'] ?? [];

		$this->assertSame('https://json-schema.org/draft-2020-12/schema', $schema['$schema'] ?? null);
		$this->assertSame('object', $schema['type'] ?? null);
		$this->assertArrayHasKey('chatbot_backend', $properties);
		$this->assertSame('runtime:neuronai', $properties['chatbot_backend']['default'] ?? null);
		$this->assertSame(['auto', 'sse', 'rest'], $properties['transport_mode']['enum'] ?? null);
		$this->assertArrayHasKey('use_mathjax', $properties);
		$this->assertFalse($properties['use_mathjax']['default'] ?? true);
		$this->assertArrayHasKey('text_to_speech_service', $properties);
		$this->assertContains('chatbot_backend', $schema['required'] ?? []);
	}

	/** @param array<string,mixed> $storedSettings */
	private function createDisplay(
		FakeChatbotDisplay $chatbotDisplay,
		string $defaultRuntime = 'missionbay',
		array $storedSettings = []
	): ChatbotDisplay {
		$linkTargetService = $this->createStub(ILinkTargetService::class);
		$linkTargetService->method('getLink')->willReturnCallback(
			static function(array $target, array $params = []): string {
				$url = '/service/' . (string)($target['name'] ?? '');
				return $params === [] ? $url : $url . '?' . http_build_query($params);
			}
		);
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settingsStore->method('get')->willReturn($storedSettings);
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('getDefaultRuntimeId')->willReturn($defaultRuntime);

		return new ChatbotDisplay(
			$chatbotDisplay,
			$linkTargetService,
			$settingsStore,
			$runtimeSelector
		);
	}
}

class FakeChatbotDisplay implements IChatbotDisplay {

	private array $data = [];
	private string $lastOutputFormat = '';
	private bool $lastOutputFinal = false;

	public static function getName(): string {
		return 'fakechatbotdisplay';
	}

	public function setData($data): void {
		$this->data = is_array($data) ? $data : [];
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$this->lastOutputFormat = $out;
		$this->lastOutputFinal = $final;

		return 'FAKE_CHATBOT_OUTPUT';
	}

	public function getHelp(): string {
		return 'Fake chatbot display.';
	}

	public function getData(): array {
		return $this->data;
	}

	public function getLastOutputFormat(): string {
		return $this->lastOutputFormat;
	}

	public function isLastOutputFinal(): bool {
		return $this->lastOutputFinal;
	}
}
