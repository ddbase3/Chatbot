<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\Dto\ChatbotTurnRequest;
use Chatbot\Dto\PendingChatbotTurn;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionChatbotTurnRequestStoreTest extends TestCase {

	public function testStoreReturnsOpaqueIdAndClaimIsSingleUse(): void {
		$store = new SessionChatbotTurnRequestStore();
		$turn = new PendingChatbotTurn('dummychatbotservice', new ChatbotTurnRequest(['prompt' => 'Hello', 'conversation_channel_id' => 'forged']));
		$id = $store->store($turn);
		$this->assertSame(1, preg_match('/^[a-f0-9]{48}$/', $id));
		$this->assertArrayHasKey($id, $_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY]);
		$this->assertSame('Hello', $store->claim(strtoupper($id))?->getRequest()->getPrompt());
		$this->assertNull($store->claim($id));
		$this->assertArrayNotHasKey($id, $_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY]);
	}

	public function testMalformedClaimIdentifiersAreRejectedWithoutStartingSession(): void {
		$store = new SessionChatbotTurnRequestStore();
		foreach (['', 'not-a-ticket', str_repeat('g', 48), str_repeat('a', 49)] as $id) {
			$this->assertNull($store->claim($id));
		}
	}

	public function testExpiredAndCorruptEntriesArePrunedBeforeClaim(): void {
		$store = new SessionChatbotTurnRequestStore();
		$turn = new PendingChatbotTurn('dummychatbotservice', new ChatbotTurnRequest(['user' => 'hi']));
		$id = $store->store($turn);
		$oldId = str_repeat('a', 48);
		$_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY][$oldId] = ['expires_at' => time() - 1, 'turn' => $turn->toArray()];
		$_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY][str_repeat('b', 48)] = 'invalid';
		$this->assertNull($store->claim($oldId));
		$this->assertArrayNotHasKey($oldId, $_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY]);
		$this->assertSame($turn->toArray(), $store->claim($id)?->toArray());
	}

	public function testMissingTurnPayloadCannotBeClaimed(): void {
		$store = new SessionChatbotTurnRequestStore();
		$id = $store->store(new PendingChatbotTurn('service', new ChatbotTurnRequest(['prompt' => 'hello'])));
		$_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY][$id]['turn'] = ['service_id' => '', 'payload' => []];
		$this->assertNull($store->claim($id));
	}

	public function testMalformedSessionContainerIsReinitialized(): void {
		$store = new SessionChatbotTurnRequestStore();
		$id = $store->store(new PendingChatbotTurn('service', new ChatbotTurnRequest(['prompt' => 'hello'])));
		$_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY] = 'corrupt';
		$this->assertNull($store->claim($id));
		$this->assertSame([], $_SESSION[SessionChatbotTurnRequestStore::SESSION_KEY]);
	}
}
