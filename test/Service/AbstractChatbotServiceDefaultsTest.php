<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IClassMap;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnRequestFactory;
use Chatbot\Service\ChatbotTurnResponder;
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
