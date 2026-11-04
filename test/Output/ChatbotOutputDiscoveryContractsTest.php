<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Api\ITextToSpeechService;
use Base3\Api\IClassMap;
use Base3\Api\IOutput;
use Base3\Api\IOutputSchemaProvider;
use Base3\Api\IRequest;
use Base3\Language\Api\ILanguage;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Chatbot\Api\IChatbotTurnRequestStore;
use Chatbot\Output\ChatbotConversationActivateOutput;
use Chatbot\Output\ChatbotConversationCreateOutput;
use Chatbot\Output\ChatbotConversationDeleteOutput;
use Chatbot\Output\ChatbotConversationMaterializeOutput;
use Chatbot\Output\ChatbotConversationRenameOutput;
use Chatbot\Output\ChatbotConversationStateOutput;
use Chatbot\Output\ChatbotConversationTitleOutput;
use Chatbot\Output\ChatbotTurnCancelOutput;
use Chatbot\Output\ChatbotTurnPrepareOutput;
use Chatbot\Output\ChatbotTurnStreamOutput;
use Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use Chatbot\Output\TextToSpeechOutput;
use Chatbot\Service\ChatbotConversationChannelResolver;
use Chatbot\Service\ChatbotConversationService;
use Chatbot\Service\ChatbotOpeningMessageService;
use Chatbot\Service\ChatbotServiceRegistry;
use Chatbot\Service\ChatbotSettingsService;
use Chatbot\Service\ChatbotTurnCancellationService;
use Chatbot\Service\ChatbotTurnResponder;
use Chatbot\Service\SessionChatbotConversationDraftStore;
use Chatbot\Test\Service\ChatbotDraftSessionStub;
use PHPUnit\Framework\TestCase;

final class ChatbotOutputDiscoveryContractsTest extends TestCase {

	public function testTransportOutputNamesRemainDistinctClassmapIdentifiers(): void {
		$expected = [
			ChatbotTurnCancelOutput::class => 'chatbotturncancel',
			ChatbotTurnPrepareOutput::class => 'chatbotturnprepare',
			ChatbotTurnStreamOutput::class => 'chatbotturnstream',
			RealtimeSpeechToTextSessionOutput::class => 'realtimespeechtotextsession',
			TextToSpeechOutput::class => 'texttospeech'
		];

		foreach ($expected as $class => $name) {
			$this->assertSame($name, $class::getName());
			$this->assertSame(1, preg_match('/^[a-z][a-z0-9._-]*$/', $name));
			$this->assertTrue(is_subclass_of($class, IOutput::class));
		}
		$this->assertCount(count($expected), array_unique(array_values($expected)));
	}

	public function testStreamOutputDescribesItsSingleUseSsePurpose(): void {
		$output = new ChatbotTurnStreamOutput(
			$this->createStub(IRequest::class),
			$this->createStub(IChatbotTurnRequestStore::class),
			new ChatbotServiceRegistry($this->createStub(IClassMap::class)),
			$this->responder()
		);
		$this->assertInstanceOf(IOutput::class, $output);
		$this->assertStringContainsString('single-use', $output->getHelp());
		$this->assertStringContainsString('Server-Sent Events', $output->getHelp());
	}

	public function testTurnPreparationAndCancellationHaveDistinctDocumentedEffects(): void {
		$cancel = new ChatbotTurnCancelOutput(
			$this->createStub(IRequest::class),
			new ChatbotTurnCancellationService($this->createStub(IStateStore::class))
		);
		$prepare = new ChatbotTurnPrepareOutput(
			$this->createStub(IRequest::class),
			$this->createStub(IChatbotTurnRequestStore::class),
			new ChatbotServiceRegistry($this->createStub(IClassMap::class)),
			$this->createStub(ILinkTargetService::class)
		);
		$this->assertStringContainsString('cancellation', $cancel->getHelp());
		$this->assertStringContainsString('single-use SSE', $prepare->getHelp());
		$this->assertNotSame($cancel->getHelp(), $prepare->getHelp());
	}

	public function testSpeechOutputsDescribeTheirDifferentBrowserContracts(): void {
		$speechToText = new RealtimeSpeechToTextSessionOutput(
			$this->createStub(IRequest::class),
			$this->createStub(ISettingsStore::class),
			$this->createStub(IRealtimeSpeechToTextSessionService::class)
		);
		$textToSpeech = new TextToSpeechOutput(
			$this->createStub(IRequest::class),
			$this->createStub(ISettingsStore::class),
			$this->createStub(ITextToSpeechService::class)
		);
		$this->assertStringContainsString('browser session', $speechToText->getHelp());
		$this->assertStringContainsString('Streams audio', $textToSpeech->getHelp());
	}

	public function testAllConversationEndpointsExposeOneConsistentJsonSchemaAndHelp(): void {
		$service = $this->conversationService();
		$classes = [
			ChatbotConversationActivateOutput::class,
			ChatbotConversationCreateOutput::class,
			ChatbotConversationDeleteOutput::class,
			ChatbotConversationMaterializeOutput::class,
			ChatbotConversationRenameOutput::class,
			ChatbotConversationStateOutput::class,
			ChatbotConversationTitleOutput::class
		];
		foreach ($classes as $class) {
			$output = new $class($this->createStub(IRequest::class), $service);
			$this->assertInstanceOf(IOutput::class, $output);
			$this->assertInstanceOf(IOutputSchemaProvider::class, $output);
			$this->assertSame('JSON endpoint for chatbot conversation state.', $output->getHelp());
			$schemas = $output->getOutputSchemas();
			$this->assertSame('object', $schemas['json']['type']);
			$this->assertSame(['ok'], $schemas['json']['required']);
			$this->assertSame('boolean', $schemas['json']['properties']['ok']['type']);
			$this->assertSame('object', $schemas['json']['properties']['error']['type']);
			$this->assertSame('string', $schemas['json']['properties']['error']['properties']['code']['type']);
		}
	}

	private function responder(): ChatbotTurnResponder {
		return new ChatbotTurnResponder(
			$this->createStub(ILanguage::class),
			new ChatbotTurnCancellationService($this->createStub(IStateStore::class)),
			$this->createStub(ILogger::class)
		);
	}

	private function conversationService(): ChatbotConversationService {
		$store = $this->createStub(ISettingsStore::class);
		$settings = new ChatbotSettingsService($store, $this->createStub(IAgentRuntimeSelector::class));
		$tasks = $this->createStub(IAgentTextTaskService::class);
		return new ChatbotConversationService(
			$this->createStub(IAgentConversationService::class),
			$tasks,
			$this->createStub(IAgentSuspensionRepository::class),
			$settings,
			new ChatbotConversationChannelResolver(),
			new ChatbotOpeningMessageService($tasks, $settings, $this->createStub(ILanguage::class)),
			new SessionChatbotConversationDraftStore(new ChatbotDraftSessionStub()),
			$this->createStub(ILogger::class)
		);
	}
}
