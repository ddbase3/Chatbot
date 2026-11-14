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

use Base3\CoreFoundation\Api\IRequest;
use Base3\Chatbot\Service\ChatbotTurnRequestFactory;
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
