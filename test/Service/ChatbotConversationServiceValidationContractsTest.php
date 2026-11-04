<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationState;
use Chatbot\Service\ChatbotConversationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ChatbotConversationServiceValidationContractsTest extends TestCase {

	public function testConversationIdentifiersAllowOnlyDeclaredTechnicalCharacters(): void {
		$service = $this->service();
		$this->assertSame('conversation:1_2.3-4', $this->invoke($service, 'requireConversationId', ' conversation:1_2.3-4 '));
	}

	public function testConversationIdentifierRejectsMissingAndWhitespaceOnlyValues(): void {
		$service = $this->service();
		foreach (['', ' ', " \t\n "] as $value) {
			try {
				$this->invoke($service, 'requireConversationId', $value);
				$this->fail('Missing conversation id was accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertSame('Conversation id is missing or invalid.', $exception->getMessage());
			}
		}
	}

	public function testConversationIdentifierRejectsUnsupportedCharacters(): void {
		$service = $this->service();
		foreach (['conversation/1', 'conversation?1', 'conversation 1', 'conversation#1'] as $value) {
			try {
				$this->invoke($service, 'requireConversationId', $value);
				$this->fail('Invalid conversation id was accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertSame('Conversation id is missing or invalid.', $exception->getMessage());
			}
		}
	}

	public function testConversationIdentifierEnforcesOneHundredCharacterLimit(): void {
		$service = $this->service();
		$this->assertSame(str_repeat('a', 100), $this->invoke($service, 'requireConversationId', str_repeat('a', 100)));
		$this->expectException(InvalidArgumentException::class);
		$this->invoke($service, 'requireConversationId', str_repeat('a', 101));
	}

	public function testDraftIdentifierMustBeOpaqueLowercaseHex(): void {
		$this->assertSame(str_repeat('a', 48), $this->invoke($this->service(), 'requireDraftId', ' ' . str_repeat('A', 48) . ' '));
	}

	public function testDraftIdentifierRejectsInvalidLengthAndNonHexValues(): void {
		$service = $this->service();
		foreach ([str_repeat('a', 47), str_repeat('a', 49), str_repeat('g', 48), 'draft-1'] as $value) {
			try {
				$this->invoke($service, 'requireDraftId', $value);
				$this->fail('Invalid draft identifier was accepted.');
			}
			catch (InvalidArgumentException $exception) {
				$this->assertSame('Conversation draft id is missing or invalid.', $exception->getMessage());
			}
		}
	}

	public function testInitialTurnIgnoresAssistantMessagesBeforeFirstUserTurn(): void {
		$this->assertSame(['User question', 'Actual answer'], $this->invoke($this->service(), 'findInitialTurn', [
			['role' => 'assistant', 'content' => 'Opening message'],
			['role' => 'user', 'content' => 'User question'],
			['role' => 'assistant', 'content' => 'Actual answer']
		]));
	}

	public function testInitialTurnSkipsCancelledAndEmptyMessages(): void {
		$this->assertSame(['Question', 'Answer'], $this->invoke($this->service(), 'findInitialTurn', [
			['role' => 'user', 'content' => 'Discard', 'status' => ' CANCELLED '],
			['role' => 'user', 'content' => ' <br> '],
			['role' => 'user', 'content' => 'Question'],
			['role' => 'assistant', 'content' => 'Cancelled', 'status' => 'cancelled'],
			['role' => 'assistant', 'content' => 'Answer']
		]));
	}

	public function testInitialTurnNeverGuessesAMissingAssistantResponse(): void {
		$this->assertSame(['Question', ''], $this->invoke($this->service(), 'findInitialTurn', [
			['role' => 'user', 'content' => 'Question'], ['role' => 'system', 'content' => 'Instruction']
		]));
	}

	public function testInitialTurnUsesFirstCompletedPairRatherThanLaterMessages(): void {
		$this->assertSame(['First', 'First answer'], $this->invoke($this->service(), 'findInitialTurn', [
			['role' => 'user', 'content' => 'First'],
			['role' => 'assistant', 'content' => 'First answer'],
			['role' => 'user', 'content' => 'Second'],
			['role' => 'assistant', 'content' => 'Second answer']
		]));
	}

	public function testAutomaticTitleRemovesMarkupAndTerminalPunctuation(): void {
		$this->assertSame('Meaningful title', $this->invoke($this->service(), 'normalizeTitle', ' <b>Meaningful</b> *title*!!! ', 100));
	}

	public function testPlainTextNormalizationLimitsModelInputAndDecodesEntities(): void {
		$this->assertSame('A & B', $this->invoke($this->service(), 'normalizePlainText', '  <b>A</b> &amp; **B** ', 100));
		$this->assertSame(str_repeat('x', 10), $this->invoke($this->service(), 'normalizePlainText', str_repeat('x', 100), 10));
	}

	public function testTemporaryTitlesUseCalendarDateAndMinutePrecision(): void {
		$title = $this->invoke($this->service(), 'createTemporaryTitle');
		$this->assertSame(1, preg_match('/^Chat \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $title));
	}

	public function testConversationLookupDoesNotSubstituteMissingActiveConversation(): void {
		$first = new AgentConversation('conversation-1', 'One', AgentConversation::TITLE_SOURCE_MANUAL, '', '2026-11-03', '2026-11-03', '2026-11-03');
		$second = new AgentConversation('conversation-2', 'Two', AgentConversation::TITLE_SOURCE_MANUAL, '', '2026-11-03', '2026-11-03', '2026-11-03');
		$state = new AgentConversationState([$first, $second], $first, [], 'assistant');
		$this->assertSame($second, $this->invoke($this->service(), 'findConversation', $state, 'conversation-2'));
		$this->assertNull($this->invoke($this->service(), 'findConversation', $state, 'missing'));
	}

	public function testTitleSettingsDoNotUseGenericTruthyValueCoercion(): void {
		$service = $this->service();
		foreach ([true, 1, '1', 'YES', 'on', 'true'] as $value) {
			$this->assertTrue($this->invoke($service, 'toBool', $value));
		}
		foreach ([false, 0, 2, '0', 'off', 'no', 'false', ''] as $value) {
			$this->assertFalse($this->invoke($service, 'toBool', $value));
		}
	}

	private function service(): ChatbotConversationService {
		return (new ReflectionClass(ChatbotConversationService::class))->newInstanceWithoutConstructor();
	}

	private function invoke(ChatbotConversationService $service, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod(ChatbotConversationService::class, $method))->invoke($service, ...$args);
	}
}
