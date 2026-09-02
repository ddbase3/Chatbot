<?php declare(strict_types=1);

namespace Chatbot\Service;

use Chatbot\Api\IChatbotAppearanceProvider;

final class StaticChatbotAppearanceProvider implements IChatbotAppearanceProvider {

	public function __construct(
		private readonly string $stylesheet = '',
		private readonly string $userMessageIcon = '',
		private readonly string $assistantMessageIcon = '',
		private readonly string $thinkingIcon = '',
		private readonly string $openingMessageIcon = '',
		private readonly array $domClasses = []
	) {}

	public function getStylesheet(): string {
		return $this->stylesheet;
	}

	public function getUserMessageIcon(): string {
		return $this->userMessageIcon;
	}

	public function getAssistantMessageIcon(): string {
		return $this->assistantMessageIcon;
	}

	public function getThinkingIcon(): string {
		return $this->thinkingIcon;
	}

	public function getOpeningMessageIcon(): string {
		return $this->openingMessageIcon;
	}

	public function getDomClasses(): array {
		return $this->domClasses;
	}
}
