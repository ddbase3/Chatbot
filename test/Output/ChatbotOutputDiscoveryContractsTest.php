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
use Base3\AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use Base3\AssistantFoundation\Api\ITextToSpeechService;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IOutput;
use Base3\CoreFoundation\Api\IOutputSchemaProvider;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\Chatbot\Api\IChatbotTurnRequestStore;
use Base3\Chatbot\Output\ChatbotConversationActivateOutput;
use Base3\Chatbot\Output\ChatbotConversationCreateOutput;
use Base3\Chatbot\Output\ChatbotConversationDeleteOutput;
use Base3\Chatbot\Output\ChatbotConversationMaterializeOutput;
use Base3\Chatbot\Output\ChatbotConversationRenameOutput;
use Base3\Chatbot\Output\ChatbotConversationStateOutput;
use Base3\Chatbot\Output\ChatbotConversationTitleOutput;
use Base3\Chatbot\Output\ChatbotTurnCancelOutput;
use Base3\Chatbot\Output\ChatbotTurnPrepareOutput;
use Base3\Chatbot\Output\ChatbotTurnStreamOutput;
use Base3\Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use Base3\Chatbot\Output\TextToSpeechOutput;
use Base3\Chatbot\Service\ChatbotConversationChannelResolver;
use Base3\Chatbot\Service\ChatbotConversationService;
use Base3\Chatbot\Service\ChatbotOpeningMessageService;
use Base3\Chatbot\Service\ChatbotServiceRegistry;
use Base3\Chatbot\Service\ChatbotSettingsService;
use Base3\Chatbot\Service\ChatbotTurnCancellationService;
use Base3\Chatbot\Service\ChatbotTurnResponder;
use Base3\Chatbot\Service\SessionChatbotConversationDraftStore;
use Base3\Chatbot\Test\Service\ChatbotDraftSessionStub;
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
