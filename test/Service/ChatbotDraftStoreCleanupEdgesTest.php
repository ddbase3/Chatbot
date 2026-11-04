<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Session\Api\ISession;
use Chatbot\Dto\ChatbotConversationDraft;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChatbotDraftStoreCleanupEdgesTest extends TestCase {

	public function testSavingPrunesStaleRowsFromOtherChannels(): void {
		$session = new ChatbotDraftSessionStub();
		$session->set(SessionChatbotConversationDraftStore::SESSION_KEY, [
			hash('sha256', 'old-channel') => [
				'old' => $this->draft('a', time() - SessionChatbotConversationDraftStore::TTL_SECONDS - 30)->toStorageArray()
			],
			hash('sha256', 'current') => [
				'fresh' => $this->draft('b', time() - 5)->toStorageArray()
			]
		]);
		$store = new SessionChatbotConversationDraftStore($session);
		$store->save('new-channel', $this->draft('c', time()));
		$data = $session->get(SessionChatbotConversationDraftStore::SESSION_KEY);
		$this->assertArrayNotHasKey(hash('sha256', 'old-channel'), $data);
		$this->assertArrayHasKey(hash('sha256', 'current'), $data);
		$this->assertArrayHasKey(hash('sha256', 'new-channel'), $data);
	}

	public function testReadingAnExpiredDraftDeletesItsSessionRecord(): void {
		$session = new ChatbotDraftSessionStub();
		$draft = $this->draft('a', time() - SessionChatbotConversationDraftStore::TTL_SECONDS - 30);
		$session->set(SessionChatbotConversationDraftStore::SESSION_KEY, [
			hash('sha256', 'channel') => [$draft->getId() => $draft->toStorageArray()]
		]);
		$store = new SessionChatbotConversationDraftStore($session);
		$this->assertNull($store->get('channel', $draft->getId()));
		$this->assertSame([], $session->get(SessionChatbotConversationDraftStore::SESSION_KEY));
	}

	public function testUnknownDraftRemovalCannotDeleteSiblingDraft(): void {
		$session = new ChatbotDraftSessionStub();
		$store = new SessionChatbotConversationDraftStore($session);
		$draft = $this->draft('a', time());
		$store->save('channel', $draft);
		$store->remove('channel', str_repeat('f', 48));
		$this->assertSame($draft->toStorageArray(), $store->get('channel', $draft->getId())?->toStorageArray());
	}

	public function testRemovingTheFinalDraftRemovesOnlyItsChannel(): void {
		$session = new ChatbotDraftSessionStub();
		$store = new SessionChatbotConversationDraftStore($session);
		$first = $this->draft('a', time());
		$second = $this->draft('b', time());
		$store->save('one', $first);
		$store->save('two', $second);
		$store->remove('one', $first->getId());
		$this->assertNull($store->getLatest('one'));
		$this->assertSame($second->getId(), $store->getLatest('two')?->getId());
		$data = $session->get(SessionChatbotConversationDraftStore::SESSION_KEY);
		$this->assertArrayNotHasKey(hash('sha256', 'one'), $data);
		$this->assertArrayHasKey(hash('sha256', 'two'), $data);
	}

	public function testAlreadyStartedSessionIsNotRestarted(): void {
		$session = $this->createMock(ISession::class);
		$session->method('started')->willReturn(true);
		$session->expects($this->never())->method('start');
		$session->method('get')->willReturn([]);
		$session->expects($this->once())->method('set')->with(SessionChatbotConversationDraftStore::SESSION_KEY, []);
		$this->assertNull((new SessionChatbotConversationDraftStore($session))->getLatest('channel'));
	}

	public function testMissingChannelIdentifierIsRejectedDuringRemoval(): void {
		$store = new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub());
		$this->expectException(InvalidArgumentException::class);
		$store->remove(' ', str_repeat('a', 48));
	}

	public function testMalformedChannelRowsAreClearedDuringLatestLookup(): void {
		$session = new ChatbotDraftSessionStub();
		$session->set(SessionChatbotConversationDraftStore::SESSION_KEY, [
			hash('sha256', 'channel') => 'broken',
			hash('sha256', 'other') => ['invalid' => ['created_at' => time() - SessionChatbotConversationDraftStore::TTL_SECONDS - 50]]
		]);
		$this->assertNull((new SessionChatbotConversationDraftStore($session))->getLatest('channel'));
		$this->assertSame([], $session->get(SessionChatbotConversationDraftStore::SESSION_KEY));
	}

	private function draft(string $character, int $createdAt): ChatbotConversationDraft {
		return new ChatbotConversationDraft(str_repeat($character, 48), 'conversation-1', 'Welcome', [], $createdAt);
	}
}

require_once __DIR__ . '/ChatbotDraftSessionStub.php';
