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

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IDisplay;
use Base3\Api\ISchemaProvider;
use Base3\Language\Api\ILanguage;
use Base3\LinkTarget\Api\ILinkTargetService;
use Chatbot\Api\IChatbotAppearanceProvider;
use Chatbot\Output\ChatbotConversationActivateOutput;
use Chatbot\Output\ChatbotConversationCreateOutput;
use Chatbot\Output\ChatbotConversationDeleteOutput;
use Chatbot\Output\ChatbotConversationMaterializeOutput;
use Chatbot\Output\ChatbotConversationRenameOutput;
use Chatbot\Output\ChatbotConversationStateOutput;
use Chatbot\Output\ChatbotConversationTitleOutput;
use Chatbot\Output\ChatbotTurnCancelOutput;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotSettingsService;
use Throwable;
use UiFoundation\Api\IChatbotDisplay;

class ChatbotDisplay implements IDisplay, ISchemaProvider {

	private const DEFAULT_AI_NOTICE = 'You are communicating with an AI system. AI-generated responses may be incorrect. Verify important information.';
	private const DISABLED_VOICE_SERVICE = 'off';

	private array $data = [];

	private ?string $defaultAiNotice = null;

	public function __construct(
		private readonly IChatbotDisplay $chatbotDisplay,
		private readonly ILinkTargetService $linkTargetService,
		private readonly ChatbotSettingsService $settingsService,
		private readonly ChatbotExtensionService $extensionService,
		private readonly IAgentRuntimeSelector $agentRuntimeSelector,
		private readonly ILanguage $language,
		private readonly IChatbotAppearanceProvider $appearanceProvider
	) {}

	public static function getName(): string {
		return 'chatbotdisplay';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$config = $this->getClientConfig();
		$config['service_url'] = $this->buildServiceUrl($config);
		$config['turn_prepare_url'] = $this->buildTurnPrepareUrl();
		$config['turn_cancel_url'] = $this->buildTurnCancelUrl();
		$config['speech_to_text_session_url'] = $this->buildSpeechToTextSessionUrl($config);
		$config['text_to_speech_url'] = $this->buildTextToSpeechUrl($config);
		$config = array_merge($config, $this->buildConversationUrls($config));

		$this->chatbotDisplay->setData($config);
		return $this->chatbotDisplay->getOutput($out, $final);
	}

	public function getHelp(): string {
		return 'Display a configurable Chatbot widget.';
	}

	public function setData($data) {
		$this->data = (array)$data;
	}

