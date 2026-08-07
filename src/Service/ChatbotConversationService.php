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

use AssistantFoundation\Api\IAgentConversationService;
use AssistantFoundation\Api\IAgentTextTaskService;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Dto\AgentConversation;
use AssistantFoundation\Dto\AgentConversationRequest;
use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentTextTaskRequest;
use AssistantFoundation\Dto\AgentSuspensionScope;
use Base3\Logger\Api\ILogger;
use Chatbot\Dto\ChatbotConversationClientState;
use Chatbot\Dto\ChatbotConversationDraft;
use Throwable;

/**
 * Application service for chatbot conversation state and metadata.
 */
final class ChatbotConversationService {

	public function __construct(
		private readonly IAgentConversationService $conversationService,
		private readonly IAgentTextTaskService $textTaskService,
		private readonly IAgentSuspensionRepository $suspensionRepository,
		private readonly ChatbotSettingsService $settingsService,
		private readonly ChatbotConversationChannelResolver $channelResolver,
		private readonly ChatbotOpeningMessageService $openingMessageService,
		private readonly SessionChatbotConversationDraftStore $draftStore,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'chatbotconversationservice';
	}

	public function isAvailable(string $group, string $name): bool {
		try {
			return $this->settingsService->hasConversationMemory(
				$this->settingsService->get($group, $name)
			);
		}
		catch (Throwable) {
			return false;
		}
	}

	/** @param array<string,mixed> $reference */
	public function getState(
		string $group,
		string $name,
		string $conversationId = '',
		array $reference = []
	): ChatbotConversationClientState {
		[$settings, $request, $channelId] = $this->createRequest($group, $name, $reference);
		$state = $this->conversationService->getState($request, $conversationId);
		if ($state->getActiveConversation() instanceof AgentConversation) {
			return $this->clientState($state, $channelId);
		}

		$draft = $this->draftStore->getLatest($channelId)
			?? $this->createDraft($channelId, $settings, $reference);

		return $this->draftClientState($state, $draft);
	}

	/** @param array<string,mixed> $reference */
	public function createConversation(
		string $group,
		string $name,
		array $reference = []
	): ChatbotConversationClientState {
		[$settings, $request, $channelId] = $this->createRequest($group, $name, $reference);
		if (!$this->toBool($settings['chat_history_enabled'] ?? true)) {
			throw new \RuntimeException('Chat history is disabled for this chatbot.');
		}

		$state = $this->conversationService->getState($request);
		$draft = $this->createDraft($channelId, $settings, $reference);

		return $this->draftClientState($state, $draft);
	}

	/** @param array<string,mixed> $reference */
	public function materializeConversation(
		string $group,
		string $name,
		string $draftId,
		array $reference = []
	): ChatbotConversationClientState {
		[, $request, $channelId] = $this->createRequest($group, $name, $reference);
		$draft = $this->draftStore->get($channelId, $this->requireDraftId($draftId));
		if (!$draft instanceof ChatbotConversationDraft) {
			throw new \RuntimeException('Conversation draft not found.');
		}

		$state = $this->conversationService->getState($request);
		$conversation = $this->findConversation($state, $draft->getConversationId());
		if (!$conversation instanceof AgentConversation) {
			$state = $this->conversationService->createConversation(
				$request,
				$draft->getConversationId(),
				$this->createTemporaryTitle(),
				AgentConversation::TITLE_SOURCE_TEMPORARY,
				$draft->getOpeningMessage()
			);
		}
		else {
			$state = $this->conversationService->activateConversation($request, $conversation->getId());
		}

		if ($state->getMessages() === []) {
			foreach ($draft->getMessages() as $message) {
				$state = $this->conversationService->appendMessage(
					$request,
					$draft->getConversationId(),
					$message
				);
			}
		}

		$this->draftStore->remove($channelId, $draft->getId());

		return $this->clientState($state, $channelId);
	}

	/** @param array<string,mixed> $reference */
	public function activateConversation(
		string $group,
		string $name,
		string $conversationId,
		array $reference = []
	): ChatbotConversationClientState {
		[, $request, $channelId] = $this->createRequest($group, $name, $reference);
		return $this->clientState(
			$this->conversationService->activateConversation(
				$request,
				$this->requireConversationId($conversationId)
			),
			$channelId
		);
	}

