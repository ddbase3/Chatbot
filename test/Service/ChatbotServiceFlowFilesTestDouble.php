<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\Service\ChatbotService;

final class ChatbotServiceFlowFilesTestDouble extends ChatbotService {

	public string $systemPromptPath = '';
	public string $agentFlowPath = '';
	public string $suggestionPromptPath = '';
	public string $suggestionFlowPath = '';

	public function inspectSystemPrompt(array $settings): string {
		return $this->getSystemPrompt($settings);
	}

	protected function getSystemPromptFile(): string {
		return $this->systemPromptPath;
	}

	protected function getAgentFlowFile(): string {
		return $this->agentFlowPath;
	}

	protected function getSuggestionPromptFile(): string {
		return $this->suggestionPromptPath;
	}

	protected function getSuggestionFlowFile(): string {
		return $this->suggestionFlowPath;
	}
}
