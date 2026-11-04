<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use PHPUnit\Framework\TestCase;

/**
 * Runs the actual header/buffer-sensitive SSE transport in a child PHP CLI.
 * The normal PHPUnit output buffers remain untouched in the parent process.
 */
final class ChatbotSseTransportIntegrationTest extends TestCase {

	public function testSinkTransmitsUnicodeAndUnescapedUrlPayloads(): void {
		$events = $this->events('sink_unicode');
		$this->assertSame(['token', 'done'], array_column($events, 'name'));
		$this->assertSame('Grüße 🌍', $events[0]['payload']['text']);
		$this->assertSame('https://example.test/a/b', $events[0]['payload']['url']);
		$this->assertSame('completed', $events[1]['payload']['status']);
	}

	public function testRuntimeDoneWaitsForExplicitCompletion(): void {
		$events = $this->events('sink_pending_done');
		$this->assertSame(['token', 'done'], array_column($events, 'name'));
		$this->assertSame('before finish', $events[0]['payload']['text']);
		$this->assertSame('suspended', $events[1]['payload']['status']);
		$this->assertSame('resume-1', $events[1]['payload']['resume_handle']);
	}

	public function testExplicitTerminalStatusOverridesPendingRuntimeStatus(): void {
		$events = $this->events('sink_override_status');
		$this->assertSame(['done'], array_column($events, 'name'));
		$this->assertSame(['status' => 'error', 'marker' => 7], $events[0]['payload']);
	}

	public function testBlankPendingStatusBecomesCompletedWithoutLosingOtherData(): void {
		$events = $this->events('sink_empty_status');
		$this->assertSame([['name' => 'done', 'payload' => ['status' => 'completed', 'marker' => true]]], $events);
	}

	public function testCancellationProducesOnlyCancelledTerminalEvent(): void {
		$this->assertSame([['name' => 'done', 'payload' => ['status' => 'cancelled']]], $this->events('sink_cancelled'));
	}

	public function testRepeatedFinishAndEmissionAfterFinishDoNotDuplicateFrames(): void {
		$events = $this->events('sink_duplicate_finish');
		$this->assertSame(['token', 'done'], array_column($events, 'name'));
		$this->assertSame('first', $events[0]['payload']['text']);
		$this->assertSame('completed', $events[1]['payload']['status']);
	}

	public function testStartingTwiceKeepsMultipleTokenEventsOrdered(): void {
		$events = $this->events('sink_multitoken');
		$this->assertSame(['token', 'token', 'done'], array_column($events, 'name'));
		$this->assertSame('A', $events[0]['payload']['text']);
		$this->assertSame('B', $events[1]['payload']['text']);
	}

	public function testMalformedUtf8IsSubstitutedWithValidJsonText(): void {
		$events = $this->events('sink_invalid_utf8');
		$this->assertSame('�(', $events[0]['payload']['text']);
		$this->assertSame('completed', $events[1]['payload']['status']);
	}

	public function testLastRuntimeDonePayloadWinsBeforeCommit(): void {
		$events = $this->events('sink_last_done');
		$this->assertSame([['name' => 'done', 'payload' => ['status' => 'suspended', 'marker' => 'second']]], $events);
	}

	public function testInvalidOrExpiredTurnIdEmitsControlledBootstrapError(): void {
		$events = $this->events('stream_invalid');
		$this->assertSame(['error', 'done'], array_column($events, 'name'));
		$this->assertSame('Invalid or expired chatbot turn id.', $events[0]['payload']['message']);
		$this->assertSame('The chat stream could not be started.', $events[0]['payload']['user_message']);
		$this->assertSame('error', $events[1]['payload']['status']);
	}

	public function testUnknownServiceIsRejectedAfterClaimWithoutExecutingBackend(): void {
		$events = $this->events('stream_unknown');
		$this->assertSame(['error', 'done'], array_column($events, 'name'));
		$this->assertSame('Unknown chatbot service: unregistered-service', $events[0]['payload']['message']);
		$this->assertSame('error', $events[1]['payload']['status']);
	}

	public function testPreparedTurnRunsThroughRealStreamEndpoint(): void {
		$events = $this->events('stream_default');
		$this->assertSame(['msgid', 'token', 'done'], array_column($events, 'name'));
		$this->assertSame('test', $events[0]['payload']['id']);
		$this->assertSame('Hello stream', $events[1]['payload']['text']);
		$this->assertSame('completed', $events[2]['payload']['status']);
	}

	public function testServiceRegistryNormalizesPreparedServiceIdentity(): void {
		$events = $this->events('stream_normalized');
		$this->assertSame(['msgid', 'token', 'done'], array_column($events, 'name'));
		$this->assertSame('Hello stream', $events[1]['payload']['text']);
	}

	public function testPreparedTicketCannotBeReplayed(): void {
		$events = $this->events('stream_replay');
		$this->assertSame(['msgid', 'token', 'done', 'error', 'done'], array_column($events, 'name'));
		$this->assertSame('completed', $events[2]['payload']['status']);
		$this->assertSame('Invalid or expired chatbot turn id.', $events[3]['payload']['message']);
		$this->assertSame('error', $events[4]['payload']['status']);
	}

