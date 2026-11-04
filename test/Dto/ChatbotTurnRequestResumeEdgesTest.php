<?php declare(strict_types=1);

namespace Chatbot\Test\Dto;

use Chatbot\Dto\ChatbotTurnRequest;
use PHPUnit\Framework\TestCase;

final class ChatbotTurnRequestResumeEdgesTest extends TestCase {

	public function testPromptWinsOverLegacyUserAndWhitespaceFallsBack(): void {
		$this->assertSame('explicit', (new ChatbotTurnRequest(['prompt' => ' explicit ', 'user' => 'legacy']))->getPrompt());
		$this->assertSame('legacy', (new ChatbotTurnRequest(['prompt' => " \t ", 'user' => ' legacy ']))->getPrompt());
		$this->assertFalse((new ChatbotTurnRequest(['prompt' => [], 'user' => new \stdClass()]))->hasPromptOrResume());
	}

	public function testResumeHandleIsMandatoryEvenWhenResponsesAreProvided(): void {
		$request = new ChatbotTurnRequest(['resume' => ['responses' => [['request_id' => 'approval']]], 'resume_response' => 'answer']);
		$this->assertSame([], $request->getResume());
		$this->assertSame('', $request->getResumeResponseText());
		$this->assertFalse($request->hasPromptOrResume());
	}

	public function testResumePayloadValuesOverrideLegacyFormFields(): void {
		$request = new ChatbotTurnRequest([
			'resume' => ['resume_handle' => ' scoped.handle ', 'responses' => [['request_id' => 'one']], 'response_text' => ' inside '],
			'resume_handle' => 'outside', 'resume_responses' => [['request_id' => 'two']], 'resume_response' => 'outside'
		]);
		$this->assertSame('scoped.handle', $request->getResume()['resume_handle']);
		$this->assertSame([['request_id' => 'one']], $request->getResume()['responses']);
		$this->assertSame('inside', $request->getResumeResponseText());
	}

	public function testLegacyFormFieldsProvideResumeWhenJsonIsNotAnObject(): void {
		$request = new ChatbotTurnRequest([
			'resume' => '"invalid-shape"',
			'resume_handle' => ' legacy ',
			'resume_responses' => '[{"request_id":"approval-1","decision":"deny"}]'
		]);
		$this->assertSame('legacy', $request->getResume()['resume_handle']);
		$this->assertSame([['request_id' => 'approval-1', 'decision' => 'deny']], $request->getResume()['responses']);
		$this->assertArrayNotHasKey('response_text', $request->getResume());
	}

	public function testInvalidJsonResponseCollectionNeverCreatesSyntheticInteraction(): void {
		$request = new ChatbotTurnRequest(['resume_handle' => 'handle', 'resume_responses' => '{invalid']);
		$this->assertSame(['resume_handle' => 'handle', 'responses' => []], $request->getResume());
		$this->assertTrue($request->hasPromptOrResume());
	}

	public function testTechnicalIdentifiersRejectArrayValuesAndPreserveAllowedSeparators(): void {
		$request = new ChatbotTurnRequest([
			'conversation_id' => ['injected'],
			'turn_id' => ' ABC-1_2.3:4 @ slash / ',
			'conversation_channel_id' => 'browser-forged',
			'config_group' => 'tenant'
		]);
		$this->assertSame('', $request->getConversationId());
		$this->assertSame('ABC-1_2.3:4slash', $request->getTurnId());
		$this->assertArrayNotHasKey('conversation_channel_id', $request->getPayload());
		$this->assertSame('tenant', $request->getConfigGroup());
	}

	public function testReferenceJsonScalarDoesNotExposeUnstructuredData(): void {
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => '"secret"']))->getReference());
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => 'null']))->getReference());
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => ' ']))->getReference());
	}

	public function testBase64ReferenceNormalizationPreservesNestedScalarTypes(): void {
		$data = ['context' => ['page' => 'course/1', 'section' => 5, 'hidden' => false, 'none' => null]];
		$raw = rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		$this->assertSame($data, (new ChatbotTurnRequest(['reference_format' => 'base64json', 'reference' => $raw]))->getReference());
	}

	public function testExplicitBase64FormatDoesNotTreatPlainJsonAsReference(): void {
		$request = new ChatbotTurnRequest(['reference_format' => 'base64json', 'reference' => '{"token":"forged"}']);
		$this->assertSame([], $request->getReference());
	}

	public function testNonArrayEncodedReferenceAndInvalidBase64AreRejected(): void {
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => base64_encode('"string"'), 'reference_format' => 'base64json']))->getReference());
		$this->assertSame([], (new ChatbotTurnRequest(['reference' => '?%%%?', 'reference_format' => 'base64json']))->getReference());
	}

	public function testGenericScalarGettersNeverConvertObjectsOrArraysToStrings(): void {
		$request = new ChatbotTurnRequest(['zero' => 0, 'yes' => true, 'complex' => new \stdClass(), 'list' => []]);
		$this->assertSame('0', $request->getString('zero', 'missing'));
		$this->assertSame('1', $request->getString('yes', 'missing'));
		$this->assertSame('missing', $request->getString('complex', 'missing'));
		$this->assertSame('missing', $request->getString('list', 'missing'));
		$this->assertSame([], $request->getArray('complex'));
	}
}
