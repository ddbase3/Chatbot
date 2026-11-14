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

use Base3\AssistantFoundation\Api\IAssistantResponseExtension;
use Base3\AssistantFoundation\Api\IAssistantResponseExtensionExamples;
use Base3\AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use PHPUnit\Framework\TestCase;

final class TestAssistantResponseExtension implements IAssistantResponseExtension, IAssistantResponseExtensionExamples {

	public static function getName(): string {
		return 'testassistantresponseextension';
	}

	public function id(): string {
		return 'test-extension';
	}

	public function getLabel(): string {
		return 'Test extension';
	}

	public function getDescription(): string {
		return 'Test extension description.';
	}

	public function getPriority(): int {
		return 100;
	}

	public function isEnabledByDefault(): bool {
		return false;
	}

	public function getRequirements(): array {
		return [];
	}

	public function getExamplePrompts(): array {
		return ['Show a test response.'];
	}

	public function getSystemPrompt(array $context): string {
		return 'Extension prompt.';
	}

	public function getClientPlugin(array $context): ?AssistantResponseClientPlugin {
		return new AssistantResponseClientPlugin(
			'test-renderer',
			'/test-renderer.js',
			'TestRendererPlugin',
			['mode' => 'stable']
		);
	}

	public function getClientPluginOptions(array $context): array {
		return [
			'markdown' => [
				'preserveTest' => true
			]
		];
	}
}