	/** @return array<string,mixed> */
	protected function getClientConfig(): array {
		$defaultBackend = $this->getDefaultBackend();
		$defaults = [
			'chatbot_backend' => $defaultBackend,
			'service' => '',
			'config_group' => '',
			'config_name' => '',
			'use_markdown' => true,
			'use_icons' => true,
			'use_voice' => true,
			'use_dialog' => true,
			'chat_history_enabled' => false,
			'chat_history_panel_mode' => 'responsive',
			'automatic_chat_titles' => false,
			'first_message_mode' => 'none',
			'ai_notice_text' => $this->getDefaultAiNotice(),
			'ai_notice_position' => 'above_composer',
			'transport_mode' => 'auto',
			'reference_mode' => 'url',
			'reference' => [],
			'reference_provider' => '',
			'default_lang' => 'auto',
			'speech_to_text_service' => '',
			'text_to_speech_service' => ''
		];

		$storedConfig = $this->loadStoredConfig($this->data);
		$providedConfig = array_merge($this->data, $storedConfig);
		$config = array_merge($defaults, $providedConfig);
		$backend = $this->resolveBackend($providedConfig);
		$group = trim((string)($config['config_group'] ?? ''));
		$name = trim((string)($config['config_name'] ?? ''));
		$conversationEnabled = $group !== ''
			&& $name !== ''
			&& $this->settingsService->hasConversationMemory($config);
		$historyEnabled = $conversationEnabled
			&& $this->toBool($config['chat_history_enabled'] ?? $defaults['chat_history_enabled']);
		$aiNotice = trim((string)($config['ai_notice_text'] ?? $defaults['ai_notice_text']));
		if ($aiNotice === '') {
			$aiNotice = $this->getDefaultAiNotice();
		}

		$useMarkdown = $this->toBool($config['use_markdown'] ?? $defaults['use_markdown']);
		$extensionConfig = $this->extensionService->getClientConfiguration([
			'use_markdown' => $useMarkdown,
			'chatbot_config_group' => $group,
			'chatbot_config_name' => $name,
			'language' => $this->getCurrentLanguage()
		]);

		$useVoice = $this->toBool($config['use_voice'] ?? $defaults['use_voice']);
		$speechToTextService = $this->normalizeTechnicalKey((string)($config['speech_to_text_service'] ?? ''));
		$textToSpeechService = $this->normalizeTechnicalKey((string)($config['text_to_speech_service'] ?? ''));
		$speechToTextEnabled = $useVoice && $speechToTextService !== self::DISABLED_VOICE_SERVICE;
		$textToSpeechEnabled = $useVoice && $textToSpeechService !== self::DISABLED_VOICE_SERVICE;
		$dialogEnabled = $useVoice
			&& $speechToTextEnabled
			&& $textToSpeechEnabled
			&& $this->toBool($config['use_dialog'] ?? $defaults['use_dialog']);

		return [
			'chatbot_backend' => $backend,
			'service' => $this->getServiceIdFromBackend($backend),
			'config_group' => $group,
			'config_name' => $name,
			'use_markdown' => $useMarkdown,
			'extensions' => $extensionConfig['plugins'],
			'extension_plugin_options' => $extensionConfig['plugin_options'],
			'use_icons' => $this->toBool($config['use_icons'] ?? $defaults['use_icons']),
			'use_voice' => $useVoice,
			'use_dialog' => $dialogEnabled,
			'speech_to_text_enabled' => $speechToTextEnabled,
			'text_to_speech_enabled' => $textToSpeechEnabled,
			'use_threads' => false,
			'conversation_enabled' => $conversationEnabled,
			'chat_history_enabled' => $historyEnabled,
			'chat_history_panel_mode' => $this->normalizeEnum(
				(string)($config['chat_history_panel_mode'] ?? $defaults['chat_history_panel_mode']),
				['responsive', 'open', 'closed'],
				'responsive'
			),
			'automatic_chat_titles' => $conversationEnabled
				&& $this->toBool($config['automatic_chat_titles'] ?? $defaults['automatic_chat_titles']),
			'first_message_mode' => $this->normalizeEnum(
				(string)($config['first_message_mode'] ?? $defaults['first_message_mode']),
				['none', 'random', 'contextual_ai'],
				'none'
			),
			'ai_notice_text' => $aiNotice,
			'ai_notice_position' => $this->normalizeEnum(
				(string)($config['ai_notice_position'] ?? $defaults['ai_notice_position']),
				['above_composer', 'below_composer'],
				'above_composer'
			),
			'transport_mode' => $this->normalizeEnum(
				(string)($config['transport_mode'] ?? $defaults['transport_mode']),
				['auto', 'sse', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string)($config['reference_mode'] ?? $defaults['reference_mode']),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference' => is_array($config['reference'] ?? null) ? $config['reference'] : [],
			'reference_provider' => trim((string)($config['reference_provider'] ?? '')),
			'default_lang' => trim((string)($config['default_lang'] ?? 'auto')),
			'speech_to_text_service' => $speechToTextService,
			'text_to_speech_service' => $textToSpeechService,
			'additional_stylesheet' => trim($this->appearanceProvider->getStylesheet()),
			'dom_classes' => $this->appearanceProvider->getDomClasses(),
			'control_icons' => $this->appearanceProvider->getControlIcons(),
			'message_icons' => [
				'user' => trim($this->appearanceProvider->getUserMessageIcon()),
				'assistant' => trim($this->appearanceProvider->getAssistantMessageIcon()),
				'thinking' => trim($this->appearanceProvider->getThinkingIcon()),
				'opening' => trim($this->appearanceProvider->getOpeningMessageIcon())
			],
			'initial_assistant_branding' => [
				'logo' => trim($this->appearanceProvider->getInitialAssistantMessageLogo()),
				'title' => trim($this->appearanceProvider->getInitialAssistantMessageTitle())
			]
		];
	}

	/** @param array<string,mixed> $config @return array<string,mixed> */
	protected function loadStoredConfig(array $config): array {
		$group = trim((string)($config['config_group'] ?? ''));
		$name = trim((string)($config['config_name'] ?? ''));
		if ($group === '' || $name === '') {
			return [];
		}

		try {
			return $this->settingsService->get($group, $name, []);
		}
		catch (Throwable) {
			return [];
		}
	}

	/** @param array<string,mixed> $config */
	protected function buildServiceUrl(array $config): string {
		$service = trim((string)($config['service'] ?? ''));
		if ($service === '') {
			return '';
		}

		return $this->linkTargetService->getLink(
			['name' => $service],
			$this->getConfigIdentityParams($config)
		);
	}

	protected function buildTurnPrepareUrl(): string {
		return $this->linkTargetService->getLink(['name' => 'chatbotturnprepare']);
	}

	protected function buildTurnCancelUrl(): string {
		return $this->linkTargetService->getLink(['name' => ChatbotTurnCancelOutput::getName()]);
	}

	/** @param array<string,mixed> $config */
	protected function buildSpeechToTextSessionUrl(array $config): string {
		$serviceId = $this->normalizeTechnicalKey((string)($config['speech_to_text_service'] ?? ''));
		if (empty($config['speech_to_text_enabled']) || $serviceId === '' || $serviceId === self::DISABLED_VOICE_SERVICE) {
			return '';
		}
		$params = $this->getConfigIdentityParams($config);
		if ($params === []) {
			return '';
		}
		return $this->linkTargetService->getLink(['name' => 'realtimespeechtotextsession'], $params);
	}

	/** @param array<string,mixed> $config */
	protected function buildTextToSpeechUrl(array $config): string {
		$serviceId = $this->normalizeTechnicalKey((string)($config['text_to_speech_service'] ?? ''));
		if (empty($config['text_to_speech_enabled']) || $serviceId === '' || $serviceId === self::DISABLED_VOICE_SERVICE) {
			return '';
		}
		$params = $this->getConfigIdentityParams($config);
		if ($params === []) {
			return '';
		}
		return $this->linkTargetService->getLink(['name' => 'texttospeech'], $params);
	}

	/** @param array<string,mixed> $config @return array<string,string> */
	protected function buildConversationUrls(array $config): array {
		$empty = [
			'conversation_state_url' => '',
			'conversation_create_url' => '',
			'conversation_materialize_url' => '',
			'conversation_activate_url' => '',
			'conversation_rename_url' => '',
			'conversation_delete_url' => '',
			'conversation_title_url' => ''
		];
		if (empty($config['conversation_enabled'])) {
			return $empty;
		}
		$params = $this->getConfigIdentityParams($config);
		if ($params === []) {
			return $empty;
		}

		return [
			'conversation_state_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationStateOutput::getName()], $params),
			'conversation_create_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationCreateOutput::getName()], $params),
			'conversation_materialize_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationMaterializeOutput::getName()], $params),
			'conversation_activate_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationActivateOutput::getName()], $params),
			'conversation_rename_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationRenameOutput::getName()], $params),
			'conversation_delete_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationDeleteOutput::getName()], $params),
			'conversation_title_url' => $this->linkTargetService->getLink(['name' => ChatbotConversationTitleOutput::getName()], $params)
		];
	}

	/** @param array<string,mixed> $config @return array<string,string> */
	protected function getConfigIdentityParams(array $config): array {
		$group = trim((string)($config['config_group'] ?? ''));
		$name = trim((string)($config['config_name'] ?? ''));
		if ($group === '' || $name === '') {
			return [];
		}
		return ['config_group' => $group, 'config_name' => $name];
	}

	/** @param array<string,mixed> $config */
	protected function resolveBackend(array $config): string {
		$backend = strtolower(trim((string)($config['chatbot_backend'] ?? '')));
		if (preg_match('/^(runtime|service):[a-z0-9._-]+$/', $backend) === 1) {
			return $backend;
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

	private function getDefaultAiNotice(): string {
		if ($this->defaultAiNotice !== null) {
			return $this->defaultAiNotice;
		}

		$language = $this->getCurrentLanguage();

		$basePath = defined('DIR_PLUGIN') ? DIR_PLUGIN . 'Chatbot/lang/Configuration/' : '';
		$files = $basePath === ''
			? []
			: array_values(array_unique([$basePath . $language . '.ini', $basePath . 'en.ini']));

		foreach ($files as $filename) {
			if (!is_file($filename) || !is_readable($filename)) {
				continue;
			}
			$data = parse_ini_file($filename, true);
			$section = is_array($data['chatbot_configuration'] ?? null) ? $data['chatbot_configuration'] : [];
			$value = $section['default_ai_notice'] ?? null;
			if (is_scalar($value) && trim((string)$value) !== '') {
				$this->defaultAiNotice = trim((string)$value);
				return $this->defaultAiNotice;
			}
		}

		$this->defaultAiNotice = self::DEFAULT_AI_NOTICE;
		return $this->defaultAiNotice;
	}


	private function getCurrentLanguage(): string {
		$language = strtolower(str_replace('_', '-', trim($this->language->getLanguage())));
		$language = explode('-', $language)[0] ?? 'en';
		return in_array($language, ['ar', 'bg', 'de', 'en', 'es', 'fr', 'hi', 'it', 'pl', 'pt', 'ru', 'zh'], true)
			? $language
			: 'en';
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
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}

	public function getSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [
				'chatbot_backend' => [
					'type' => 'string',
					'pattern' => '^(service|runtime):[a-z0-9._-]+$',
					'default' => $this->getDefaultBackend()
				],
				'config_group' => ['type' => 'string', 'default' => ''],
				'config_name' => ['type' => 'string', 'default' => ''],
				'use_markdown' => ['type' => 'boolean', 'default' => true],
				'use_icons' => ['type' => 'boolean', 'default' => true],
				'use_voice' => ['type' => 'boolean', 'default' => true],
				'use_dialog' => ['type' => 'boolean', 'default' => true],
				'chat_history_enabled' => ['type' => 'boolean', 'default' => true],
				'chat_history_panel_mode' => [
					'type' => 'string',
					'enum' => ['responsive', 'open', 'closed'],
					'default' => 'responsive'
				],
				'automatic_chat_titles' => ['type' => 'boolean', 'default' => true],
				'main_headings' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'default' => []
				],
				'first_message_mode' => [
					'type' => 'string',
					'enum' => ['none', 'fixed', 'random', 'contextual_ai'],
					'default' => 'none'
				],
				'first_messages' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'default' => []
				],
				'ai_notice_text' => ['type' => 'string', 'minLength' => 1, 'default' => $this->getDefaultAiNotice()],
				'ai_notice_position' => [
					'type' => 'string',
					'enum' => ['above_composer', 'below_composer'],
					'default' => 'above_composer'
				],
				'transport_mode' => [
					'type' => 'string',
					'enum' => ['auto', 'sse', 'rest'],
					'default' => 'auto'
				],
				'reference_mode' => [
					'type' => 'string',
					'enum' => ['none', 'url', 'custom', 'provider'],
					'default' => 'url'
				],
				'reference' => ['type' => 'object', 'default' => []],
				'reference_provider' => ['type' => 'string', 'default' => ''],
				'default_lang' => ['type' => 'string', 'default' => 'auto'],
				'speech_to_text_service' => ['type' => 'string', 'default' => ''],
				'text_to_speech_service' => ['type' => 'string', 'default' => '']
			],
			'required' => ['chatbot_backend']
		];
	}
}
