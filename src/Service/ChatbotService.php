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

use Base3\Api\IRequest;
use AssistantFoundation\Api\IAgentExecutionService;

/**
 * Class ChatbotService
 *
 * Official SettingsStore-backed chatbot service.
 *
 * This service is selected by technical service name in chatbot configuration
 * displays. The host-specific endpoint URL is generated outside this class by
 * ILinkTargetService.
 */
class ChatbotService extends AbstractChatbotService {

	public function __construct(
		IRequest $request,
		ChatbotSettingsService $settingsService,
		IAgentExecutionService $agentExecutionService,
		ChatbotTurnRequestFactory $turnRequestFactory,
		ChatbotTurnResponder $turnResponder,
		ChatbotConversationChannelResolver $conversationChannelResolver,
		private readonly ChatbotOpeningMessageService $openingMessageService
	) {
		parent::__construct(
			$request,
			$settingsService,
			$agentExecutionService,
			$turnRequestFactory,
			$turnResponder,
			$conversationChannelResolver
		);
	}

	public static function getName(): string {
		return 'chatbotservice';
	}

	public static function getServiceLabel(): string {
		return 'Agent-backed Chatbot Service';
	}

	public static function getServiceDescription(): string {
		return 'Uses the selected agent runtime with SettingsStore based prompts and runtime-specific configuration.';
	}

	public function getHelp(): string {
		return 'SettingsStore backed chatbot service using the selected agent runtime.';
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Base prompt
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Creates the configured opening message for the current chatbot request.
	 */
	protected function getBasePrompt(): string {
		$turn = $this->turnRequestFactory->fromCurrentRequest();
		$settings = $this->getChatbotSettings($turn);

		return $this->openingMessageService->createHeading($settings, $turn->getReference());
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Assistant answer
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Returns the configured agent flow from the SettingsStore.
	 *
	 * The value may be an already decoded array or a JSON string from the temporary
	 * textarea configuration UI. Effective component expansion now happens in
	 * the configured IAgentExecutionService implementation.
	 */
	protected function getSimpleAgentFlow(): ?array {
		$settings = $this->getChatbotSettings();

		return $this->getArraySetting($settings, 'agent_flow');
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Prompt suggestion
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Returns the configured suggestion flow from the SettingsStore if present.
	 *
	 * This keeps suggestion support available for installations that already store
	 * a dedicated suggestion flow, without requiring a separate service derivative.
	 */
	protected function getSimpleSuggestionFlow(): ?array {
		$settings = $this->getChatbotSettings();

		$flow = $this->getArraySetting($settings, 'suggestion_agent_flow');

		if ($flow !== null) {
			return $flow;
		}

		return $this->getArraySetting($settings, 'suggestion_flow');
	}

	///////////////////////////////////////////////////////////////////////////////////////
	// Settings helpers
	///////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Reads an array setting from the loaded SettingsStore data.
	 *
	 * Values may be present as arrays or as JSON strings. Invalid or empty values
	 * are treated as missing so the parent service can return its normal error
	 * response.
	 */
	protected function getArraySetting(array $settings, string $key): ?array {
		if (!array_key_exists($key, $settings)) {
			return null;
		}

		$value = $settings[$key];

		if (is_array($value)) {
			return $value;
		}

		if (!is_string($value)) {
			return null;
		}

		$value = trim($value);

		if ($value === '') {
			return null;
		}

		$decoded = json_decode($value, true);

		return is_array($decoded) ? $decoded : null;
	}
}
