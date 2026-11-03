<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use AssistantFoundation\Dto\RealtimeSpeechToTextSession;
use AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Output\RealtimeSpeechToTextSessionOutput;
use PHPUnit\Framework\TestCase;

final class RealtimeSpeechToTextSessionOutputTestDouble1 implements IRealtimeSpeechToTextSessionService  {
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
		}
