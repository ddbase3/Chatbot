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

use Base3\Chatbot\Service\AbstractChatbotService;

final class ChatbotBareServiceFixture extends AbstractChatbotService {

	public static function getName(): string {
		return 'chatbotbareservicefixture';
	}

	protected function getBasePrompt(): string {
		return 'Fixture base prompt';
	}

	public function inspectSettingString(array $settings, string $key, string $default = ''): string {
		return $this->getChatbotSettingString($settings, $key, $default);
	}

	public function inspectSettingBool(array $settings, string $key, bool $default = false): bool {
		return $this->getChatbotSettingBool($settings, $key, $default);
	}

	public function inspectAgentInputs(string $system, string $prompt, array $resume = []): array {
		return $this->buildAgentInputs($system, $prompt, $resume);
	}

	public function inspectAgentConfiguration(array $settings): array {
		return $this->getAgentSettingsForExecution($settings);
	}

	public function inspectSystemPrompt(array $settings): string {
		return $this->getSystemPrompt($settings);
	}

	public function inspectSuggestionPrompt(array $settings): string {
		return $this->getSuggestionPrompt($settings);
	}
}
