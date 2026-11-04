<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\Service\AbstractChatbotService;

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
