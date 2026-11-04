<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\Service\HttpTextToSpeechStream;
use LogicException;
use PHPUnit\Framework\TestCase;

final class HttpTextToSpeechStreamTest extends TestCase {

	public function testBufferingStreamAccumulatesBinaryAudioInOrder(): void {
		$stream = new HttpTextToSpeechStream(false);
		$this->assertFalse($stream->hasStarted());
		$this->assertFalse($stream->isCancelled());
		$this->assertSame('', $stream->getOutput());
		$stream->start(' audio/mpeg ');
		$stream->write("\x00\x01");
		$stream->write('binary');
		$stream->write('');
		$this->assertTrue($stream->hasStarted());
		$this->assertSame("\x00\x01binary", $stream->getOutput());
	}

	public function testStartingTwiceDoesNotEraseBufferedBytes(): void {
		$stream = new HttpTextToSpeechStream(false);
		$stream->start('audio/wav');
		$stream->write('first');
		$stream->start('');
		$stream->write('second');
		$this->assertSame('firstsecond', $stream->getOutput());
	}

	public function testEmptyAudioBeforeStartingIsIgnored(): void {
		$stream = new HttpTextToSpeechStream(false);
		$stream->write('');
		$this->assertSame('', $stream->getOutput());
	}

	public function testAudioBeforeStartIsRejected(): void {
		$stream = new HttpTextToSpeechStream(false);
		$this->expectException(LogicException::class);
		$stream->write('not-started');
	}

	public function testEmptyMimeTypeIsRejectedWithoutStarting(): void {
		$stream = new HttpTextToSpeechStream(false);
		try {
			$stream->start('  ');
			$this->fail('Empty MIME type was accepted.');
		} catch (LogicException) {
			$this->assertFalse($stream->hasStarted());
			$this->assertSame('', $stream->getOutput());
		}
	}
}
