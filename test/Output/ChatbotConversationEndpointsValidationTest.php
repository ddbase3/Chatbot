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
use Chatbot\Output\ChatbotConversationActivateOutput;
use Chatbot\Output\ChatbotConversationCreateOutput;
use Chatbot\Output\ChatbotConversationDeleteOutput;
use Chatbot\Output\ChatbotConversationMaterializeOutput;
use Chatbot\Output\ChatbotConversationRenameOutput;
use Chatbot\Output\ChatbotConversationStateOutput;
use Chatbot\Output\ChatbotConversationTitleOutput;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Test\Service\ChatbotDraftSessionStub;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationEndpointsValidationTest extends TestCase {

	public function testAllMutationEndpointsRejectGetRequests(): void {
		foreach ($this->mutationEndpoints() as $endpoint) {
			$output = new $endpoint($this->request('GET'), $this->service());
			$response = json_decode($output->getOutput(), true);
			$this->assertFalse($response['ok']);
			$this->assertSame('invalid_request', $response['error']['code']);
			$this->assertSame('Unsupported request method: GET', $response['error']['message']);
		}
	}

	public function testEveryEndpointRejectsMissingConfigurationIdentity(): void {
		foreach (array_merge([ChatbotConversationStateOutput::class], $this->mutationEndpoints()) as $endpoint) {
			$output = new $endpoint($this->request('POST'), $this->service());
			$response = json_decode($output->getOutput(), true);
			$this->assertFalse($response['ok']);
			$this->assertSame('invalid_request', $response['error']['code']);
			$this->assertSame('Missing request field: config_group', $response['error']['message']);
		}
	}

	public function testMutationEndpointsRequireConversationOrDraftId(): void {
		$input = ['config_group' => 'chatbot', 'config_name' => 'example'];
		$endpoints = [
			ChatbotConversationActivateOutput::class => 'conversation_id',
			ChatbotConversationDeleteOutput::class => 'conversation_id',
			ChatbotConversationMaterializeOutput::class => 'draft_id',
			ChatbotConversationRenameOutput::class => 'conversation_id',
			ChatbotConversationTitleOutput::class => 'conversation_id'
		];
		foreach ($endpoints as $endpoint => $field) {
			$output = new $endpoint($this->request('POST', $input), $this->service());
			$response = json_decode($output->getOutput(), true);
			$this->assertFalse($response['ok']);
			$this->assertSame('invalid_request', $response['error']['code']);
			$this->assertSame('Missing request field: ' . $field, $response['error']['message']);
		}
	}

	public function testRenameRejectsMissingTitle(): void {
		$output = new ChatbotConversationRenameOutput(
			$this->request('POST', ['config_group' => 'chatbot', 'config_name' => 'example', 'conversation_id' => 'chat-1', 'title' => []]),
			$this->service()
		);
		$response = json_decode($output->getOutput(), true);
		$this->assertFalse($response['ok']);
		$this->assertSame('invalid_request', $response['error']['code']);
		$this->assertSame('Invalid request field: title', $response['error']['message']);
	}

	public function testQueryConfigurationIdentityOverridesUntrustedJsonAndRequestFields(): void {
		$request = $this->request('GET', ['config_group' => 'forged-body', 'config_name' => 'forged-body'], ['config_group' => 'forged-json', 'config_name' => 'forged-json'], ['config_group' => 'real', 'config_name' => 'target']);
		$response = json_decode((new ChatbotConversationStateOutput($request, $this->service()))->getOutput(), true);
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_not_found', $response['error']['code']);
		$this->assertSame('Chatbot configuration does not exist: real/target', $response['error']['message']);
	}

	public function testStateGetSupportsReferenceNormalizationAndDraftResponse(): void {
		$captured = null;
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(AgentConversationRequest $request) use (&$captured): AgentConversationState {
			$captured = $request->getContext();
			return new AgentConversationState([], null, [], 'assistant');
		});
		$reference = ['course' => ['id' => 2, 'object' => new \stdClass()], 'skip' => new \stdClass()];
		$request = $this->request('GET', ['config_group' => 'chatbot', 'config_name' => 'example', 'reference' => json_encode(['course' => ['id' => 2]])], [], ['config_group' => 'chatbot', 'config_name' => 'example']);
		$output = new ChatbotConversationStateOutput($request, $this->service($runtime, true));
		$data = json_decode($output->getOutput(), true);
		$this->assertTrue($data['ok']);
		$this->assertSame(['course' => ['id' => 2]], $captured['reference']);
		$this->assertSame('chatbot:' . hash('sha256', "chatbot\0example"), $captured['conversation_channel_id']);
		$this->assertSame([], $data['data']['state']['messages']);
		$this->assertNotNull($data['data']['state']['draft']);
	}

	public function testOutputSchemaAdvertisesSuccessAndStructuredErrors(): void {
		$output = new ChatbotConversationCreateOutput($this->request('POST'), $this->service());
		$schema = $output->getOutputSchemas()['json'];
		$this->assertSame('object', $schema['type']);
		$this->assertSame(['ok'], $schema['required']);
		$this->assertSame('boolean', $schema['properties']['ok']['type']);
		$this->assertSame('object', $schema['properties']['error']['type']);
	}

	private function mutationEndpoints(): array {
		return [
			ChatbotConversationCreateOutput::class,
			ChatbotConversationActivateOutput::class,
			ChatbotConversationDeleteOutput::class,
			ChatbotConversationMaterializeOutput::class,
			ChatbotConversationRenameOutput::class,
			ChatbotConversationTitleOutput::class
		];
	}

	private function request(string $method, array $body = [], array $json = [], array $query = []): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('server')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'REQUEST_METHOD' ? $method : $default);
		$request->method('allRequest')->willReturn($body);
		$request->method('getJsonBody')->willReturn($json);
		$request->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $query[$key] ?? $default);
		return $request;
	}

	private function service(?IAgentConversationService $runtime = null, bool $configured = false): ChatbotConversationService {
		$settings = $this->createStub(ISettingsStore::class);
		$settings->method('has')->willReturn($configured);
		$settings->method('get')->willReturn(['chatbot_backend' => 'runtime:missionbay', 'memory_profile' => 'database-memory', 'first_message_mode' => 'none']);
		$configuration = new ChatbotSettingsService($settings, $this->createStub(IAgentRuntimeSelector::class));
		$text = $this->createStub(IAgentTextTaskService::class);
		return new ChatbotConversationService(
			$runtime ?? $this->createStub(IAgentConversationService::class),
			$text,
			$this->createStub(IAgentSuspensionRepository::class),
			$configuration,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $configuration, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
	}
}
