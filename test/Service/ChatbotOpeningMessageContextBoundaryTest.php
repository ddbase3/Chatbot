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
use Base3\AssistantFoundation\Dto\AgentTextTaskRequest;
use Base3\AssistantFoundation\Dto\AgentTextTaskResult;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotOpeningMessageContextBoundaryTest extends TestCase {

	public function testConfiguredLanguageWinsOverHostLocale(): void {
		$captured = null;
		$service = $this->newOpeningService('de-DE', $captured);
		$this->assertSame('Welcome', $service->createAssistantMessage([
			'first_message_mode' => 'contextual_ai', 'default_lang' => ' en-GB '
		]));
		$this->assertSame('en-GB', $captured->getContext()['language']);
		$this->assertStringContainsString('Language: en-GB', $captured->getPrompt());
	}

	public function testAutomaticLanguageUsesTranslatedHostLocale(): void {
		$captured = null;
		$service = $this->newOpeningService('fr-FR', $captured);
		$this->assertSame('Welcome', $service->createAssistantMessage([
			'first_message_mode' => 'contextual_ai', 'default_lang' => 'auto'
		]));
		$this->assertSame('fr-FR', $captured->getContext()['language']);
	}

	public function testEmptyHostLocaleFallsBackToEnglishForContextualMessage(): void {
		$captured = null;
		$service = $this->newOpeningService('', $captured);
		$this->assertSame('Welcome', $service->createAssistantMessage([
			'first_message_mode' => 'contextual_ai'
		]));
		$this->assertSame('en', $captured->getContext()['language']);
	}

	public function testContextIncludesCurrentDateReferenceAndRequestedProfiles(): void {
		$captured = null;
		$service = $this->newOpeningService('de', $captured);
		$reference = ['course' => ['id' => 42], 'path' => '/kurse/42'];
		$this->assertSame('Welcome', $service->createAssistantMessage([
			'first_message_mode' => 'contextual_ai', 'chatbot_backend' => 'runtime:missionbay'
		], $reference));
		$this->assertSame('chat-opening-message', $captured->getTaskName());
		$this->assertTrue($captured->shouldIncludeContextProfile());
		$this->assertTrue($captured->shouldIncludeToolProfile());
		$this->assertSame('missionbay', $captured->getAgentConfiguration()['agent_runtime']);
		$this->assertSame($reference, $captured->getContext()['reference']);
		$this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $captured->getContext()['current_date']));
		$this->assertSame(1, preg_match('/^\d{2}:\d{2}:\d{2}$/', $captured->getContext()['current_time']));
		$this->assertStringContainsString('"course":{"id":42}', $captured->getPrompt());
	}

	public function testContextualResultBecomesPlainTextWithFiveHundredCharacterLimit(): void {
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturn(new AgentTextTaskResult('<p>**Hello** &amp; welcome</p> ' . str_repeat('x', 600)));
		$service = $this->withTextService($text, 'en');
		$result = $service->createAssistantMessage(['first_message_mode' => 'contextual_ai']);
		$this->assertStringContainsString('Hello & welcome', $result);
		$this->assertSame(500, strlen($result));
		$this->assertStringNotContainsString('<p>', $result);
		$this->assertStringNotContainsString('**', $result);
	}

	public function testInvalidReferenceEncodingNeverLeaksBrokenJsonIntoModelPrompt(): void {
		$captured = null;
		$service = $this->newOpeningService('en', $captured);
		$this->assertSame('Welcome', $service->createAssistantMessage([
			'first_message_mode' => 'contextual_ai'
		], ['input' => "invalid\xC3byte"]));
		$this->assertStringContainsString('Current page context: {}', $captured->getPrompt());
	}

	public function testTextTaskFailurePropagatesInsteadOfFabricatingAnOpeningMessage(): void {
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturnCallback(static function(): never {
			throw new RuntimeException('Text task provider failed.');
		});
		$this->expectException(RuntimeException::class);
		$this->withTextService($text, 'de')->createAssistantMessage(['first_message_mode' => 'contextual_ai']);
	}

	private function newOpeningService(string $locale, mixed &$captured): ChatbotOpeningMessageService {
		$text = $this->createStub(IAgentTextTaskService::class);
		$text->method('executeTextTask')->willReturnCallback(static function(AgentTextTaskRequest $request) use (&$captured): AgentTextTaskResult {
			$captured = $request;
			return new AgentTextTaskResult('Welcome');
		});
		return $this->withTextService($text, $locale);
	}

	private function withTextService(IAgentTextTaskService $text, string $locale): ChatbotOpeningMessageService {
		$selector = $this->createStub(IAgentRuntimeSelector::class);
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn($locale);
		return new ChatbotOpeningMessageService(
			$text, new ChatbotSettingsService($this->createStub(ISettingsStore::class), $selector), $language
		);
	}
}
