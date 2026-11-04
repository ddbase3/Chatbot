<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentTextTaskResult;
use Base3\Language\Api\ILanguage;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotOpeningMessageServiceAdditionalTest extends TestCase {

	public function testUnknownFirstMessageModeDefaultsToNone(): void {
		$service = $this->service();
		$this->assertSame('none', $service->getFirstMessageMode(['first_message_mode' => 'unknown']));
		$this->assertSame('random', $service->getFirstMessageMode(['first_message_mode' => ' Random ']));
		$this->assertSame('contextual_ai', $service->getFirstMessageMode(['first_message_mode' => 'CONTEXTUAL_AI']));
		$this->assertSame('', $service->createAssistantMessage(['first_message_mode' => 'unknown']));
	}

	public function testHeadingDecodesMarkupAndWhitespaceAndTruncatesToFiveHundredCharacters(): void {
		$service = $this->service();
		$this->assertSame('Welcome & enjoy', $service->createHeading(['main_headings' => ['<b>Welcome</b> &amp; enjoy']]));
		$this->assertSame(str_repeat('x', 500), $service->createHeading(['main_headings' => [str_repeat('x', 700)]]));
		$this->assertSame('', $service->createHeading(['main_headings' => [null, [], '  ']]));
	}

	public function testRandomMessagePreservesNewlinesAndSanitizesHtmlMarkup(): void {
		$service = $this->service();
		$this->assertSame("Hello & welcome\nSecond line", $service->createAssistantMessage([
			'first_message_mode' => 'random',
			'first_messages' => [" <b>Hello</b> &amp; welcome\r\nSecond *line* "]
		]));
	}

	public function testEmptyRandomSelectionIsRejected(): void {
		$service = $this->service();
		$this->expectException(RuntimeException::class);
		$service->createAssistantMessage(['first_message_mode' => 'random', 'first_messages' => [null, [], '  ']]);
	}

	public function testContextualModeRejectsEmptyTextTaskResult(): void {
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturn(new AgentTextTaskResult('  <b> </b>  '));
		$this->expectException(RuntimeException::class);
		$this->service($text)->createAssistantMessage(['first_message_mode' => 'contextual_ai', 'default_lang' => 'en']);
	}

	private function service(?IAgentTextTaskService $text = null): ChatbotOpeningMessageService {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('en');
		return new ChatbotOpeningMessageService(
			$text ?? $this->createStub(IAgentTextTaskService::class),
			new ChatbotSettingsService($this->createStub(ISettingsStore::class), $selector),
			$language
		);
	}
}
