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

namespace Chatbot\Service;

use AssistantFoundation\Api\IAssistantResponseExtension;
use AssistantFoundation\Api\IAssistantResponseExtensionExamples;
use AssistantFoundation\Dto\AssistantResponseClientPlugin;
use Base3\Settings\Api\ISettingsStore;
use RuntimeException;

final class ChatbotExtensionService {

	public const SETTINGS_GROUP = 'chatbot-extensions';
	public const SETTINGS_NAME = 'default';

	public function __construct(
		private readonly ChatbotExtensionRegistry $registry,
		private readonly ISettingsStore $settingsStore
	) {}

	public static function getName(): string {
		return 'chatbotextensionservice';
	}

	/** @return array<int,array<string,mixed>> */
	public function getStates(): array {
		$enabled = $this->getEnabledMap();
		$states = [];

		foreach ($this->registry->all() as $id => $extension) {
			$states[] = [
				'id' => $id,
				'label' => $extension->getLabel(),
				'description' => $extension->getDescription(),
				'requirements' => $extension->getRequirements(),
				'example_prompts' => $extension instanceof IAssistantResponseExtensionExamples
					? $extension->getExamplePrompts()
					: [],
				'enabled' => $enabled[$id] ?? $extension->isEnabledByDefault()
			];
		}

		return $states;
	}

	/** @param array<int,string> $enabledIds */
	public function saveEnabled(array $enabledIds): void {
		$available = $this->registry->all();
		$selected = [];
		foreach ($enabledIds as $id) {
			$id = $this->normalizeId($id);
			if ($id === '' || !isset($available[$id])) {
				throw new \InvalidArgumentException('Unknown assistant response extension selected.');
			}
			$selected[$id] = true;
		}

		$enabled = [];
		foreach ($available as $id => $extension) {
			$enabled[$id] = isset($selected[$id]);
		}

		$this->settingsStore->set(self::SETTINGS_GROUP, self::SETTINGS_NAME, [
			'enabled' => $enabled
		]);
		$this->settingsStore->save();
	}

	/** @param array<string,mixed> $context */
	public function composeSystemPrompt(string $prompt, array $context = []): string {
		$blocks = [];
		foreach ($this->getEnabledExtensions() as $extension) {
			$block = trim($extension->getSystemPrompt($context));
			if ($block !== '') {
				$blocks[] = $block;
			}
		}

		if ($blocks === []) {
			return $prompt;
		}

		return rtrim($prompt) . "\n\n" . implode("\n\n", $blocks);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array{plugins:array<int,array<string,mixed>>,plugin_options:array<string,array<string,mixed>>}
	 */
	public function getClientConfiguration(array $context = []): array {
		$plugins = [];
		$pluginNames = [];
		$pluginOptions = [];

		foreach ($this->getEnabledExtensions() as $extension) {
			$pluginOptions = array_replace_recursive(
				$pluginOptions,
				$extension->getClientPluginOptions($context)
			);

			$plugin = $extension->getClientPlugin($context);
			if ($plugin === null) {
				continue;
			}

			$this->validateClientPlugin($plugin);
			$name = $this->normalizeId($plugin->getName());
			if (isset($pluginNames[$name])) {
				throw new RuntimeException('Duplicate assistant response client plugin: ' . $name);
			}
			$pluginNames[$name] = true;
			$plugins[] = $plugin->toArray();
		}

		return [
			'plugins' => $plugins,
			'plugin_options' => $pluginOptions
		];
	}

	/** @return array<string,IAssistantResponseExtension> */
	private function getEnabledExtensions(): array {
		$enabled = $this->getEnabledMap();
		$extensions = [];

		foreach ($this->registry->all() as $id => $extension) {
			$isEnabled = $enabled[$id] ?? $extension->isEnabledByDefault();
			if ($isEnabled) {
				$extensions[$id] = $extension;
			}
		}

		return $extensions;
	}

	/** @return array<string,bool> */
	private function getEnabledMap(): array {
		$settings = $this->settingsStore->get(self::SETTINGS_GROUP, self::SETTINGS_NAME, []);
		$values = is_array($settings) && is_array($settings['enabled'] ?? null)
			? $settings['enabled']
			: [];
		$enabled = [];

		foreach ($values as $id => $value) {
			if (!is_string($id) || $this->normalizeId($id) === '') {
				throw new RuntimeException('Stored assistant response extension settings contain an invalid id.');
			}
			$enabled[$id] = $this->toBool($value);
		}

		return $enabled;
	}

	private function validateClientPlugin(AssistantResponseClientPlugin $plugin): void {
		if ($this->normalizeId($plugin->getName()) === '') {
			throw new RuntimeException('Assistant response client plugin requires a technical name.');
		}
		if (trim($plugin->getModuleUrl()) === '') {
			throw new RuntimeException('Assistant response client plugin requires a module URL.');
		}
		if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $plugin->getExportName()) !== 1) {
			throw new RuntimeException('Assistant response client plugin contains an invalid export name.');
		}
	}

	private function normalizeId(string $id): string {
		$id = trim($id);
		return preg_match('/^[a-z0-9._-]+$/', $id) === 1 ? $id : '';
	}

	private function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value === 1;
		}
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}
}
