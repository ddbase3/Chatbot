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

namespace Base3\Chatbot\Api;

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
