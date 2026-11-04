<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Logger\AbstractLogger;

final class ChatbotSseProbeLogger extends AbstractLogger {

	public function logLevel(string $level, string|\Stringable $message, array $context = []): void {
	}
}
