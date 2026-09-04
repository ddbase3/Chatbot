<?php declare(strict_types=1);

namespace Chatbot\Api;

interface IChatbotAppearanceProvider {

	public function getStylesheet(): string;

	public function getUserMessageIcon(): string;

	public function getAssistantMessageIcon(): string;

	public function getThinkingIcon(): string;

	public function getOpeningMessageIcon(): string;

	public function getInitialAssistantMessageLogo(): string;

	public function getInitialAssistantMessageTitle(): string;

	public function getDomClasses(): array;

	public function getControlIcons(): array;
}
