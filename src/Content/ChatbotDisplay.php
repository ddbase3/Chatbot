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

class ChatbotDisplay implements IDisplay, ISchemaProvider {

	private array $data = [];

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver
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
		$defaults = [
			'service' => 'chatbotservice.php',

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

		$config = array_merge($defaults, $this->data);

		return [
			'service' => trim((string) ($config['service'] ?? $defaults['service'])),
			'config_group' => trim((string) ($config['config_group'] ?? $defaults['config_group'])),
			'config_name' => trim((string) ($config['config_name'] ?? $defaults['config_name'])),
			'use_markdown' => $this->toBool($config['use_markdown'] ?? $defaults['use_markdown']),
			'use_icons' => $this->toBool($config['use_icons'] ?? $defaults['use_icons']),
			'use_voice' => $this->toBool($config['use_voice'] ?? $defaults['use_voice']),
			'use_threads' => $this->toBool($config['use_threads'] ?? $defaults['use_threads']),
			'transport_mode' => $this->normalizeEnum(
				(string) ($config['transport_mode'] ?? $defaults['transport_mode']),
				['auto', 'sse', 'websocket', 'rest'],
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
	 * Builds the service URL used by the JavaScript chatbot client.
	 *
	 * The chatbot service receives config_group/config_name with every request.
	 * This is intentionally done through the endpoint URL instead of exposing
	 * server-only configuration values. It works for all current request types:
	 * base prompt, normal prompt and suggestions.
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

		if ($params === []) {
			return $service;
		}

		return $this->appendQueryParams($service, $params);
	}

	protected function appendQueryParams(string $url, array $params): string {
		$fragment = '';

		$fragmentPos = strpos($url, '#');
		if ($fragmentPos !== false) {
			$fragment = substr($url, $fragmentPos);
			$url = substr($url, 0, $fragmentPos);
		}

		$query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

		if ($query === '') {
			return $url . $fragment;
		}

		$separator = str_contains($url, '?')
			? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&')
			: '?';

		return $url . $separator . $query . $fragment;
	}

	protected function normalizeEnum(string $value, array $allowed, string $default): string {
		return in_array($value, $allowed, true) ? $value : $default;
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
		return [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [

				'service' => [
					'type' => 'string',
					'description' => 'Service URL (server endpoint)',
					'default' => 'chatbotservice.php'
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
					'enum' => ['auto', 'sse', 'websocket', 'rest'],
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
			'required' => ['service']
		];
	}
}
