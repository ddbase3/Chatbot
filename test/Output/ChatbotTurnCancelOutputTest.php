<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use Base3\Api\IRequest;
use Base3\State\Api\IStateStore;
use Chatbot\Output\ChatbotTurnCancelOutput;
use Chatbot\Service\ChatbotTurnCancellationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChatbotTurnCancelOutputTest extends TestCase {

	public function testNoTurnIdIsRejected(): void {
		$data = json_decode($this->createCancelEndpoint([], null)->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Missing chatbot turn id.', $data['error']);
		$this->assertSame('chatbotturncancel', ChatbotTurnCancelOutput::getName());
	}

	public function testJsonTurnIdHasPrecedenceOverRequestFields(): void {
		session_id('chatbot-cancel-output');
		$key = 'chatbot.turn.cancel.' . hash('sha256', 'chatbot-cancel-output') . '.json-turn';
		$state = $this->createMock(IStateStore::class);
		$state->expects($this->once())->method('set')->with($key, true, 600);
		$state->expects($this->once())->method('flush');
		$data = json_decode($this->createCancelEndpoint(['turn_id' => ' json-turn '], 'forged-query-turn', $state)->getOutput(), true);
		$this->assertSame(['ok' => true, 'turn_id' => 'json-turn', 'status' => 'cancellation_requested'], $data);
	}

	public function testRequestFallbackAcceptsScalarTurnId(): void {
		session_id('chatbot-cancel-output');
		$state = $this->createStub(IStateStore::class);
		$data = json_decode($this->createCancelEndpoint([], 'fallback', $state)->getOutput(), true);
		$this->assertSame('fallback', $data['turn_id']);
		$this->assertTrue($data['ok']);
	}

	public function testNonScalarTurnIdIsRejected(): void {
		$data = json_decode($this->createCancelEndpoint(['turn_id' => ['invalid']], null)->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Missing chatbot turn id.', $data['error']);
	}

	public function testSessionIdentityFailureIsReturnedAsControlledError(): void {
		session_id('');
		$data = json_decode($this->createCancelEndpoint(['turn_id' => 'turn-1'], null)->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Chatbot cancellation requires an active session identity.', $data['error']);
	}

	private function createCancelEndpoint(array $json, mixed $fallback, ?IStateStore $state = null): ChatbotTurnCancelOutput {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn($json);
		$request->method('request')->willReturn($fallback);
		return new ChatbotTurnCancelOutput($request, new ChatbotTurnCancellationService($state ?? $this->createStub(IStateStore::class)));
	}
}
