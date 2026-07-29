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

use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Dto\AgentTextTaskRequest;
use Base3\Language\Api\ILanguage;

/**
 * Creates the independent heading and first assistant message of a new chat.
 */
final class ChatbotOpeningMessageService {

	public const FIRST_MESSAGE_MODE_NONE = 'none';
	public const FIRST_MESSAGE_MODE_RANDOM = 'random';
	public const FIRST_MESSAGE_MODE_CONTEXTUAL_AI = 'contextual_ai';

	private const FIRST_MESSAGE_MODES = [
		self::FIRST_MESSAGE_MODE_NONE,
		self::FIRST_MESSAGE_MODE_RANDOM,
		self::FIRST_MESSAGE_MODE_CONTEXTUAL_AI
	];

	public function __construct(
		private readonly IAgentTextTaskService $textTaskService,
		private readonly ChatbotSettingsService $settingsService,
		private readonly ILanguage $language
	) {}

	public static function getName(): string {
		return 'chatbotopeningmessageservice';
	}

	/**
	 * Returns one randomly selected main heading.
	 *
	 * The heading is outside the conversation. With one configured heading the
	 * result is effectively fixed; with no configured heading no heading exists.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $reference
	 */
	public function createHeading(array $settings, array $reference = []): string {
		$headings = $this->normalizeMessages($settings['main_headings'] ?? []);
		if ($headings === []) {
			return '';
		}

		return $headings[array_rand($headings)];
	}

	/** @param array<string,mixed> $settings */
	public function getFirstMessageMode(array $settings): string {
		return $this->normalizeFirstMessageMode(
			(string)($settings['first_message_mode'] ?? self::FIRST_MESSAGE_MODE_NONE)
		);
	}

	/**
	 * Returns the first real assistant message of a new conversation.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $reference
	 */
	public function createAssistantMessage(array $settings, array $reference = []): string {
		$mode = $this->getFirstMessageMode($settings);
		if ($mode === self::FIRST_MESSAGE_MODE_NONE) {
			return '';
		}
		if ($mode === self::FIRST_MESSAGE_MODE_CONTEXTUAL_AI) {
			return $this->createContextualMessage($settings, $reference);
		}

		$messages = $this->normalizeMessages($settings['first_messages'] ?? []);
		if ($messages === []) {
			throw new \RuntimeException('Random first-message mode requires at least one message.');
		}

		return $messages[array_rand($messages)];
	}

	/** @param array<string,mixed> $settings @param array<string,mixed> $reference */
	private function createContextualMessage(array $settings, array $reference): string {
		$now = new \DateTimeImmutable('now');
		$language = trim((string)($settings['default_lang'] ?? ''));
		if ($language === '' || $language === 'auto') {
			$language = trim($this->language->getLanguage());
		}
		if ($language === '') {
			$language = 'en';
		}

		$context = [
			'current_datetime' => $now->format(DATE_ATOM),
			'current_date' => $now->format('Y-m-d'),
			'current_time' => $now->format('H:i:s'),
			'timezone' => $now->getTimezone()->getName(),
			'language' => $language,
			'reference' => $reference
		];
		$referenceJson = json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($referenceJson)) {
			$referenceJson = '{}';
		}

		$result = $this->textTaskService->executeTextTask(new AgentTextTaskRequest(
			$this->settingsService->getAgentConfiguration($settings),
			'chat-opening-message',
			'Create one concise, welcoming first assistant message for a chatbot conversation. '
				. 'Write plain text only, in the requested language. '
				. 'Mention only capabilities that are explicitly available in the supplied capability catalog. '
				. 'Do not claim that an action has already been performed.',
			"Language: {$language}\n"
				. "Current date and time: {$context['current_datetime']}\n"
				. "Current page context: {$referenceJson}\n"
				. 'Write one short first assistant message that helps the user understand what this chatbot can assist with.',
			$context,
			true,
			true
		));

		$message = $this->normalizePlainText($result->getContent(), 500);
		if ($message === '') {
			throw new \RuntimeException('Contextual chatbot start task returned an empty assistant message.');
		}

		return $message;
	}

	private function normalizeFirstMessageMode(string $mode): string {
		$mode = strtolower(trim($mode));
		return in_array($mode, self::FIRST_MESSAGE_MODES, true)
			? $mode
			: self::FIRST_MESSAGE_MODE_NONE;
	}

	/** @return array<int,string> */
	private function normalizeMessages(mixed $value): array {
		if (is_string($value)) {
			$value = [$value];
		}
		if (!is_array($value)) {
			return [];
		}

		$result = [];
		foreach ($value as $message) {
			if (!is_scalar($message) && $message !== null) {
				continue;
			}
			$message = $this->normalizePlainText((string)$message, 500);
			if ($message !== '') {
				$result[] = $message;
			}
		}
		return array_values($result);
	}

	private function normalizePlainText(string $value, int $maxLength): string {
		$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = preg_replace('/[`*_#>~]+/u', '', $value) ?? $value;
		$value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
		if (function_exists('mb_substr')) {
			return trim(mb_substr($value, 0, $maxLength));
		}
		return trim(substr($value, 0, $maxLength));
	}
}
