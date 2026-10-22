<?php declare(strict_types=1);

namespace Chatbot\Diagnostics;

use Base3\Diagnostics\Api\IDiagnosticCheck;
use Base3\Diagnostics\Dto\DiagnosticCheckResult;
use Base3\Diagnostics\Scope\PrivacyDiagnosticScope;
use Base3\Session\Api\ISession;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Service\SessionChatbotTurnRequestStore;

/** Read-only current-session sample; deliberately cannot claim global PHP-session cleanup. */
final class TransientChatSessionDiagnosticCheck implements IDiagnosticCheck {

	public function __construct(private readonly ISession $session) {}

	public static function getName(): string {
		return 'transientchatsessiondiagnosticcheck';
	}

	public function getScope(): string {
		return PrivacyDiagnosticScope::getName();
	}

	public function run(): DiagnosticCheckResult {
		if(!$this->session->started()) {
			return new DiagnosticCheckResult(DiagnosticCheckResult::NOT_APPLICABLE, 'not_applicable');
		}

		$now = time();
		$overdueDrafts = 0;
		$overdueTurns = 0;
		$drafts = $this->session->get(SessionChatbotConversationDraftStore::SESSION_KEY, []);
		foreach(is_array($drafts) ? $drafts : [] as $entries) {
			if(!is_array($entries)) {
				continue;
			}
			foreach($entries as $entry) {
				if(!is_array($entry) || (int)($entry['created_at'] ?? 0) < $now - SessionChatbotConversationDraftStore::TTL_SECONDS) {
					$overdueDrafts++;
				}
			}
		}
		$turns = $_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY] ?? [];
		foreach(is_array($turns) ? $turns : [] as $entry) {
			if(!is_array($entry) || (int)($entry['expires_at'] ?? 0) <= $now) {
				$overdueTurns++;
			}
		}

		$overdue = $overdueDrafts + $overdueTurns;
		return new DiagnosticCheckResult(
			$overdue > 0 ? DiagnosticCheckResult::FAILED : DiagnosticCheckResult::WARNING,
			$overdue > 0 ? 'failed' : 'partial',
			['drafts' => $overdueDrafts, 'turns' => $overdueTurns],
			$overdue > 0 ? 'remediation' : 'scope_remediation',
			['overdueDrafts' => $overdueDrafts, 'overdueTurns' => $overdueTurns]
		);
	}

}
