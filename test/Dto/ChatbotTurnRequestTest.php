<?php declare(strict_types=1);

namespace Chatbot\Test\Dto;

use Chatbot\Dto\ChatbotTurnRequest;
use PHPUnit\Framework\TestCase;

final class ChatbotTurnRequestTest extends TestCase {

	public function testPromptFallbackAndTransportNormalization(): void {
		$request = new ChatbotTurnRequest(['prompt' => '  ', 'user' => ' fallback ', 'transport_mode' => ' SSE ', 'config_group' => ' chatbot ', 'config_name' => ' default ']);
		$this->assertSame('fallback', $request->getPrompt());
		$this->assertTrue($request->hasPromptOrResume());
		$this->assertSame('sse', $request->getTransportMode());
		$this->assertSame('chatbot', $request->getConfigGroup());
		$this->assertSame('default', $request->getConfigName());
		$this->assertSame('', (new ChatbotTurnRequest(['prompt' => []]))->getPrompt());
		$this->assertFalse((new ChatbotTurnRequest([]))->hasPromptOrResume());
	}

	public function testTechnicalIdsAreTrimmedSanitizedAndLengthLimited(): void {
		$request = new ChatbotTurnRequest([
			'conversation_id' => '  chat@id /a :._-  ',
			'turn_id' => str_repeat('a', 120),
			'conversation_channel_id' => 'forged-channel'
		]);
		$this->assertSame('chatida:._-', $request->getConversationId());
		$this->assertSame(str_repeat('a', 100), $request->getTurnId());
		$this->assertArrayNotHasKey('conversation_channel_id', $request->getPayload());
		$this->assertSame('', (new ChatbotTurnRequest(['turn_id' => ['unsafe']]))->getTurnId());
	}

	public function testReferenceArrayKeepsScalarValuesAndRemovesObjects(): void {
		$request = new ChatbotTurnRequest(['reference' => [
			'id' => 7, 'enabled' => true, 'empty' => null,
			'nested' => ['value' => 'ok', 'object' => new \stdClass() ],
			'object' => new \stdClass()
		]]);
		$this->assertSame([
			'id' => 7, 'enabled' => true, 'empty' => null,
			'nested' => ['value' => 'ok']
		], $request->getReference());
	}

	public function testJsonAndBase64UrlReferencesAreBothAccepted(): void {
		$data = ['resource' => 'course/1', 'ids' => [1, 2]];
		$rawJson = json_encode($data, JSON_THROW_ON_ERROR);
		$this->assertSame($data, (new ChatbotTurnRequest(['reference' => $rawJson]))->getReference());
		$base64Url = rtrim(strtr(base64_encode($rawJson), '+/', '-_'), '=');
		$this->assertSame($data, (new ChatbotTurnRequest(['reference' => $base64Url, 'reference_format' => 'base64json']))->getReference());
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => '!not-json!']))->getReference());
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => 42]))->getReference());
	}

	public function testDeepReferenceNestingIsBounded(): void {
		$request = new ChatbotTurnRequest(['reference' => ['n' => ['n' => ['n' => ['n' => ['n' => ['n' => ['hidden' => 'value']]]]]]]]);
		$node = $request->getReference();
		for ($depth = 0; $depth < 6; $depth++) {
			$this->assertArrayHasKey('n', $node);
			$node = $node['n'];
		}
		$this->assertSame([], $node);
	}

	public function testResumeFromJsonNormalizesResponsesAndText(): void {
		$request = new ChatbotTurnRequest(['resume' => json_encode([
			'resume_handle' => '  handle-1 ',
			'responses' => [['request_id' => 'r1', 'decision' => 'approve']],
			'response_text' => ' Approved '
		])]);
		$this->assertSame('handle-1', $request->getResume()['resume_handle']);
		$this->assertSame([['request_id' => 'r1', 'decision' => 'approve']], $request->getResume()['responses']);
		$this->assertSame('Approved', $request->getResumeResponseText());
		$this->assertTrue($request->hasPromptOrResume());
	}

	public function testResumeFallbackFieldsAndInvalidJson(): void {
		$request = new ChatbotTurnRequest(['resume' => 'broken-json', 'resume_handle' => 'session-1', 'resume_responses' => '[{"request_id":"x","decision":"deny"}]', 'resume_response' => ' no ']);
		$this->assertSame('session-1', $request->getResume()['resume_handle']);
		$this->assertSame('no', $request->getResumeResponseText());
		$this->assertCount(1, $request->getResume()['responses']);
		$this->assertSame([], (new ChatbotTurnRequest(['resume_responses' => ['bad'], 'resume_handle' => '']))->getResume());
		$this->assertSame([], (new ChatbotTurnRequest(['resume' => ['resume_handle' => 'h', 'responses' => false]]))->getResume()['responses']);
	}

	public function testArrayAndScalarGettersHonorDefaults(): void {
		$request = new ChatbotTurnRequest(['list' => [1], 'scalar' => 10, 'nested' => ['a' => 1], 'null' => null]);
		$this->assertSame([1], $request->getArray('list'));
		$this->assertSame([], $request->getArray('scalar'));
		$this->assertSame('10', $request->getString('scalar'));
		$this->assertSame('default', $request->getString('nested', 'default'));
		$this->assertSame('', $request->getString('null', 'default'));
		$this->assertSame('default', $request->getString('missing', 'default'));
	}
}
