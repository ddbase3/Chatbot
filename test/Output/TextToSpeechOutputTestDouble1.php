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

use Base3\AssistantFoundation\Api\ITextToSpeechService;
use Base3\AssistantFoundation\Api\ITextToSpeechStream;
use Base3\AssistantFoundation\Dto\TextToSpeechRequest;
use Base3\AssistantFoundation\Dto\TextToSpeechResult;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Output\TextToSpeechOutput;
use Base3\MediaFoundation\Model\AudioMedia;
use PHPUnit\Framework\TestCase;

final class TextToSpeechOutputTestDouble1 implements ITextToSpeechService  {
			public ?TextToSpeechRequest $request = null;
			public int $streamCalls = 0;

			public function synthesize(TextToSpeechRequest $request): TextToSpeechResult {
				return new TextToSpeechResult(
					'audio/mpeg',
					new AudioMedia('complete', 'audio/mpeg', 0.0, 0)
				);
			}

			public function stream(
				TextToSpeechRequest $request,
				ITextToSpeechStream $stream
			): TextToSpeechResult {
				$this->request = $request;
				$this->streamCalls += 1;
				$stream->start('audio/mpeg', ['provider' => 'test']);
				$stream->write('audio-');
				$stream->write('data');

				return new TextToSpeechResult('audio/mpeg', null, ['provider' => 'test']);
			}
		}
