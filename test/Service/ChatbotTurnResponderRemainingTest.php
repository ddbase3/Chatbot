<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Api\IAgentEventSink;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\State\Api\IStateStore;
use Chatbot\Api\IChatbotService;
use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\ChatbotTurnResult;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnResponder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotTurnResponderRemainingTest extends TestCase {

	public function testRestReturnsMessageWithIdAndUnicodeContent(): void {
		$service = $this->createMock(IChatbotService::class);
		$service->expects($this->once())->method('executeTurn')->willReturnCallback(function(ChatbotTurnRequest $request, IAgentEventSink $sink): ChatbotTurnResult {
			$this->assertSame('Hello', $request->getPrompt());
			$this->assertFalse($sink->isCancelled());
			return ChatbotTurnResult::message('message-1', 'Grüße ✅');
		});
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->never())->method('error');
		$data = json_decode($this->makeResponder($logger)->respondRest($service, new ChatbotTurnRequest(['prompt' => 'Hello'])), true);
		$this->assertSame('message', $data['type']);
		$this->assertSame('message-1', $data['id']);
		$this->assertSame('Grüße ✅', $data['text']);
	}

	public function testRestCancelledResultIsNotReinterpretedAsNormalMessage(): void {
		$service = $this->createStub(IChatbotService::class);
		$service->method('executeTurn')->willReturn(ChatbotTurnResult::cancelled());
		$data = json_decode($this->makeResponder()->respondRest($service, new ChatbotTurnRequest(['prompt' => 'Cancel'])), true);
		$this->assertSame('cancelled', $data['type']);
		$this->assertSame('cancelled', $data['status']);
	}

	public function testRestRetainsInteractionResumeData(): void {
		$service = $this->createStub(IChatbotService::class);
		$service->method('executeTurn')->willReturn(ChatbotTurnResult::interactionRequired(
			'awaiting_approval', 'resume-token', [['id' => 'approval-1', 'kind' => 'approval']], '2026-11-04T10:00:00Z'
		));
		$data = json_decode($this->makeResponder()->respondRest($service, new ChatbotTurnRequest(['prompt' => 'Approve?'])), true);
		$this->assertSame('interaction_required', $data['type']);
		$this->assertSame('resume-token', $data['resume_handle']);
		$this->assertSame('approval-1', $data['interaction_requests'][0]['id']);
	}

	public function testRestServiceFailureIsControlledAndLoggedWithContext(): void {
		$error = new RuntimeException('Remote model unavailable');
		$service = $this->createStub(IChatbotService::class);
		$service->method('executeTurn')->willReturnCallback(static function() use ($error): never { throw $error; });
		$captured = [];
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())->method('error')->willReturnCallback(function(string $message, array $context) use (&$captured): void {
			$captured = [$message, $context];
		});
		$data = json_decode($this->makeResponder($logger)->respondRest($service, new ChatbotTurnRequest([
			'config_group' => 'group', 'config_name' => 'name', 'prompt' => 'test'
		])), true);
		$this->assertSame('error', $data['type']);
		$this->assertStringContainsString('Remote model unavailable', $data['text']);
		$this->assertStringContainsString('config=group/name', $captured[0]);
		$this->assertSame('chatbot', $captured[1]['scope']);
		$this->assertSame($error, $captured[1]['exception']);
	}

	public function testUserMessageUsesGermanLocaleWithRegionalSuffix(): void {
		$this->assertSame(
			'Es ist ein technischer Fehler aufgetreten. Die Anfrage konnte nicht vollständig abgeschlossen werden.',
			$this->makeResponder(language: 'de-DE')->getUserMessage('runtime_error', 'fallback')
		);
	}

	public function testUnknownLocaleFallsBackToEnglishCatalog(): void {
		$this->assertSame(
			'The chat stream could not be started.',
			$this->makeResponder(language: 'xx-YY')->getUserMessage('stream_start_error', 'fallback')
		);
	}

	public function testUnknownLanguageKeyFallsBackToCallerText(): void {
		$this->assertSame('caller fallback', $this->makeResponder(language: 'en')->getUserMessage('absent_test_key_123', 'caller fallback'));
	}

	private function makeResponder(?ILogger $logger = null, string $language = 'en'): ChatbotTurnResponder {
		$translator = $this->createStub(ILanguage::class);
		$translator->method('getLanguage')->willReturn($language);
		return new ChatbotTurnResponder(
			$translator,
			new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
			$logger ?? $this->createStub(ILogger::class)
		);
	}
}
