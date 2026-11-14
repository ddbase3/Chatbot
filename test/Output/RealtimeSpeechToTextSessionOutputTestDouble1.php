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

use Base3\AssistantFoundation\Api\IRealtimeSpeechToTextSessionService;
use Base3\AssistantFoundation\Dto\RealtimeSpeechToTextSession;
use Base3\AssistantFoundation\Dto\RealtimeSpeechToTextSessionRequest;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Output\RealtimeSpeechToTextSessionOutput;
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
