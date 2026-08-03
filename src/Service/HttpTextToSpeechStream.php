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

namespace Chatbot\Service;

use AssistantFoundation\Api\ITextToSpeechStream;
use LogicException;

final class HttpTextToSpeechStream implements ITextToSpeechStream {

	private bool $started = false;
	private string $output = '';

	public function __construct(
		private readonly bool $streamOutput
	) {}

	public function start(string $mimeType, array $metadata = []): void {
		if($this->started) {
			return;
		}

		$mimeType = trim($mimeType);
		if($mimeType === '') {
			throw new LogicException('Text-to-speech stream requires a MIME type.');
		}

		$this->started = true;
		if(!$this->streamOutput) {
			return;
		}

		while(ob_get_level() > 0) {
			@ob_end_clean();
		}

		if(function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');
		@ini_set('output_buffering', 'off');

		if(!headers_sent()) {
			header_remove('Content-Type');
			header_remove('Content-Length');
			header('Content-Type: ' . $mimeType);
			header('Cache-Control: no-store, private, no-transform');
			header('X-Accel-Buffering: no');
			header_remove('Content-Encoding');
			header('X-Content-Type-Options: nosniff');
		}

		if(function_exists('ob_implicit_flush')) {
			ob_implicit_flush(true);
		}

		@flush();
	}

	public function write(string $audio): void {
		if($audio === '' || $this->isCancelled()) {
			return;
		}
		if(!$this->started) {
			throw new LogicException('Text-to-speech stream was not started.');
		}

		if(!$this->streamOutput) {
			$this->output .= $audio;
			return;
		}

		echo $audio;
		@flush();
	}

	public function isCancelled(): bool {
		return $this->streamOutput && connection_aborted() === 1;
	}

	public function hasStarted(): bool {
		return $this->started;
	}

	public function getOutput(): string {
		return $this->output;
	}
}
