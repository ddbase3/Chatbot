<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use Base3\Language\Api\ILanguage;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;

final class ChatbotOpeningMessageInputShapesTest extends TestCase {

	public function testSingleHeadingStringCanBeConfiguredWithoutAnArray(): void {
		$service = $this->service();
		$this->assertSame('Welcome & hello', $service->createHeading([
			'main_headings' => ' <b>Welcome</b> &amp; hello '
		]));
	}

	public function testHeadingsIgnoreInvalidNestedTypesInsteadOfStringifyingThem(): void {
		$service = $this->service();
		$this->assertSame('Valid welcome', $service->createHeading([
			'main_headings' => [[], new \stdClass(), false, null, ' ', '<strong>Valid welcome</strong>']
		]));
	}

	public function testCompletelyInvalidHeadingListReturnsAnEmptyHeading(): void {
		$this->assertSame('', $this->service()->createHeading([
			'main_headings' => [[], new \stdClass(), '', '   ', null]
		]));
	}

	public function testHeadingIsRestrictedToFiveHundredPlainTextCharacters(): void {
		$heading = $this->service()->createHeading(['main_headings' => '<p>' . str_repeat('a', 700) . '</p>']);
		$this->assertSame(str_repeat('a', 500), $heading);
	}

	public function testRandomOpeningMessageAcceptsAPlainStringAndPreservesParagraphBreaks(): void {
		$result = $this->service()->createAssistantMessage([
			'first_message_mode' => ' RANDOM ',
			'first_messages' => " <p>First</p>\r\nSecond paragraph "
		]);
		$this->assertSame("First\nSecond paragraph", $result);
	}

	public function testRandomMessagesIgnoreMalformedEntriesAndKeepTheValidMessage(): void {
		$result = $this->service()->createAssistantMessage([
			'first_message_mode' => 'random',
			'first_messages' => [new \stdClass(), ['invalid'], '', false, "<i>Hello</i>\nagain"]
		]);
		$this->assertSame("Hello\nagain", $result);
	}

	public function testUnknownMessageModeDoesNotCreateAnAssistantTurn(): void {
		$service = $this->service();
		$this->assertSame('none', $service->getFirstMessageMode(['first_message_mode' => 'automatic']));
		$this->assertSame('', $service->createAssistantMessage([
			'first_message_mode' => 'automatic',
			'first_messages' => ['Do not send this as an assistant turn']
		]));
	}

	private function service(): ChatbotOpeningMessageService {
		$tasks = $this->createMock(IAgentTextTaskService::class);
		$tasks->expects($this->never())->method('executeTextTask');
		return new ChatbotOpeningMessageService(
			$tasks,
			new ChatbotSettingsService(
				$this->createStub(ISettingsStore::class),
				$this->createStub(IAgentRuntimeSelector::class)
			),
			$this->createStub(ILanguage::class)
		);
	}
}
