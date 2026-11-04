<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use Chatbot\Output\AbstractChatbotConversationOutput;
use Chatbot\Output\ChatbotConversationCreateOutput;
use Chatbot\Output\ChatbotConversationStateOutput;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class ChatbotConversationBoundaryPrivateContractsTest extends TestCase {

	public function testStateEndpointAllowsReadAndWriteMethods(): void {
		$this->assertSame(['GET', 'POST'], $this->invoke($this->subject(ChatbotConversationStateOutput::class), 'getAllowedMethods'));
	}

	public function testMutationEndpointAllowsOnlyPost(): void {
		$this->assertSame(['POST'], $this->invoke($this->subject(ChatbotConversationCreateOutput::class), 'getAllowedMethods'));
	}

	public function testRequiredFieldTrimsTextWithoutChangingContent(): void {
		$this->assertSame('chat-7', $this->invoke($this->boundary(), 'requireString', ['id' => "  chat-7\t"], 'id'));
	}

	public function testRequiredFieldAcceptsNumericZeroAsValue(): void {
		$this->assertSame('0', $this->invoke($this->boundary(), 'requireString', ['id' => 0], 'id'));
	}

	public function testRequiredFieldRejectsArrayInsteadOfCastingIt(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->invoke($this->boundary(), 'requireString', ['id' => ['forged']], 'id');
	}

	public function testRequiredFieldRejectsObjectsInsteadOfCastingThem(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->invoke($this->boundary(), 'requireString', ['id' => new \stdClass()], 'id');
	}

	public function testRequiredFieldRejectsWhitespaceOnlyValues(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->invoke($this->boundary(), 'requireString', ['id' => " \n "], 'id');
	}

	public function testReferenceAllowsStructuredJsonArrayAndPreservesScalarTypes(): void {
		$reference = '["course/7",{"id":9,"active":true,"none":null}]';
		$this->assertSame(['course/7', ['id' => 9, 'active' => true, 'none' => null]], $this->invoke($this->boundary(), 'getReference', ['reference' => $reference]));
	}

	public function testReferenceRejectsInvalidAndScalarJsonStrings(): void {
		foreach (['broken', '"secret"', 'null', 'true', ''] as $reference) {
			$this->assertSame([], $this->invoke($this->boundary(), 'getReference', ['reference' => $reference]));
		}
	}

	public function testReferenceRejectsObjectsAndNonArrayScalarValues(): void {
		foreach ([new \stdClass(), 12, false, null] as $reference) {
			$this->assertSame([], $this->invoke($this->boundary(), 'getReference', ['reference' => $reference]));
		}
	}

	public function testReferenceDropsObjectsButPreservesNestedArrayKeys(): void {
		$reference = ['page' => ['id' => 2, 'private' => new \stdClass()], 'skip' => new \stdClass(), 'enabled' => false];
		$this->assertSame(['page' => ['id' => 2], 'enabled' => false], $this->invoke($this->boundary(), 'getReference', ['reference' => $reference]));
	}

	public function testReferenceDepthLimitPreventsUnboundedNestedData(): void {
		$reference = ['value' => 'secret'];
		for ($depth = 0; $depth < 7; $depth++) {
			$reference = ['child' => $reference];
		}
		$normalized = $this->invoke($this->boundary(), 'getReference', ['reference' => $reference]);
		for ($depth = 0; $depth < 6; $depth++) {
			$this->assertArrayHasKey('child', $normalized);
			$normalized = $normalized['child'];
		}
		$this->assertSame([], $normalized);
	}

	public function testEncodingPreservesUnicodeAndUnescapedPaths(): void {
		$json = $this->invoke($this->boundary(), 'encode', ['ok' => true, 'url' => 'https://example.test/chat/a', 'text' => 'Gruesse']);
		$this->assertSame(['ok' => true, 'url' => 'https://example.test/chat/a', 'text' => 'Gruesse'], json_decode($json, true));
		$this->assertStringContainsString('https://example.test/chat/a', $json);
	}

	public function testEncodingSubstitutesInvalidUtf8WithoutLeakingRawBytes(): void {
		$json = $this->invoke($this->boundary(), 'encode', ['value' => "bad\xC3\x28"]);
		$this->assertSame('bad' . "\u{FFFD}" . '(', json_decode($json, true)['value']);
	}

	public function testRecursiveEncodingReturnsDeterministicError(): void {
		$recursive = [];
		$recursive['self'] = &$recursive;
		$this->assertSame([
			'ok' => false,
			'error' => ['code' => 'encoding_error', 'message' => 'Response encoding failed.']
		], json_decode($this->invoke($this->boundary(), 'encode', $recursive), true));
	}

	public function testInvalidArgumentIsAlwaysAnInvalidRequest(): void {
		$this->assertSame('invalid_request', $this->invoke($this->boundary(), 'getErrorCode', new InvalidArgumentException('not found')));
	}

	public function testMissingConfiguredMemoryMapsToConversationUnavailable(): void {
		foreach (['Chatbot is not configured.', 'Operation requires a configured memory_profile.'] as $message) {
			$this->assertSame('conversation_unavailable', $this->invoke($this->boundary(), 'getErrorCode', new RuntimeException($message)));
		}
	}

	public function testMissingConversationMapsToNotFound(): void {
		foreach (['Conversation not found', 'Conversation does not exist'] as $message) {
			$this->assertSame('conversation_not_found', $this->invoke($this->boundary(), 'getErrorCode', new RuntimeException($message)));
		}
	}

	public function testOtherRuntimeFailuresUseConversationError(): void {
		$this->assertSame('conversation_error', $this->invoke($this->boundary(), 'getErrorCode', new RuntimeException('Failed to save')));
	}

	public function testUnexpectedFailuresNeverMasqueradeAsValidationErrors(): void {
		$this->assertSame('internal_error', $this->invoke($this->boundary(), 'getErrorCode', new LogicException('Unknown state')));
	}

	private function boundary(): ChatbotConversationCreateOutput {
		return $this->subject(ChatbotConversationCreateOutput::class);
	}

	/** @template T of AbstractChatbotConversationOutput @param class-string<T> $type @return T */
	private function subject(string $type): AbstractChatbotConversationOutput {
		return (new ReflectionClass($type))->newInstanceWithoutConstructor();
	}

	private function invoke(AbstractChatbotConversationOutput $endpoint, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod($endpoint, $method))->invoke($endpoint, ...$args);
	}
}
