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

namespace Base3\Chatbot\Test\Content;

use Base3\Chatbot\Content\ChatbotConfigDisplay;

/** Exposes documented configuration boundaries without changing their behavior. */
final class ChatbotConfigBranchProbe extends ChatbotConfigDisplay {

	public function inspectContext(bool $fromRequest): array {
		return $this->getContext($fromRequest);
	}

	public function inspectJson(string $raw, string $label, array &$errors): array {
		return $this->decodeConfigJsonInput($raw, $label, $errors);
	}

	public function inspectReference(string $raw, array &$errors): array {
		return $this->decodeReferenceInput($raw, $errors);
	}

	public function inspectJsonResponse(bool $success, array $data = []): string {
		return $this->jsonResponse($success, $data);
	}

	public function inspectJsonError(string $message): string {
		return $this->jsonError($message);
	}

	public function inspectMessages(): array {
		return $this->messages;
	}

	public function inspectSaveRequest(array $context): bool {
		return $this->isSaveRequest($context);
	}

	public function inspectSaveUrl(): string {
		return $this->getSaveUrl();
	}

	public function inspectResources(): array {
		return $this->getResourcesConfig();
	}

	public function inspectBackend(string $value): string {
		return $this->normalizeBackendId($value);
	}

	public function inspectRuntime(string $value): string {
		return $this->getRuntimeIdFromBackend($value);
	}

	public function inspectService(string $value): string {
		return $this->getServiceIdFromBackend($value);
	}

	public function inspectBackendFromSettings(array $settings): string {
		return $this->resolveBackendFromSettings($settings);
	}

	public function inspectMessageList(mixed $value): array {
		return $this->normalizeMessageListInput($value);
	}

	public function inspectFormatJson(array $value, string $empty): string {
		return $this->formatConfigJson($value, $empty);
	}

	public function inspectViewValues(array $settings): array {
		return $this->settingsToViewValues($settings);
	}

	public function inspectNormalizeSettings(array $settings): array {
		return $this->normalizeSettings($settings);
	}
}
