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

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Settings\Api\ISettingsStore;

/**
 * Canonical access to one configured chatbot settings dataset.
 */
final class ChatbotSettingsService {

	public function __construct(
		private readonly ISettingsStore $settingsStore,
		private readonly IAgentRuntimeSelector $runtimeSelector
	) {}

	public static function getName(): string {
		return 'chatbotsettingsservice';
	}

	/** @return array<string,mixed> */
	public function get(string $group, string $name, array $default = []): array {
		$group = trim($group);
		$name = trim($name);
		if ($group === '' || $name === '') {
			return $default;
		}

		$settings = $this->settingsStore->get($group, $name, $default);
		return is_array($settings) ? $settings : $default;
	}

	/** @return array<string,mixed> */
	public function require(string $group, string $name): array {
		$group = trim($group);
		$name = trim($name);
		if ($group === '' || $name === '') {
			throw new \InvalidArgumentException('Chatbot configuration requires group and name.');
		}
		if (!$this->settingsStore->has($group, $name)) {
			throw new \RuntimeException('Chatbot configuration does not exist: ' . $group . '/' . $name);
		}

		return $this->get($group, $name);
	}

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	public function getAgentConfiguration(array $settings): array {
		$configuration = $settings;
		$runtimeId = $this->getRuntimeId($settings);
		if ($runtimeId !== '') {
			$configuration['agent_runtime'] = $runtimeId;
		}

		return $configuration;
	}

	/** @param array<string,mixed> $settings */
	public function getRuntimeId(array $settings): string {
		$runtimeId = $this->normalizeTechnicalId((string)($settings['agent_runtime'] ?? ''));
		if ($runtimeId !== '') {
			return $runtimeId;
		}

		$backend = strtolower(trim((string)($settings['chatbot_backend'] ?? '')));
		if (str_starts_with($backend, 'runtime:')) {
			return $this->normalizeTechnicalId(substr($backend, 8));
		}

		return '';
	}

	/** @param array<string,mixed> $settings */
	public function hasConversationMemory(array $settings): bool {
		return $this->normalizeTechnicalId((string)($settings['memory_profile'] ?? '')) !== '';
	}

	/** @param array<string,mixed> $settings */
	public function selectRuntimeId(array $settings): string {
		$runtimeId = $this->getRuntimeId($settings);
		return $runtimeId !== '' ? $runtimeId : $this->runtimeSelector->selectRuntimeId($settings);
	}

	private function normalizeTechnicalId(string $value): string {
		$value = strtolower(trim($value));
		return preg_replace('/[^a-z0-9._-]+/', '', $value) ?? '';
	}
}
