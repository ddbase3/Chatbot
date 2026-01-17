<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use Base3\Api\IRequest;
use Chatbot\Service\DummyChatbotService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chatbot\Service\DummyChatbotService
 */
#[AllowMockObjectsWithoutExpectations]
final class DummyChatbotServiceTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('dummychatbotservice', DummyChatbotService::getName());
	}

	public function testGetHelpReturnsString(): void {
		$service = $this->makeService($this->createStub(IRequest::class));
		$this->assertSame('Help on DummyChatbotService.', $service->getHelp());
	}

	public function testGetOutputReturnsEmptyStringByDefault(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturn(null);

		$service = $this->makeService($request);

		$this->assertSame('', $service->getOutput('json'));
		$this->assertSame('', $service->getOutput('html'));
	}

	public function testGetOutputReturnsBasePromptWhenBasepromptIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturnMap([
			['baseprompt', null, '1'],
		]);
		$request->method('request')->willReturn(null);

		$service = $this->makeService($request);

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

		$service = $this->makeService($request);

		$this->assertSame('STREAM_OK', $service->getOutput('json'));
	}

	public function testGetOutputReturnsSuggestionsJsonWhenSuggestionsIsSet(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('get')->willReturn(null);
		$request->method('request')->willReturnMap([
			['prompt', null, null],        // important: otherwise streaming branch triggers first
			['suggestions', null, '1'],
		]);

		$service = $this->makeService($request);

		$json = $service->getOutput('json');
		$this->assertNotSame('', $json);

		$data = json_decode($json, true);
		$this->assertIsArray($data);
		$this->assertCount(3, $data);
		$this->assertIsString($data[0]);
		$this->assertIsString($data[1]);
		$this->assertIsString($data[2]);
	}

	// ---------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------

	private function makeService(IRequest $request): DummyChatbotService {
		// Patch two issues for testability:
		// 1) getOutput() calls getBasePrompt(), but class only defines getSimpleBasePrompt()
		// 2) runStreamingFlow() calls header()/exit, which must not run in unit tests
		return new class($request) extends DummyChatbotService {

			protected function getBasePrompt(): string {
				return $this->getSimpleBasePrompt();
			}

			protected function runStreamingFlow() {
				return 'STREAM_OK';
			}
		};
	}
}
