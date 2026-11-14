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

namespace Base3\Chatbot\Service;

use Base3\Chatbot\Api\IChatbotAppearanceProvider;

final class StaticChatbotAppearanceProvider implements IChatbotAppearanceProvider {

	public function __construct(
		private readonly string $stylesheet = '',
		private readonly string $userMessageIcon = '',
		private readonly string $assistantMessageIcon = '',
		private readonly string $thinkingIcon = '',
		private readonly string $openingMessageIcon = '',
		private readonly array $domClasses = [],
		private readonly array $controlIcons = [],
		private readonly string $initialAssistantMessageLogo = '',
		private readonly string $initialAssistantMessageTitle = ''
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

	public function getInitialAssistantMessageLogo(): string {
		return $this->initialAssistantMessageLogo;
	}

	public function getInitialAssistantMessageTitle(): string {
		return $this->initialAssistantMessageTitle;
	}

	public function getDomClasses(): array {
		return $this->domClasses;
	}

	public function getControlIcons(): array {
		return $this->controlIcons;
	}
}
