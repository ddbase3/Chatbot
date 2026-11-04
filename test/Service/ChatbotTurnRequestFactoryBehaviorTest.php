<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Api\IRequest;
use Chatbot\Service\ChatbotTurnRequestFactory;
use PHPUnit\Framework\TestCase;

final class ChatbotTurnRequestFactoryBehaviorTest extends TestCase {

	public function testMergedRequestValuesTakePrecedenceOverGetValues(): void {
		$request = $this->request(['prompt' => 'body', 'config_group' => 'chatbot', 'turn_id' => 'body-turn'], ['prompt' => 'query', 'config_group' => 'query-group', 'turn_id' => 'query-turn']);
		$turn = (new ChatbotTurnRequestFactory($request))->fromCurrentRequest();
		$this->assertSame('body', $turn->getPrompt());
		$this->assertSame('chatbot', $turn->getConfigGroup());
		$this->assertSame('body-turn', $turn->getTurnId());
	}

	public function testQueryFallbackIsUsedForMissingRequestFields(): void {
		$request = $this->request([], ['user' => 'query user', 'conversation_id' => 'chat-1', 'transport_mode' => ' REST ']);
		$turn = (new ChatbotTurnRequestFactory($request))->fromCurrentRequest();
		$this->assertSame('query user', $turn->getPrompt());
		$this->assertSame('chat-1', $turn->getConversationId());
		$this->assertSame('rest', $turn->getTransportMode());
	}

	public function testMissingPromptFallsBackToUserField(): void {
		$turn = (new ChatbotTurnRequestFactory($this->request(['user' => 'fallback prompt'])))->fromCurrentRequest();
		$this->assertSame('fallback prompt', $turn->getPrompt());
		$this->assertTrue($turn->hasPromptOrResume());
	}

	public function testRequestFactoryCarriesStructuredResumeAndSanitizesReference(): void {
		$turn = (new ChatbotTurnRequestFactory($this->request([
			'resume_handle' => ' resume-1 ',
			'resume_response' => ' Yes ',
			'resume_responses' => '[{"request_id":"one","decision":"approve"}]',
			'reference' => '{"page":{"id":42}}',
			'conversation_channel_id' => 'forged-channel'
		])))->fromCurrentRequest();
		$this->assertTrue($turn->hasPromptOrResume());
		$this->assertSame('resume-1', $turn->getResume()['resume_handle']);
		$this->assertSame('Yes', $turn->getResumeResponseText());
		$this->assertSame(['page' => ['id' => 42]], $turn->getReference());
		$this->assertArrayNotHasKey('conversation_channel_id', $turn->getPayload());
	}

	private function request(array $body, array $query = []): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('request')->willReturnCallback(static fn(string $key): mixed => $body[$key] ?? null);
		$request->method('get')->willReturnCallback(static fn(string $key): mixed => $query[$key] ?? null);
		return $request;
	}
}
