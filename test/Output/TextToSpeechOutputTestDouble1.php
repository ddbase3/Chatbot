<?php declare(strict_types=1);

namespace Chatbot\Test\Output;

use AssistantFoundation\Api\ITextToSpeechService;
use AssistantFoundation\Api\ITextToSpeechStream;
use AssistantFoundation\Dto\TextToSpeechRequest;
use AssistantFoundation\Dto\TextToSpeechResult;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Output\TextToSpeechOutput;
use MediaFoundation\Model\AudioMedia;
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
