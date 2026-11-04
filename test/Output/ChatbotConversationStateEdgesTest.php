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
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotConversationStateEdgesTest extends TestCase {

	public function testMissingConversationMemoryIsClassifiedAsUnavailable(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->invokeState($runtime, ['memory_profile' => '']);
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_unavailable', $response['error']['code']);
		$this->assertStringContainsString('not configured', $response['error']['message']);
	}

	public function testMissingConfiguredInstanceIsClassifiedAsNotFound(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->invokeState($runtime, [], false);
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_not_found', $response['error']['code']);
		$this->assertStringContainsString('does not exist', $response['error']['message']);
	}

	public function testRuntimeRepositoryFailureHasControlledConversationErrorCode(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(): never {
			throw new RuntimeException('Repository temporarily unavailable');
		});
		$response = $this->invokeState($runtime);
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_error', $response['error']['code']);
		$this->assertSame('Repository temporarily unavailable', $response['error']['message']);
	}

	public function testUnknownRuntimeFailureIsNotMisreportedAsInvalidInput(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(): never {
			throw new LogicException('Unexpected runtime invariant');
		});
		$response = $this->invokeState($runtime);
		$this->assertFalse($response['ok']);
		$this->assertSame('internal_error', $response['error']['code']);
	}

	public function testNativeRuntimeNotFoundErrorGetsSpecificCode(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturnCallback(static function(): never {
			throw new RuntimeException('Requested conversation not found');
		});
		$response = $this->invokeState($runtime);
		$this->assertFalse($response['ok']);
		$this->assertSame('conversation_not_found', $response['error']['code']);
	}

	public function testArrayConfigurationIdentityIsRejectedBeforeServiceCalls(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->invokeState($runtime, [], true, 'GET', [
			'config_group' => ['forged'], 'config_name' => 'sidebar'
		]);
		$this->assertFalse($response['ok']);
		$this->assertSame('invalid_request', $response['error']['code']);
		$this->assertSame('Invalid request field: config_group', $response['error']['message']);
	}

	public function testUnsupportedMethodIsRejectedBeforeRuntimeAccess(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->never())->method('getState');
		$response = $this->invokeState($runtime, [], true, 'PATCH');
		$this->assertFalse($response['ok']);
		$this->assertSame('invalid_request', $response['error']['code']);
		$this->assertSame('Unsupported request method: PATCH', $response['error']['message']);
	}

	public function testLowercaseAndWhitespaceHttpMethodIsNormalized(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->emptyState());
		$response = $this->invokeState($runtime, [], true, ' get ');
		$this->assertTrue($response['ok']);
		$this->assertSame([], $response['data']['state']['conversations']);
	}

	public function testUnspecifiedMethodRetainsExistingEmbeddedInvocationBehavior(): void {
		$runtime = $this->createStub(IAgentConversationService::class);
		$runtime->method('getState')->willReturn($this->emptyState());
		$this->assertTrue($this->invokeState($runtime, [], true, '')['ok']);
	}

	public function testInvalidJsonReferenceIsDiscardedInsteadOfReachingAgentRuntime(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturnCallback(function(AgentConversationRequest $request): AgentConversationState {
			$this->assertSame([], $request->getContext()['reference']);
			return $this->emptyState();
		});
		$response = $this->invokeState($runtime, [], true, 'GET', ['reference' => '{malformed']);
		$this->assertTrue($response['ok']);
	}

	public function testObjectValuesInsideNestedReferenceAreRemovedAtTheRequestBoundary(): void {
		$runtime = $this->createMock(IAgentConversationService::class);
		$runtime->expects($this->once())->method('getState')->willReturnCallback(function(AgentConversationRequest $request): AgentConversationState {
			$this->assertSame([
				'course' => ['id' => 17, 'tags' => ['one', null, true]]
			], $request->getContext()['reference']);
			return $this->emptyState();
		});
		$response = $this->invokeState($runtime, [], true, 'POST', [
			'reference' => ['course' => ['id' => 17, 'tags' => ['one', null, true], 'object' => new \stdClass()]]
		]);
		$this->assertTrue($response['ok']);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function invokeState(
		IAgentConversationService $runtime,
		array $settings = [],
		bool $configured = true,
		string $method = 'GET',
		array $body = []
	): array {
		$request = $this->createStub(IRequest::class);
		$request->method('allRequest')->willReturn(array_replace([
			'config_group' => 'chatbot', 'config_name' => 'sidebar'
		], $body));
		$request->method('getJsonBody')->willReturn([]);
		$request->method('server')->willReturnCallback(static fn(string $key, string $default = ''): string => $key === 'REQUEST_METHOD' ? $method : $default);
		$request->method('get')->willReturn(null);
		$output = new ChatbotConversationStateOutput(
			$request,
			$this->conversationService($runtime, $settings, $configured)
		);
		$result = json_decode($output->getOutput('json'), true);
		$this->assertTrue(is_array($result));
		return $result;
	}

	/** @param array<string,mixed> $settings */
	private function conversationService(
		IAgentConversationService $runtime,
		array $settings,
		bool $configured
	): ChatbotConversationService {
		$stored = array_replace([
			'chatbot_backend' => 'runtime:missionbay',
			'memory_profile' => 'memory',
			'first_message_mode' => 'none'
		], $settings);
		$store = $this->createStub(ISettingsStore::class);
		$store->method('has')->willReturn($configured);
		$store->method('get')->willReturn($stored);
		$settingsService = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		$text = $this->createStub(IAgentTextTaskService::class);
		return new ChatbotConversationService(
			$runtime,
			$text,
			$this->createStub(IAgentSuspensionRepository::class),
			$settingsService,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($text, $settingsService, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
	}

	private function emptyState(): AgentConversationState {
		return new AgentConversationState([], null, [], 'assistant');
	}
}

require_once dirname(__DIR__) . '/Service/ChatbotDraftSessionStub.php';
