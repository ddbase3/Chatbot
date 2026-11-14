<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of Chatbot for BASE3 Framework.
 *
 * Chatbot extends the BASE3 framework with a modular API
 * foundation for flow-based chatbot services and interfaces.
 * It provides reusable components for AI-driven conversations.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/chatbot
 * https://github.com/ddbase3/Chatbot
 **********************************************************************/

namespace Base3\Chatbot\Test\Service;

use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Dto\AgentTextTaskResult;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
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