	public function testBackendExceptionEmitsSafeMessageErrorDetailsAndErrorCompletion(): void {
		$events = $this->events('stream_runtime_error');
		$this->assertSame(['token', 'error', 'done'], array_column($events, 'name'));
		$this->assertSame('A technical error occurred. The request could not be completed.', $events[0]['payload']['text']);
		$this->assertSame('Deliberate stream failure.', $events[1]['payload']['message']);
		$this->assertSame('RuntimeException', $events[1]['payload']['type']);
		$this->assertSame(0, $events[1]['payload']['code']);
		$this->assertSame('error', $events[2]['payload']['status']);
	}

	public function testPreviouslyEmittedTokenIsNotReplacedByGenericErrorToken(): void {
		$events = $this->events('stream_error_after_token');
		$this->assertSame(['token', 'error', 'done'], array_column($events, 'name'));
		$this->assertSame('partial', $events[0]['payload']['text']);
		$this->assertSame('Failure after token.', $events[1]['payload']['message']);
	}

	public function testRuntimeDoneIsCommittedOnlyAfterResponderEmitsMessageFallbacks(): void {
		$events = $this->events('stream_runtime_done');
		$this->assertSame(['msgid', 'token', 'done'], array_column($events, 'name'));
		$this->assertSame('message-1', $events[0]['payload']['id']);
		$this->assertSame('Complete', $events[1]['payload']['text']);
		$this->assertSame(['status' => 'completed', 'origin' => 'runtime'], $events[2]['payload']);
	}

	public function testInteractionRequiredIsEmittedOnceWithResumeData(): void {
		$events = $this->events('stream_interaction');
		$this->assertSame(['agent.interaction.required', 'done'], array_column($events, 'name'));
		$this->assertSame('interaction_required', $events[0]['payload']['type']);
		$this->assertSame('resume-1', $events[0]['payload']['resume_handle']);
		$this->assertSame('approval-1', $events[0]['payload']['interaction_requests'][0]['id']);
		$this->assertSame('waiting_for_user', $events[1]['payload']['status']);
	}

	public function testCancelledBackendReturnCommitsOnlyCancelledStatus(): void {
		$events = $this->events('stream_cancelled');
		$this->assertSame([['name' => 'done', 'payload' => ['status' => 'cancelled']]], $events);
	}

	public function testErrorResultIsDeliveredAsErrorAndTerminalStatus(): void {
		$events = $this->events('stream_error_result');
		$this->assertSame(['error', 'done'], array_column($events, 'name'));
		$this->assertSame('Provider returned an error.', $events[0]['payload']['message']);
		$this->assertSame('Provider returned an error.', $events[0]['payload']['user_message']);
		$this->assertSame('error', $events[1]['payload']['status']);
	}

	/** @return array<int,array{name:string,payload:array<string,mixed>}> */
	private function events(string $scenario): array {
		$raw = $this->executeProbe($scenario);
		$this->assertSame("\n\n", substr($raw, -2));
		$frames = explode("\n\n", substr($raw, 0, -2));
		$events = [];

		foreach ($frames as $frame) {
			$lines = explode("\n", $frame);
			$this->assertCount(2, $lines);
			$this->assertSame('event: ', substr($lines[0], 0, 7));
			$this->assertSame('data: ', substr($lines[1], 0, 6));
			$payload = json_decode(substr($lines[1], 6), true, 512, JSON_THROW_ON_ERROR);
			$this->assertTrue(is_array($payload));
			$events[] = ['name' => substr($lines[0], 7), 'payload' => $payload];
		}

		return $events;
	}

	private function executeProbe(string $scenario): string {
		$autoloaderFile = (new \ReflectionClass(\Base3\Core\Autoloader::class))->getFileName();
		$this->assertIsString($autoloaderFile);
		$pluginRoot = dirname(__DIR__, 3);
		$program = <<<'PHP'
$autoloaderFile = $argv[1];
$pluginRoot = $argv[2];
putenv('DEBUG=0');
define('DIR_ROOT', dirname($autoloaderFile, 3) . '/');
define('DIR_SRC', dirname($autoloaderFile, 2) . '/');
define('DIR_PLUGIN', $pluginRoot . '/');
define('DIR_TEST', DIR_ROOT . 'test/');
define('DIR_TMP', sys_get_temp_dir() . '/');
require $autoloaderFile;
\Base3\Core\Autoloader::register();
\Chatbot\Test\Service\ChatbotSseTransportProbe::run($argv[3]);
PHP;

		$command = [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $autoloaderFile, $pluginRoot, $scenario];
		$process = proc_open($command, [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		], $pipes, dirname($autoloaderFile, 3));
		$this->assertTrue(is_resource($process));
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), (string)$stderr);
		$this->assertSame('', $stderr);

		return (string)$stdout;
	}
}
