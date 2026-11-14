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

use Base3\CoreFoundation\Session\Api\ISession;
use Base3\Chatbot\Dto\ChatbotConversationDraft;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SessionChatbotConversationDraftStoreTest extends TestCase {

	public function testSavingAndLoadingDraftsPreservesChannelIsolation(): void {
		$session = new ChatbotDraftSessionStub();
		$store = new SessionChatbotConversationDraftStore($session);
		$alpha = $this->draft('a', 'alpha', time());
		$beta = $this->draft('b', 'beta', time());
		$store->save(' channel-1 ', $alpha);
		$store->save('channel-2', $beta);
		$this->assertSame($alpha->toStorageArray(), $store->get('channel-1', $alpha->getId())?->toStorageArray());
		$this->assertNull($store->get('channel-2', $alpha->getId()));
		$this->assertSame($beta->toStorageArray(), $store->getLatest('channel-2')?->toStorageArray());
		$this->assertTrue($session->started());
		$this->assertArrayHasKey(hash('sha256', 'channel-1'), $session->get(SessionChatbotConversationDraftStore::SESSION_KEY));
	}

	public function testLatestPrefersMostRecentCreationTime(): void {
		$session = new ChatbotDraftSessionStub();
		$store = new SessionChatbotConversationDraftStore($session);
		$now = time();
		$older = $this->draft('a', 'conversation-1', $now - 100);
		$newer = $this->draft('b', 'conversation-2', $now - 1);
		$store->save('same', $older);
		$store->save('same', $newer);
		$this->assertSame($newer->getId(), $store->getLatest('same')?->getId());
		$store->remove('same', $newer->getId());
		$this->assertSame($older->getId(), $store->getLatest('same')?->getId());
		$store->remove('same', $older->getId());
		$this->assertNull($store->getLatest('same'));
		$this->assertSame([], $session->get(SessionChatbotConversationDraftStore::SESSION_KEY));
	}

	public function testExpiredDraftsAndInvalidRowsAreRemovedOnRead(): void {
		$session = new ChatbotDraftSessionStub();
		$channelKey = hash('sha256', 'channel');
		$session->set(SessionChatbotConversationDraftStore::SESSION_KEY, [
			$channelKey => [
				'old' => $this->draft('a', 'old', time() - SessionChatbotConversationDraftStore::TTL_SECONDS - 20)->toStorageArray(),
				'broken' => 'not-an-array',
				'valid' => $this->draft('b', 'new', time())->toStorageArray()
			],
			'broken-channel' => 42
		]);
		$store = new SessionChatbotConversationDraftStore($session);
		$this->assertSame(str_repeat('b', 48), $store->getLatest('channel')?->getId());
		$this->assertSame(['valid'], array_keys($session->get(SessionChatbotConversationDraftStore::SESSION_KEY)[$channelKey]));
		$this->assertArrayNotHasKey('broken-channel', $session->get(SessionChatbotConversationDraftStore::SESSION_KEY));
	}

	public function testMissingOrNonArraySessionPayloadIsHandled(): void {
		$session = new ChatbotDraftSessionStub();
		$session->set(SessionChatbotConversationDraftStore::SESSION_KEY, 'invalid');
		$store = new SessionChatbotConversationDraftStore($session);
		$this->assertNull($store->get('channel', str_repeat('a', 48)));
		$this->assertSame([], $session->get(SessionChatbotConversationDraftStore::SESSION_KEY));
	}

	public function testEmptyChannelIsRejected(): void {
		$store = new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub());
		$this->expectException(InvalidArgumentException::class);
		$store->save(' ', $this->draft('a', 'valid', time()));
	}

	public function testFailedSessionStartupIsReported(): void {
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn(false);
		$session->method('start')->willReturn(false);
		$this->expectException(RuntimeException::class);
		(new SessionChatbotConversationDraftStore($session))->getLatest('channel');
	}

	private function draft(string $idCharacter, string $conversationId, int $createdAt): ChatbotConversationDraft {
		return new ChatbotConversationDraft(str_repeat($idCharacter, 48), $conversationId, 'Welcome', [], $createdAt);
	}
}
