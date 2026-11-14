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

use Base3\AssistantFoundation\Api\IAgentExecutionService;
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
use Base3\Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\TestCase;

final class AbstractChatbotServiceDefaultsTest extends TestCase {

	public function testBareServiceUsesTheAbstractBaseDiscoveryDefaults(): void {
		$service = $this->service();
		$this->assertSame('chatbotbareservicefixture', ChatbotBareServiceFixture::getName());
		$this->assertSame('chatbotbareservicefixture', ChatbotBareServiceFixture::getServiceLabel());
		$this->assertSame('', ChatbotBareServiceFixture::getServiceDescription());
		$this->assertSame('Help on AbstractChatbotService.', $service->getHelp());
	}

	public function testBasePromptIsReturnedWithoutExecutingTheAgent(): void {
		$agent = $this->createMock(IAgentExecutionService::class);
		$agent->expects($this->never())->method('execute');
		$this->assertSame('Fixture base prompt', $this->service(['baseprompt' => '1'], $agent)->getOutput());
	}

	public function testEmptyTurnNeverStartsAnAgentExecution(): void {
		$agent = $this->createMock(IAgentExecutionService::class);
		$agent->expects($this->never())->method('execute');
		$this->assertSame('', $this->service([], $agent)->getOutput());
	}

	public function testBasePromptTakesPriorityOverSuggestionsWithoutExecutingTheAgent(): void {
		$agent = $this->createMock(IAgentExecutionService::class);
		$agent->expects($this->never())->method('execute');
		$this->assertSame('Fixture base prompt', $this->service(['suggestions' => '1', 'baseprompt' => '1'], $agent)->getOutput());
	}

	public function testStringSettingsUseDefaultForMissingOrNonScalarEntries(): void {
		$service = $this->service();
		$this->assertSame('fallback', $service->inspectSettingString([], 'missing', 'fallback'));
		$this->assertSame('one', $service->inspectSettingString(['value' => ' one '], 'value', 'fallback'));
		$this->assertSame('42', $service->inspectSettingString(['value' => 42], 'value'));
		$this->assertSame('', $service->inspectSettingString(['value' => null], 'value', 'fallback'));
		$this->assertSame('fallback', $service->inspectSettingString(['value' => ['nested']], 'value', 'fallback'));
	}

	public function testBooleanSettingsHaveExactCanonicalTrueValues(): void {
		$service = $this->service();
		foreach ([true, 1, '1', ' TRUE ', 'yes', 'On'] as $value) {
			$this->assertTrue($service->inspectSettingBool(['enabled' => $value], 'enabled'));
		}
		foreach ([false, 0, 2, 'false', 'off', 'no', null] as $value) {
			$this->assertFalse($service->inspectSettingBool(['enabled' => $value], 'enabled'));
		}
	}

	public function testBooleanSettingsDoNotCoerceStructuredValuesToTrue(): void {
		$service = $this->service();
		$this->assertFalse($service->inspectSettingBool([], 'enabled'));
		$this->assertTrue($service->inspectSettingBool([], 'enabled', true));
		$this->assertTrue($service->inspectSettingBool(['enabled' => []], 'enabled', true));
		$this->assertFalse($service->inspectSettingBool(['enabled' => new \stdClass()], 'enabled'));
	}

	public function testAgentInputsIncludeResumeOnlyWhenItWasProvided(): void {
		$service = $this->service();
		$this->assertSame([
			'system' => 'system', 'prompt' => 'hello', 'mode' => 'chat'
		], $service->inspectAgentInputs('system', 'hello'));
		$this->assertSame([
			'system' => 'system', 'prompt' => '', 'mode' => 'chat',
			'resume' => ['handle' => 'resume-1']
		], $service->inspectAgentInputs('system', '', ['handle' => 'resume-1']));
	}

	public function testBaseAgentSettingsDoNotInventFlowWhenNoFlowIsConfigured(): void {
		$service = $this->service();
		$this->assertSame(['chatbot_backend' => 'service:dummychatbotservice'], $service->inspectAgentConfiguration([
			'chatbot_backend' => 'service:dummychatbotservice'
		]));
		$this->assertSame(['agent_flow' => ['nodes' => []]], $service->inspectAgentConfiguration([
			'agent_flow' => ['nodes' => []]
		]));
	}

	public function testSystemAndSuggestionPromptsUseBaseDefaultsWithoutFiles(): void {
		$service = $this->service();
		$this->assertSame('You are a helpful assistant.', $service->inspectSystemPrompt([]));
		$this->assertSame('Suggest three prompts.', $service->inspectSuggestionPrompt([]));
		$this->assertSame('Use these guidelines.', $service->inspectSystemPrompt(['system_prompt' => ' Use these guidelines. ']));
		$this->assertSame('Offer questions.', $service->inspectSuggestionPrompt(['suggestion_system_prompt' => ' Offer questions. ']));
	}

	private function service(array $values = [], ?IAgentExecutionService $agent = null): ChatbotBareServiceFixture {
		$request = $this->createStub(IRequest::class);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);
		$request->method('request')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $values[$key] ?? $default);
		$settingsStore = $this->createStub(ISettingsStore::class);
		$settings = new ChatbotSettingsService($settingsStore, $this->createStub(IAgentRuntimeSelector::class));
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([]);
		return new ChatbotBareServiceFixture(
			$request,
			$settings,
			$agent ?? $this->createStub(IAgentExecutionService::class),
			new ChatbotTurnRequestFactory($request),
			new ChatbotTurnResponder(
				$this->createStub(ILanguage::class),
				new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
				$this->createStub(ILogger::class)
			),
			new ChatbotConversationChannelResolver(),
			new ChatbotExtensionService(new ChatbotExtensionRegistry($map), $settingsStore)
		);
	}
}

require_once __DIR__ . '/ChatbotBareServiceFixture.php';
