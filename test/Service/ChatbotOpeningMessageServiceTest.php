<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentTextTaskRequest;
use AssistantFoundation\Dto\AgentTextTaskResult;
use Base3\Language\Api\ILanguage;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;

final class ChatbotOpeningMessageServiceTest extends TestCase {

	public function testHeadingAndRandomFirstMessageAreIndependentAndDoNotExecuteTextTask(): void {
		$textTaskService = $this->createMock(IAgentTextTaskService::class);
		$textTaskService->expects($this->never())->method('executeTextTask');
		$service = $this->createService($textTaskService);

		$this->assertSame('', $service->createHeading([]));
		$this->assertSame('Headline', $service->createHeading([
			'main_headings' => ['Headline']
		]));
		$this->assertSame('', $service->createAssistantMessage([
			'first_message_mode' => 'none'
		]));
		$this->assertSame('Hello there', $service->createAssistantMessage([
			'first_message_mode' => 'random',
			'first_messages' => ['Hello there']
		]));
	}

	public function testRandomFirstMessagePreservesLineBreaksWithoutLengthLimit(): void {
		$textTaskService = $this->createMock(IAgentTextTaskService::class);
		$textTaskService->expects($this->never())->method('executeTextTask');
		$service = $this->createService($textTaskService);
		$message = "First paragraph.\n\nSecond paragraph.\n" . str_repeat('x', 800);

		$this->assertSame($message, $service->createAssistantMessage([
			'first_message_mode' => 'random',
			'first_messages' => [$message]
		]));
	}

	public function testContextualModeIncludesContextAndToolProfilesWithoutExecutingTools(): void {
		$textTaskService = $this->createMock(IAgentTextTaskService::class);
		$textTaskService->expects($this->once())
			->method('executeTextTask')
			->with($this->callback(static function(AgentTextTaskRequest $request): bool {
				return $request->getTaskName() === 'chat-opening-message'
					&& $request->shouldIncludeContextProfile()
					&& $request->shouldIncludeToolProfile()
					&& ($request->getContext()['reference']['url'] ?? null) === '/courses/42';
			}))
			->willReturn(new AgentTextTaskResult('**Ich helfe bei Kursen und Plugins.**'));

		$service = $this->createService($textTaskService);
		$this->assertSame('', $service->createHeading([
			'first_message_mode' => 'contextual_ai'
		]));

		$message = $service->createAssistantMessage([
			'chatbot_backend' => 'runtime:missionbay',
			'first_message_mode' => 'contextual_ai',
			'default_lang' => 'de',
			'context_profile' => 'page-context',
			'tool_profile' => 'admin-tools'
		], [
			'url' => '/courses/42'
		]);

		$this->assertSame('Ich helfe bei Kursen und Plugins.', $message);
	}

	private function createService(IAgentTextTaskService $textTaskService): ChatbotOpeningMessageService {
		$runtimeSelector = $this->createStub(IAgentRuntimeSelector::class);
		$runtimeSelector->method('getDefaultRuntimeId')->willReturn('missionbay');
		$language = $this->createStub(ILanguage::class);
		$language->method('getLanguage')->willReturn('de');

		return new ChatbotOpeningMessageService(
			$textTaskService,
			new ChatbotSettingsService($this->createStub(ISettingsStore::class), $runtimeSelector),
			$language
		);
	}
}
