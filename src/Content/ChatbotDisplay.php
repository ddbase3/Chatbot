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

namespace Chatbot\Content;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\ISchemaProvider;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use Throwable;

class ChatbotDisplay implements IDisplay, ISchemaProvider {

	private array $data = [];

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver,
		private readonly ILinkTargetService $linkTargetService,
		private readonly ISettingsStore $settingsStore,
		private readonly IAgentRuntimeSelector $agentRuntimeSelector
	) {}

	public static function getName(): string {
		return 'chatbotdisplay';
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------

	public function getOutput(string $out = 'html', bool $final = false): string {
		$this->view->setPath(DIR_PLUGIN . 'Chatbot');
		$this->view->setTemplate('Content/ChatbotDisplay.php');

		$config = $this->getClientConfig();
		$config['service_url'] = $this->buildServiceUrl($config);
		$config['turn_prepare_url'] = $this->buildTurnPrepareUrl();

		foreach ($config as $tag => $content) {
			$this->view->assign($tag, $content);
		}

		$this->view->assign('resolve', fn($src) => $this->assetResolver->resolve($src));

		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'Display a configurable Chatbot widget.';
	}

	public function setData($data) {
		$this->data = (array) $data;
	}

	// ---------------------------------------------------------------------
	// Configuration
	// ---------------------------------------------------------------------

	/**
	 * Returns the client-side display configuration.
	 *
	 * Server-side configuration values, especially system_prompt and later
	 * agent/tool settings, must not be rendered into the browser. The browser
	 * only needs to know which chatbot service to call and which SettingsStore
	 * dataset identifies the current chatbot instance.
	 */
	protected function getClientConfig(): array {
		$defaultBackend = $this->getDefaultBackend();
		$defaults = [
			'chatbot_backend' => $defaultBackend,
			'service' => '',

			// SettingsStore instance identity.
			'config_group' => '',
			'config_name' => '',

			// Features
			'use_markdown' => true,
			'use_icons' => true,
			'use_voice' => true,
			'use_threads' => true,

			// Transport
			'transport_mode' => 'auto',

			// Reference context
			'reference_mode' => 'url',
			'reference' => [],
			'reference_provider' => '',

			// Voice config
			'default_lang' => 'auto'
		];

		$storedConfig = $this->loadStoredConfig($this->data);
		$providedConfig = array_merge($this->data, $storedConfig);
		$config = array_merge($defaults, $providedConfig);

		$backend = $this->resolveBackend($providedConfig);

		return [
			'chatbot_backend' => $backend,
			'service' => $this->getServiceIdFromBackend($backend),
			'config_group' => trim((string) ($config['config_group'] ?? $defaults['config_group'])),
			'config_name' => trim((string) ($config['config_name'] ?? $defaults['config_name'])),
			'use_markdown' => $this->toBool($config['use_markdown'] ?? $defaults['use_markdown']),
			'use_icons' => $this->toBool($config['use_icons'] ?? $defaults['use_icons']),
			'use_voice' => $this->toBool($config['use_voice'] ?? $defaults['use_voice']),
			'use_threads' => $this->toBool($config['use_threads'] ?? $defaults['use_threads']),
			'transport_mode' => $this->normalizeEnum(
				(string) ($config['transport_mode'] ?? $defaults['transport_mode']),
				['auto', 'sse', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string) ($config['reference_mode'] ?? $defaults['reference_mode']),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference' => is_array($config['reference'] ?? null) ? $config['reference'] : [],
			'reference_provider' => trim((string) ($config['reference_provider'] ?? $defaults['reference_provider'])),
			'default_lang' => trim((string) ($config['default_lang'] ?? $defaults['default_lang']))
		];
	}

	/**
	 * Loads the persisted chatbot settings identified by the page component.
	 *
	 * The page component owns only the SettingsStore identity. Backend and UI
	 * selections are stored by ChatbotConfigDisplay and must be resolved again
	 * while rendering the public chatbot.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	protected function loadStoredConfig(array $config): array {
		$group = trim((string)($config['config_group'] ?? ''));
		$name = trim((string)($config['config_name'] ?? ''));
		if ($group === '' || $name === '') {
			return [];
		}

		try {
			$settings = $this->settingsStore->get($group, $name, []);
		}
		catch (Throwable) {
			return [];
		}

		return is_array($settings) ? $settings : [];
	}

	/**
	 * Builds the service URL used by the JavaScript chatbot client.
	 *
	 * The configured backend resolves either to a direct chatbot service or to
	 * the shared agent-backed endpoint. The host system owns URL generation.
	 */
	protected function buildServiceUrl(array $config): string {
		$service = trim((string) ($config['service'] ?? ''));

		if ($service === '') {
			return '';
		}

		$params = [];

		if (($config['config_group'] ?? '') !== '') {
			$params['config_group'] = (string) $config['config_group'];
		}

		if (($config['config_name'] ?? '') !== '') {
			$params['config_name'] = (string) $config['config_name'];
		}

		return $this->linkTargetService->getLink(
			[
				'name' => $service
			],
			$params
		);
	}

	protected function buildTurnPrepareUrl(): string {
		return $this->linkTargetService->getLink([
			'name' => 'chatbotturnprepare'
		]);
	}

	/** @param array<string,mixed> $config */
	protected function resolveBackend(array $config): string {
		$backend = strtolower(trim((string)($config['chatbot_backend'] ?? '')));
		if (preg_match('/^(runtime|service):[a-z0-9._-]+$/', $backend) === 1) {
			return $backend;
		}

		$legacyService = $this->normalizeTechnicalKey((string)($config['service'] ?? ''));
		if ($legacyService !== '' && $legacyService !== 'chatbotservice') {
			return 'service:' . $legacyService;
		}

		$runtimeId = $this->normalizeTechnicalKey((string)($config['agent_runtime'] ?? ''));
		return $runtimeId !== '' ? 'runtime:' . $runtimeId : $this->getDefaultBackend();
	}

	protected function getDefaultBackend(): string {
		try {
			$runtimeId = $this->normalizeTechnicalKey($this->agentRuntimeSelector->getDefaultRuntimeId());
			if ($runtimeId !== '') {
				return 'runtime:' . $runtimeId;
			}
		}
		catch (Throwable) {
		}

		return 'service:dummychatbotservice';
	}

	protected function getServiceIdFromBackend(string $backend): string {
		if (str_starts_with($backend, 'service:')) {
			return $this->normalizeTechnicalKey(substr($backend, 8));
		}
		if (str_starts_with($backend, 'runtime:')) {
			return 'chatbotservice';
		}

		return '';
	}

	protected function normalizeEnum(string $value, array $allowed, string $default): string {
		return in_array($value, $allowed, true) ? $value : $default;
	}

	protected function normalizeTechnicalKey(string $value): string {
		$value = strtolower(trim($value));

		return preg_replace('/[^a-z0-9._-]+/', '', $value) ?? '';
	}

	protected function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value === 1;
		}

		$value = strtolower(trim((string) $value));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	// ---------------------------------------------------------------------
	// JSON Schema
	// ---------------------------------------------------------------------

	public function getSchema(): array {
		$defaultBackend = $this->getDefaultBackend();

		return [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [

				'chatbot_backend' => [
					'type' => 'string',
					'description' => 'Direct chatbot service or registered agent runtime',
					'pattern' => '^(service|runtime):[a-z0-9._-]+$',
					'default' => $defaultBackend
				],

				'config_group' => [
					'type' => 'string',
					'description' => 'SettingsStore group of the chatbot instance',
					'default' => ''
				],

				'config_name' => [
					'type' => 'string',
					'description' => 'SettingsStore name of the chatbot instance',
					'default' => ''
				],

				'use_markdown' => [
					'type' => 'boolean',
					'description' => 'Enable markdown to HTML conversion',
					'default' => true
				],
				'use_icons' => [
					'type' => 'boolean',
					'description' => 'Show dialog action icons (copy, like, dislike, reload)',
					'default' => true
				],
				'use_voice' => [
					'type' => 'boolean',
					'description' => 'Enable voice controls and TTS/STT',
					'default' => true
				],
				'use_threads' => [
					'type' => 'boolean',
					'description' => 'Enable multiple chat threads within the widget',
					'default' => true
				],

				'transport_mode' => [
					'type' => 'string',
					'enum' => ['auto', 'sse', 'rest'],
					'description' => 'Transport protocol for streaming responses',
					'default' => 'auto'
				],

				'reference_mode' => [
					'type' => 'string',
					'enum' => ['none', 'url', 'custom', 'provider'],
					'description' => 'Defines how client-side context reference is sent with each request',
					'default' => 'url'
				],
				'reference' => [
					'type' => 'object',
					'description' => 'Static reference payload for reference_mode=custom',
					'default' => []
				],
				'reference_provider' => [
					'type' => 'string',
					'description' => 'Global JavaScript function name for reference_mode=provider',
					'default' => ''
				],

				'default_lang' => [
					'type' => 'string',
					'description' => 'Default language for voice control',
					'default' => 'auto'
				]
			],
			'required' => ['chatbot_backend']
		];
	}
}
