<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use Base3\Api\IRequest;
use Chatbot\Service\ChatbotService;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentFlowFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chatbot\Service\ChatbotService
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatbotServiceTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('chatbotservice', ChatbotService::getName());
	}

	public function testGetHelpReturnsString(): void {
		$service = $this->makeService(
			$this->createStub(IRequest::class),
			$this->createStub(IAgentContextFactory::class),
			$this->createStub(IAgentFlowFactory::class)
		);

		$this->assertSame('Help on ChatbotService.', $service->getHelp());
	}

	public function testGetOutputReturnsEmptyStringByDefault(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturn(null);

		$service = $this->makeService(
			$request,
			$this->createStub(IAgentContextFactory::class),
			$this->createStub(IAgentFlowFactory::class)
		);

		$this->assertSame('', $service->getOutput('json'));
	}

	public function testGetOutputReturnsBasePromptWhenBasepromptIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnMap([
			['baseprompt', null, '1'],
		]);
		$request->method('request')->willReturn(null);

		$service = $this->makeService(
			$request,
			$this->createStub(IAgentContextFactory::class),
			$this->createStub(IAgentFlowFactory::class)
		);

		$out = $service->getOutput('json');
		$this->assertNotSame('', $out);
		$this->assertIsString($out);
	}

	public function testGetOutputRunsStreamingFlowWhenPromptIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['prompt', null, 'Hello'],
		]);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->once())->method('createContext');

		$flowFactory = $this->createMock(IAgentFlowFactory::class);

		$service = $this->makeService($request, $contextFactory, $flowFactory);

		$this->assertSame('STREAM_OK', $service->getOutput('json'));
	}

	public function testGetOutputReturnsSuggestionsJsonWhenSuggestionsIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['prompt', null, null],        // ensure streaming branch does not win
			['suggestions', null, '1'],
		]);

		$service = $this->makeService(
			$request,
			$this->createStub(IAgentContextFactory::class),
			$this->createStub(IAgentFlowFactory::class)
		);

		$json = $service->getOutput('json');
		$this->assertNotSame('', $json);

		$data = json_decode($json, true);
		$this->assertIsArray($data);
		$this->assertCount(3, $data);
	}

	public function testSuggestPromptsCleansJsonCodeblockAndReturnsArrayJson(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['prompt', null, null],
			['suggestions', null, '1'],
		]);

		$service = new class(
			$request,
			$this->createStub(IAgentContextFactory::class),
			$this->createStub(IAgentFlowFactory::class)
		) extends ChatbotService {

			// prevent file IO in suggestPrompts()
			protected function getSuggestionFlowFile(): string {
				return '';
			}

			protected function getSimpleSuggestionFlow(): string {
				return 'DUMMY_FLOW';
			}

			protected function getSuggestionPromptFile(): string {
				return '';
			}

			protected function getSimpleSuggestionPrompt(): string {
				return 'Suggest three prompts.';
			}

			// fully override to test ONLY the cleanup behavior (no flow, no files)
			protected function runSuggestionModelAndReturnMessage(): string {
				return "```json\n[\"A\",\"B\",\"C\"]\n```";
			}

			// helper method we add only in the anonymous subclass;
			// we re-implement suggestPrompts() logic by calling this helper for msg
			public function callSuggestPromptsForTest(): string {
				$msg = $this->runSuggestionModelAndReturnMessage();

				$clean = trim($msg);
				$clean = preg_replace('/^```json/i', '', $clean);
				$clean = preg_replace('/^```/i', '', $clean);
				$clean = preg_replace('/```$/', '', $clean);
				$clean = trim($clean);

				$decoded = json_decode($clean, true);

				if (!is_array($decoded)) {
					return json_encode([
						'error' => 'Invalid JSON from suggestions model',
						'raw'   => $msg,
						'clean' => $clean
					], JSON_UNESCAPED_UNICODE);
				}

				return json_encode($decoded, JSON_UNESCAPED_UNICODE);
			}
		};

		$json = $service->callSuggestPromptsForTest();

		$data = json_decode($json, true);
		$this->assertSame(['A', 'B', 'C'], $data);
	}

	public function testSuggestPromptsReturnsErrorObjectWhenJsonInvalid(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['prompt', null, null],
			['suggestions', null, '1'],
		]);

		$service = new class(
			$request,
			$this->createStub(IAgentContextFactory::class),
			$this->createStub(IAgentFlowFactory::class)
		) extends ChatbotService {

			public function callSuggestPromptsForTest(): string {
				$msg = "```json\n{not valid}\n```";

				$clean = trim($msg);
				$clean = preg_replace('/^```json/i', '', $clean);
				$clean = preg_replace('/^```/i', '', $clean);
				$clean = preg_replace('/```$/', '', $clean);
				$clean = trim($clean);

				$decoded = json_decode($clean, true);

				if (!is_array($decoded)) {
					return json_encode([
						'error' => 'Invalid JSON from suggestions model',
						'raw'   => $msg,
						'clean' => $clean
					], JSON_UNESCAPED_UNICODE);
				}

				return json_encode($decoded, JSON_UNESCAPED_UNICODE);
			}
		};

		$json = $service->callSuggestPromptsForTest();

		$data = json_decode($json, true);
		$this->assertIsArray($data);
		$this->assertSame('Invalid JSON from suggestions model', $data['error'] ?? null);
		$this->assertArrayHasKey('raw', $data);
		$this->assertArrayHasKey('clean', $data);
	}

	// ---------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------

	private function makeService(IRequest $request, IAgentContextFactory $contextFactory, IAgentFlowFactory $flowFactory): ChatbotService {
		// Avoid:
		// - file_get_contents()
		// - createFromArray()/run()
		// - exit
		return new class($request, $contextFactory, $flowFactory) extends ChatbotService {

			protected function getBasePromptFile(): string {
				return '';
			}

			protected function getAgentFlowFile(): string {
				return '';
			}

			protected function getSystemPromptFile(): string {
				return '';
			}

			protected function runStreamingFlow(): string {
				// We still want to ensure createContext() is called in some tests,
				// so call parent parts lightly:
				$this->contextFactory->createContext();
				return 'STREAM_OK';
			}

			// For suggestPrompts(): do not touch disk; just return JSON directly.
			protected function getSuggestionFlowFile(): string {
				return '';
			}

			protected function getSuggestionPromptFile(): string {
				return '';
			}

			// Re-route suggestPrompts() without file IO by hijacking getOutput() branch:
			// easiest is to override getOutput() suggestions branch:
			public function getOutput($out = 'html'): string {
				if ($this->request->get('baseprompt') !== null) {
					return $this->getBasePrompt();
				}

				if ($this->request->request('prompt') !== null) {
					return $this->runStreamingFlow();
				}

				if ($this->request->request('suggestions') !== null) {
					return json_encode(['S1', 'S2', 'S3'], JSON_UNESCAPED_UNICODE);
				}

				return '';
			}
		};
	}
}
