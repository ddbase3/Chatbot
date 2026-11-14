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

namespace Base3\Chatbot\Test\Output;

use Base3\AssistantFoundation\Api\IAgentConversationService;
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\AssistantFoundation\Api\IAgentSuspensionRepository;
use Base3\AssistantFoundation\Api\IAgentTextTaskService;
use Base3\AssistantFoundation\Dto\AgentConversationRequest;
use Base3\AssistantFoundation\Dto\AgentConversationState;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Output\ChatbotConversationStateOutput;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotConversationService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use Base3\Chatbot\Test\Service\ChatbotDraftSessionStub;
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
