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

namespace Chatbot\Service;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Session\Api\ISession;

/**
 * Adds server-owned conversation scope data to a browser turn payload.
 */
final class ChatbotConversationContextFactory {

	public function __construct(
		private readonly IAccesscontrol $accesscontrol,
		private readonly ISession $session
	) {}

	public static function getName(): string {
		return 'chatbotconversationcontextfactory';
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function enrich(array $payload): array {
		$payload['conversation_owner_key'] = hash('sha256', $this->resolveOwnerIdentity());

		return $payload;
	}

	private function resolveOwnerIdentity(): string {
		$userId = $this->accesscontrol->getUserId();
		if ($userId !== null && (int)$userId > 0) {
			return 'user:' . (string)$userId;
		}

		$this->session->start();
		$sessionId = trim((string)$this->session->getId());
		if ($sessionId === '') {
			throw new \RuntimeException('Chatbot conversation requires an active user or session.');
		}

		return 'session:' . $sessionId;
	}
}
