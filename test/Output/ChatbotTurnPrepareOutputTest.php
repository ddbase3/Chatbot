<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use Base3\Api\IClassMap;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Dto\PendingChatbotTurn;
use Chatbot\Output\ChatbotTurnPrepareOutput;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Test\Service\ChatbotServiceRegistryTestDouble;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotTurnPrepareOutputTest extends TestCase {

	public function testPreparedTurnReturnsSingleUseStreamUrl(): void {
		$stored = null;
		$store = $this->createStub(IChatbotTurnRequestStore::class);
		$store->method('store')->willReturnCallback(static function(PendingChatbotTurn $turn) use (&$stored): string {
			$stored = $turn;
			return str_repeat('a', 48);
		});
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturnCallback(static function(array $target, array $parameters): string {
			return '/stream?name=' . $target['name'] . '&id=' . $parameters['id'];
		});
		$output = new ChatbotTurnPrepareOutput(
			$this->request(['service' => ' CHATBOTSERVICEREGISTRYTESTDOUBLE ', 'payload' => ['prompt' => '  Hello ', 'conversation_channel_id' => 'forged-channel', 'turn_id' => 'turn@id']]),
			$store,
			$this->registry(),
			$links
		);
		$data = json_decode($output->getOutput('json'), true);
		$this->assertTrue($data['ok']);
		$this->assertSame(str_repeat('a', 48), $data['id']);
		$this->assertSame('/stream?name=chatbotturnstream&id=' . str_repeat('a', 48), $data['stream']);
		$this->assertSame('chatbotserviceregistrytestdouble', $stored?->getServiceId());
		$this->assertSame('Hello', $stored?->getRequest()->getPrompt());
		$this->assertSame('turnid', $stored?->getRequest()->getTurnId());
		$this->assertArrayNotHasKey('conversation_channel_id', $stored->toArray()['payload']);
		$this->assertSame('chatbotturnprepare', ChatbotTurnPrepareOutput::getName());
	}

	public function testMissingServiceAndPayloadAreRejectedWithoutStorage(): void {
		foreach ([[], ['service' => ' '], ['service' => 'chatbotserviceregistrytestdouble', 'payload' => false]] as $payload) {
			$data = json_decode($this->createPrepareEndpoint($payload)->getOutput(), true);
			$this->assertFalse($data['ok']);
			$this->assertSame('Missing chatbot service or payload.', $data['error']);
		}
	}

	public function testUnknownServiceIsNotInvoked(): void {
		$data = json_decode($this->createPrepareEndpoint(['service' => 'unknown', 'payload' => ['prompt' => 'Hello']])->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Unknown chatbot service: unknown', $data['error']);
	}

	public function testEmptyTurnIsRejectedButStructuredResumeIsAccepted(): void {
		$data = json_decode($this->createPrepareEndpoint(['service' => 'chatbotserviceregistrytestdouble', 'payload' => ['prompt' => '  ']])->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Chatbot turn requires a prompt or resume payload.', $data['error']);
		$data = json_decode($this->createPrepareEndpoint(['service' => 'chatbotserviceregistrytestdouble', 'payload' => ['resume_handle' => 'resume-1']])->getOutput(), true);
		$this->assertTrue($data['ok']);
	}

	public function testStoreExceptionsAreReturnedAsControlledErrors(): void {
		$store = $this->createStub(IChatbotTurnRequestStore::class);
		$store->method('store')->willReturnCallback(static function(): never { throw new RuntimeException('Session unavailable'); });
		$data = json_decode((new ChatbotTurnPrepareOutput(
			$this->request(['service' => 'chatbotserviceregistrytestdouble', 'payload' => ['prompt' => 'Hello']]),
			$store,
			$this->registry(),
			$this->createStub(ILinkTargetService::class)
		))->getOutput(), true);
		$this->assertFalse($data['ok']);
		$this->assertSame('Session unavailable', $data['error']);
	}

	private function createPrepareEndpoint(array $payload): ChatbotTurnPrepareOutput {
		$store = $this->createStub(IChatbotTurnRequestStore::class);
		$store->method('store')->willReturn(str_repeat('f', 48));
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/stream');
		return new ChatbotTurnPrepareOutput($this->request($payload), $store, $this->registry(), $links);
	}

	private function request(array $payload): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn($payload);
		return $request;
	}

	private function registry(): ChatbotServiceRegistry {
		$map = $this->createStub(IClassMap::class);
		$map->method('getInstancesByInterface')->willReturn([new ChatbotServiceRegistryTestDouble()]);
		return new ChatbotServiceRegistry($map);
	}
}
