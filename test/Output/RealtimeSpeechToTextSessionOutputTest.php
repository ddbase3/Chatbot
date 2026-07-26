<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Dto\RealtimeSpeechToTextSession;
use AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use Base3\Api\IRequest;
use Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use PHPUnit\Framework\TestCase;

final class RealtimeSpeechToTextSessionOutputTest extends TestCase {

	public function testOutputReturnsProviderNeutralSessionPayload(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('request')->willReturnCallback(
			static fn(string $key, mixed $default = null): mixed => match($key) {
				'service' => 'mistral-realtime',
				'language' => 'de-DE',
				default => $default
			}
		);
		$service = new class implements IRealtimeSpeechToTextSessionService {
			public ?RealtimeSpeechToTextSessionRequest $request = null;

			public function createSession(RealtimeSpeechToTextSessionRequest $request): RealtimeSpeechToTextSession {
				$this->request = $request;

				return new RealtimeSpeechToTextSession(
					'mistral',
					'websocket',
					'wss://api.mistral.ai/v1/audio/transcriptions/realtime?model=test',
					'rt_test',
					'2026-07-26T12:00:00Z',
					'test',
					'pcm_s16le',
					16000,
					['targetStreamingDelayMs' => 480]
				);
			}
		};
		$output = new RealtimeSpeechToTextSessionOutput($request, $service);

		$data = json_decode($output->getOutput('json'), true);

		$this->assertSame('ok', $data['status'] ?? null);
		$this->assertSame('mistral', $data['data']['session']['provider'] ?? null);
		$this->assertSame('rt_test', $data['data']['session']['clientToken'] ?? null);
		$this->assertSame('mistral-realtime', $service->request?->getServiceId());
		$this->assertSame('de-DE', $service->request?->getLanguage());
	}
}
