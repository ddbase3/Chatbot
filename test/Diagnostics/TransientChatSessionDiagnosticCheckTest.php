<?php declare(strict_types=1);

namespace Chatbot\Test\Diagnostics;

use Base3\Diagnostics\Dto\DiagnosticCheckResult;
use Base3\Session\Api\ISession;
use Chatbot\Diagnostics\TransientChatSessionDiagnosticCheck;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use PHPUnit\Framework\TestCase;

final class TransientChatSessionDiagnosticCheckTest extends TestCase {

	private mixed $originalSession;

	protected function setUp(): void {
		$this->originalSession = $_SESSION ?? null;
		$_SESSION = [];
	}

	protected function tearDown(): void {
		if($this->originalSession === null) {
			unset($_SESSION);
		} else {
			$_SESSION = $this->originalSession;
		}
	}

	public function testEmptyCurrentSessionWarnsAboutIncompleteCoverage(): void {
		$result = $this->check([]);
		$this->assertSame(DiagnosticCheckResult::WARNING, $result->status);
		$this->assertSame('partial', $result->messageKey);
	}

	public function testOverdueCurrentSessionEntriesFailWithoutExposingContent(): void {
		$_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY] = ['secret-id' => [
			'expires_at' => time() - 1,
			'turn' => ['secret_prompt' => 'PERSONAL-DATA']
		]];
		$result = $this->check([
			'channel' => ['draft-id' => ['created_at' => time() - SessionChatbotConversationDraftStore::TTL_SECONDS - 1, 'messages' => ['PERSONAL-DATA']]]
		]);
		$this->assertSame(DiagnosticCheckResult::FAILED, $result->status);
		$this->assertSame(1, $result->details['overdueTurns']);
		$this->assertSame(1, $result->details['overdueDrafts']);
		$this->assertStringNotContainsString('PERSONAL-DATA', json_encode($result));
	}

	public function testMissingSessionIsNotApplicable(): void {
		$this->assertSame(DiagnosticCheckResult::NOT_APPLICABLE, $this->check([], false)->status);
	}

	private function check(array $drafts, bool $started = true): DiagnosticCheckResult {
		$session = $this->createMock(ISession::class);
		$session->method('started')->willReturn($started);
		$session->method('get')->willReturn($drafts);
		$session->expects($this->never())->method('set');
		return (new TransientChatSessionDiagnosticCheck($session))->run();
	}

}
