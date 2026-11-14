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

namespace Base3\Chatbot\Test\Service;

use Base3\Chatbot\Service\HttpTextToSpeechStream;
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