	/** @param array<string,mixed> $reference */
	public function renameConversation(
		string $group,
		string $name,
		string $conversationId,
		string $title,
		array $reference = []
	): ChatbotConversationClientState {
		[, $request] = $this->createRequest($group, $name, $reference);
		$title = $this->normalizeTitle($title, 255);
		if ($title === '') {
			throw new \InvalidArgumentException('Conversation title must not be empty.');
		}

		return new ChatbotConversationClientState(
			$this->conversationService->renameConversation(
				$request,
				$this->requireConversationId($conversationId),
				$title,
				AgentConversation::TITLE_SOURCE_MANUAL
			)
		);
	}

	/** @param array<string,mixed> $reference */
	public function deleteConversation(
		string $group,
		string $name,
		string $conversationId,
		array $reference = []
	): ChatbotConversationClientState {
		[$settings, $request, $channelId] = $this->createRequest($group, $name, $reference);
		$state = $this->conversationService->deleteConversation(
			$request,
			$this->requireConversationId($conversationId)
		);
		if ($state->getActiveConversation() instanceof AgentConversation) {
			return $this->clientState($state, $channelId);
		}

		return $this->draftClientState(
			$state,
			$this->createDraft($channelId, $settings, $reference)
		);
	}

	/** @param array<string,mixed> $reference */
	public function generateAutomaticTitle(
		string $group,
		string $name,
		string $conversationId,
		array $reference = []
	): ChatbotConversationClientState {
		[$settings, $request] = $this->createRequest($group, $name, $reference);
		$conversationId = $this->requireConversationId($conversationId);
		$state = $this->conversationService->getState($request, $conversationId);
		$conversation = $state->getActiveConversation();
		if (!$conversation instanceof AgentConversation) {
			throw new \RuntimeException('Conversation not found: ' . $conversationId);
		}
		if (!$this->toBool($settings['automatic_chat_titles'] ?? true)) {
			return new ChatbotConversationClientState($state);
		}
		if ($conversation->getTitleSource() !== AgentConversation::TITLE_SOURCE_TEMPORARY) {
			return new ChatbotConversationClientState($state);
		}

		[$userMessage, $assistantMessage] = $this->findInitialTurn($state->getMessages());
		if ($userMessage === '' || $assistantMessage === '') {
			return new ChatbotConversationClientState($state);
		}

		try {
			$result = $this->textTaskService->executeTextTask(new AgentTextTaskRequest(
				$this->settingsService->getAgentConfiguration($settings),
				'chat-title',
				'Create a concise title for a chat conversation. '
					. 'Return plain text only, without quotation marks, markdown, punctuation at the end, or a prefix.',
				"User message:\n{$userMessage}\n\nAssistant answer:\n{$assistantMessage}\n\n"
					. 'Write a specific title with approximately four to eight words.',
				[
					'reference' => $reference,
					'conversation_channel_id' => $this->channelResolver->resolve($group, $name)
				]
			));
			$title = $this->normalizeTitle($result->getContent(), 100);
			if ($title === '') {
				throw new \RuntimeException('Automatic chat title task returned an empty title.');
			}

			return new ChatbotConversationClientState(
				$this->conversationService->renameConversation(
					$request,
					$conversationId,
					$title,
					AgentConversation::TITLE_SOURCE_AUTOMATIC
				)
			);
		}
		catch (Throwable $exception) {
			$this->logger->warning('Automatic chatbot conversation title generation failed.', [
				'conversation_id' => $conversationId,
				'chatbot_config_group' => $group,
				'chatbot_config_name' => $name,
				'exception' => $exception
			]);
			return new ChatbotConversationClientState($state);
		}
	}

	/**
	 * @param array<string,mixed> $reference
	 * @return array{0:array<string,mixed>,1:AgentConversationRequest,2:string}
	 */
	private function createRequest(string $group, string $name, array $reference): array {
		$group = trim($group);
		$name = trim($name);
		$settings = $this->settingsService->require($group, $name);
		if (!$this->settingsService->hasConversationMemory($settings)) {
			throw new \RuntimeException('Chatbot conversation memory is not configured.');
		}

		$channelId = $this->channelResolver->resolve($group, $name);
		if ($channelId === '') {
			throw new \RuntimeException('Chatbot conversation channel could not be resolved.');
		}

		$nodeId = trim((string)($settings['agent_components_assistant_node'] ?? 'assistant'));
		if ($nodeId === '') {
			$nodeId = 'assistant';
		}

		return [$settings, new AgentConversationRequest(
			$this->settingsService->getAgentConfiguration($settings),
			[
				'reference' => $reference,
				'conversation_channel_id' => $channelId,
				'chatbot_config_group' => $group,
				'chatbot_config_name' => $name,
				'chatbot_config' => $settings,
				'source' => 'chatbot-conversation-api'
			],
			$nodeId
		), $channelId];
	}

