<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Output\ChatbotConversationStateOutput;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Test\Service\ChatbotDraftSessionStub;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationJsonBoundaryCompletionTest extends TestCase {

	public function testJsonBodyReplacesRequestReferenceBeforeRuntimeDispatch(): void {
		$captured = [];
		$runtime = $this->runtime($captured);
		$body = ['config_group' => 'chatbot', 'config_name' => 'example', 'reference' => ['source' => 'form']];
		$json = ['reference' => ['source' => 'json', 'id' => 7]];
		$response = $this->execute($runtime, $body, $json);
		$this->assertTrue($response['ok']);
		$this->assertSame(['source' => 'json', 'id' => 7], $captured['reference']);
	}

	public function testEmptyJsonObjectLeavesRegularRequestDataUntouched(): void {
		$captured = [];
		$runtime = $this->runtime($captured);
		$response = $this->execute($runtime, ['config_group' => 'chatbot', 'config_name' => 'example', 'reference' => ['source' => 'body']], []);
		$this->assertTrue($response['ok']);
		$this->assertSame(['source' => 'body'], $captured['reference']);
	}

	public function testQueryIdentityCannotBeOverriddenByJsonBody(): void {
		$body = ['config_group' => 'body-group', 'config_name' => 'body-name'];
		$json = ['config_group' => 'json-group', 'config_name' => 'json-name'];
		$response = $this->execute($this->createStub(IAgentConversationService::class), $body, $json, [
			'config_group' => ' trusted ', 'config_name' => ' instance '
		], false);
		$this->assertFalse($response['ok']);
		$this->assertSame('Chatbot configuration does not exist: trusted/instance', $response['error']['message']);
	}

	public function testEmptyQueryIdentityDoesNotOverrideValidRequestIdentity(): void {
		$captured = [];
		$runtime = $this->runtime($captured);
		$response = $this->execute($runtime, ['config_group' => 'chatbot', 'config_name' => 'example'], [], [
			'config_group' => '  ', 'config_name' => ''
		]);
		$this->assertTrue($response['ok']);
		$this->assertSame('chatbot:' . hash('sha256', "chatbot\0example"), $captured['conversation_channel_id']);
	}

	public function testDeepReferenceDataIsBoundedAtConversationOutputBoundary(): void {
		$captured = [];
		$runtime = $this->runtime($captured);
		$reference = ['a' => ['a' => ['a' => ['a' => ['a' => ['a' => ['secret' => 'should-not-survive']]]]]]];
		$response = $this->execute($runtime, ['config_group' => 'chatbot', 'config_name' => 'example', 'reference' => $reference]);
		$this->assertTrue($response['ok']);
		$node = $captured['reference'];
		for ($depth = 0; $depth < 6; $depth++) {
			$this->assertArrayHasKey('a', $node);
			$node = $node['a'];
		}
		$this->assertSame([], $node);
	}

	public function testJsonEncodedArrayReferenceIsAcceptedThroughPublicEndpoint(): void {
		$captured = [];
		$runtime = $this->runtime($captured);
		$response = $this->execute($runtime, ['config_group' => 'chatbot', 'config_name' => 'example', 'reference' => '["one",{"id":2}]']);
		$this->assertTrue($response['ok']);
		$this->assertSame(['one', ['id' => 2]], $captured['reference']);
	}

	private function execute(
		IAgentConversationService $runtime,
		array $body,
		array $json = [],
		array $query = [],
		bool $configured = true
	): array {
		$request = $this->createStub(IRequest::class);
		$request->method('allRequest')->willReturn($body);
		$request->method('getJsonBody')->willReturn($json);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $query[$key] ?? $default);
		$request->method('server')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'REQUEST_METHOD' ? 'GET' : $default);
		$stored = $this->createStub(ISettingsStore::class);
		$stored->method('has')->willReturn($configured);
		$stored->method('get')->willReturn([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'memory',
			'first_message_mode' => 'none'
		]);
		$settings = new ChatbotSettingsService($stored, $this->createStub(IAgentRuntimeSelector::class));
		$text = $this->createStub(IAgentTextTaskService::class);
		$service = new ChatbotConversationService(
			$runtime,
			$text,
			$this->createStub(IAgentSuspensionRepository::class),
			$settings,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $settings, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
		$data = json_decode((new ChatbotConversationStateOutput($request, $service))->getOutput(), true);
		$this->assertTrue(is_array($data));
		return $data;
	}

	private function runtime(array &$captured): IAgentConversationService {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(AgentConversationRequest $request) use (&$captured): AgentConversationState {
			$captured = $request->getContext();
			return new AgentConversationState([], null, [], 'assistant');
		});
		return $runtime;
	}
}

require_once dirname(__DIR__) . '/Service/ChatbotDraftSessionStub.php';
