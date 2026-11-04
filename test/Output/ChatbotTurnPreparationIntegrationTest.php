<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use Base3\Api\IClassMap;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Output\ChatbotTurnPrepareOutput;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\SessionChatbotTurnRequestStore;
use Chatbot\Test\Service\ChatbotServiceRegistryTestDouble;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChatbotTurnPreparationIntegrationTest extends TestCase {

	public function testPreparedTurnIsConsumableOnlyOnceAndCannotContainBrowserChannelIdentity(): void {
		$store = new SessionChatbotTurnRequestStore();
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects($this->once())->method('getLink')->willReturnCallback(function(array $target, array $params): string {
			$this->assertSame(['name' => 'chatbotturnstream'], $target);
			$this->assertSame(1, preg_match('/^[a-f0-9]{48}$/', $params['id']));
			return '/stream?ticket=' . $params['id'];
		});
		$output = $this->prepare([
			'service' => ' ChatbotServiceRegistryTestDouble ',
			'payload' => [
				'prompt' => ' Hello ', 'config_group' => 'chatbot', 'config_name' => 'sidebar',
				'conversation_channel_id' => 'browser-forgery'
			]
		], $store, $links);
		$data = json_decode($output->getOutput(), true);
		$this->assertTrue($data['ok']);
		$this->assertSame('/stream?ticket=' . $data['id'], $data['stream']);
		$turn = $store->claim($data['id']);
		$this->assertNotNull($turn);
		$this->assertSame('chatbotserviceregistrytestdouble', $turn->getServiceId());
		$this->assertSame('Hello', $turn->getRequest()->getPrompt());
		$this->assertSame('chatbot', $turn->getRequest()->getConfigGroup());
		$this->assertArrayNotHasKey('conversation_channel_id', $turn->getRequest()->getPayload());
		$this->assertNull($store->claim($data['id']));
	}

	public function testResumeOnlyTurnCanBePreparedAndClaimedWithoutInventingPrompt(): void {
		$store = new SessionChatbotTurnRequestStore();
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/stream');
		$data = json_decode($this->prepare([
			'service' => 'chatbotserviceregistrytestdouble',
			'payload' => ['resume_handle' => 'resume-abc', 'prompt' => '']
		], $store, $links)->getOutput(), true);
		$this->assertTrue($data['ok']);
		$claimed = $store->claim($data['id']);
		$this->assertNotNull($claimed);
		$this->assertSame('', $claimed->getRequest()->getPrompt());
		$this->assertTrue($claimed->getRequest()->hasPromptOrResume());
	}

	public function testUnknownBackendNeverCreatesPendingTurnOrStreamLink(): void {
		$store = $this->createMock(IChatbotTurnRequestStore::class);
		$store->expects($this->never())->method('store');
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects($this->never())->method('getLink');
		$data = json_decode($this->prepare([
			'service' => 'unregistered-service',
			'payload' => ['prompt' => 'hello']
		], $store, $links)->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Unknown chatbot service: unregistered-service', $data['error']);
	}

	public function testLinkGenerationFailureIsReportedAsAnErrorInsteadOfAFalseStreamUrl(): void {
		$store = $this->createMock(IChatbotTurnRequestStore::class);
		$store->expects($this->once())->method('store')->willReturn(str_repeat('a', 48));
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(): never {
			throw new RuntimeException('Stream route unavailable');
		});
		$data = json_decode($this->prepare([
			'service' => 'chatbotserviceregistrytestdouble',
			'payload' => ['prompt' => 'hello']
		], $store, $links)->getOutput(), true);
		$this->assertSame(['ok' => false, 'error' => 'Stream route unavailable'], $data);
	}

	private function prepare(array $payload, IChatbotTurnRequestStore $store, ILinkTargetService $links): ChatbotTurnPrepareOutput {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn($payload);
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceRegistryTestDouble()]);
		return new ChatbotTurnPrepareOutput($request, $store, new ChatbotServiceRegistry($map), $links);
	}
}

require_once __DIR__ . '/../Service/ChatbotServiceRegistryTestDouble.php';