	private function clientState(AgentConversationState $state, string $channelId): ChatbotConversationClientState {
		$active = $state->getActiveConversation();
		if (!$active instanceof AgentConversation) {
			return new ChatbotConversationClientState($state);
		}

		$scopeId = AgentSuspensionScope::forConversation($channelId, $active->getId());
		$pending = $scopeId !== '' ? $this->suspensionRepository->findPending($scopeId) : null;

		return new ChatbotConversationClientState($state, null, $pending);
	}

	/** @param array<string,mixed> $settings @param array<string,mixed> $reference */
	private function createDraft(
		string $channelId,
		array $settings,
		array $reference
	): ChatbotConversationDraft {
		$messages = [];
		if (
			$this->openingMessageService->getFirstMessageMode($settings)
			!== ChatbotOpeningMessageService::FIRST_MESSAGE_MODE_NONE
		) {
			$messages[] = [
				'id' => 'msg_' . bin2hex(random_bytes(20)),
				'role' => 'assistant',
				'content' => $this->openingMessageService->createAssistantMessage($settings, $reference),
				'timestamp' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
				'feedback' => null
			];
		}

		$draft = new ChatbotConversationDraft(
			bin2hex(random_bytes(24)),
			'conversation-' . bin2hex(random_bytes(20)),
			$this->openingMessageService->createHeading($settings, $reference),
			$messages,
			time()
		);
		$this->draftStore->save($channelId, $draft);

		return $draft;
	}

	private function draftClientState(
		AgentConversationState $state,
		ChatbotConversationDraft $draft
	): ChatbotConversationClientState {
		return new ChatbotConversationClientState(
			new AgentConversationState(
				$state->getConversations(),
				null,
				[],
				$state->getNodeId(),
				$state->getWarnings()
			),
			$draft
		);
	}

	private function findConversation(
		AgentConversationState $state,
		string $conversationId
	): ?AgentConversation {
		foreach ($state->getConversations() as $conversation) {
			if ($conversation->getId() === $conversationId) {
				return $conversation;
			}
		}
		return null;
	}

	private function createTemporaryTitle(): string {
		return 'Chat ' . (new \DateTimeImmutable('now'))->format('Y-m-d H:i');
	}

	private function requireConversationId(string $conversationId): string {
		$conversationId = trim($conversationId);
		if ($conversationId === '' || strlen($conversationId) > 100) {
			throw new \InvalidArgumentException('Conversation id is missing or invalid.');
		}
		if (preg_match('/^[A-Za-z0-9._:-]+$/', $conversationId) !== 1) {
			throw new \InvalidArgumentException('Conversation id is missing or invalid.');
		}
		return $conversationId;
	}

	private function requireDraftId(string $draftId): string {
		$draftId = strtolower(trim($draftId));
		if (preg_match('/^[a-f0-9]{48}$/', $draftId) !== 1) {
			throw new \InvalidArgumentException('Conversation draft id is missing or invalid.');
		}
		return $draftId;
	}

	/** @param array<int,array<string,mixed>> $messages @return array{0:string,1:string} */
	private function findInitialTurn(array $messages): array {
		$userMessage = '';
		$assistantMessage = '';
		foreach ($messages as $message) {
			$role = strtolower(trim((string)($message['role'] ?? '')));
			$content = $this->normalizePlainText((string)($message['content'] ?? ''), 1200);
			if ($content === '') {
				continue;
			}
			if ($role === 'user' && $userMessage === '') {
				$userMessage = $content;
				continue;
			}
			if ($role === 'assistant' && $userMessage !== '' && $assistantMessage === '') {
				$assistantMessage = $content;
				break;
			}
		}
		return [$userMessage, $assistantMessage];
	}

	private function normalizeTitle(string $value, int $maxLength): string {
		$value = $this->normalizePlainText($value, $maxLength);
		$value = trim($value, " \t\n\r\0\x0B\"'`*-–—:;,.!?");
		return $value;
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

	private function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value === 1;
		}
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}
}
