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
