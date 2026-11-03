<?php declare(strict_types=1);

namespace Test\Chatbot\Service;

use AssistantFoundation\Api\IAssistantResponseExtension;
use AssistantFoundation\Api\IAssistantResponseExtensionExamples;
use AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\Api\IClassMap;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
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
