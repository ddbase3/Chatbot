<?php declare(strict_types=1);

namespace Chatbot\Api;

interface IChatbotAppearanceProvider {

	public function getStylesheet(): string;

	public function getUserMessageIcon(): string;

	public function getAssistantMessageIcon(): string;

	public function getThinkingIcon(): string;

	public function getOpeningMessageIcon(): string;

	public function getDomClasses(): array;
}
