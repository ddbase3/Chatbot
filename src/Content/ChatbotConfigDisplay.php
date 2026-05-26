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

use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Settings\Api\ISettingsStore;
use JsonException;
use Throwable;

/**
 * Class ChatbotConfigDisplay
 *
 * Provides a reusable configuration display for one concrete chatbot instance.
 *
 * Important:
 * The surrounding integration layer, e.g. ILIAS UIHook, RepositoryObject or
 * PageComponent, only needs to persist a stable instance id. The actual
 * chatbot configuration is stored here through ISettingsStore using:
 *
 * - group: the integration scope, e.g. uihk-chatbot, repo-chatbot, copg-chatbot
 * - name:  the concrete instance id, e.g. default, obj_123, pc_...
 *
 * This keeps the configuration independent from ILIAS plugin config storage
 * and allows each copied object or page component to receive its own settings
 * dataset later.
 */
class ChatbotConfigDisplay implements IDisplay {

	protected const FORM_ACTION_SAVE = 'save';

	protected array $data = [];

	protected array $messages = [];

	protected ?array $postedValues = null;

	public function __construct(
		private readonly IMvcView $view,
		private readonly IRequest $request,
		private readonly ISettingsStore $settingsStore
	) {}

	public static function getName(): string {
		return 'chatbotconfigdisplay';
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') {
			return '';
		}

		$context = $this->getContext();

		if ($this->isSaveRequest($context)) {
			$this->handleSave($context);
		}

		$values = $this->postedValues ?? $this->settingsToViewValues(
			$this->loadSettings($context)
		);

		$this->view->setPath(DIR_PLUGIN . 'Chatbot');
		$this->view->setTemplate('Content/ChatbotConfigDisplay.php');

		$this->view->assign('title', $context['title']);
		$this->view->assign('description', $context['description']);
		$this->view->assign('group', $context['group']);
		$this->view->assign('name', $context['name']);
		$this->view->assign('form_id', $context['form_id']);
		$this->view->assign('form_action', $context['form_action']);
		$this->view->assign('submit_label', $context['submit_label']);
		$this->view->assign('values', $values);
		$this->view->assign('messages', $this->messages);

		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'Configure one chatbot instance and store its settings through ISettingsStore.';
	}

	public function setData($data) {
		$this->data = is_array($data) ? $data : [];
	}

	// ---------------------------------------------------------------------
	// Context
	// ---------------------------------------------------------------------

	/**
	 * Returns the fixed storage context for the rendered configuration form.
	 *
	 * The group/name pair is not trusted from POST data. POST data is only
	 * checked against this server-side context so that multiple configuration
	 * displays can safely exist on different pages or commands.
	 */
	protected function getContext(): array {
		$group = trim((string) ($this->data['group'] ?? 'chatbot'));
		$name = trim((string) ($this->data['name'] ?? 'default'));

		if ($group === '') {
			$group = 'chatbot';
		}

		if ($name === '') {
			$name = 'default';
		}

		$title = trim((string) ($this->data['title'] ?? 'Chatbot Configuration'));
		if ($title === '') {
			$title = 'Chatbot Configuration';
		}

		$description = trim((string) ($this->data['description'] ?? 'Configure the selected chatbot instance.'));
		$submitLabel = trim((string) ($this->data['submit_label'] ?? 'Save'));

		if ($submitLabel === '') {
			$submitLabel = 'Save';
		}

		return [
			'group' => $group,
			'name' => $name,
			'title' => $title,
			'description' => $description,
			'submit_label' => $submitLabel,
			'form_id' => 'base3_chatbot_config_' . md5($group . '/' . $name),
			'form_action' => (string) ($this->data['form_action'] ?? ($_SERVER['REQUEST_URI'] ?? ''))
		];
	}

	// ---------------------------------------------------------------------
	// Save handling
	// ---------------------------------------------------------------------

	protected function isSaveRequest(array $context): bool {
		if ((string) $this->request->request('chatbot_config_action') !== self::FORM_ACTION_SAVE) {
			return false;
		}

		$postedGroup = trim((string) $this->request->request('chatbot_config_group'));
		$postedName = trim((string) $this->request->request('chatbot_config_name'));

		return $postedGroup === $context['group'] && $postedName === $context['name'];
	}

	protected function handleSave(array $context): void {
		$errors = [];
		$settings = $this->getPostedSettings($errors);
		$this->postedValues = $this->getPostedViewValues();

		if ($errors !== []) {
			foreach ($errors as $error) {
				$this->addMessage('danger', $error);
			}

			return;
		}

		try {
			$this->settingsStore->set($context['group'], $context['name'], $settings);
			$this->settingsStore->save();

			$this->postedValues = $this->settingsToViewValues($settings);
			$this->addMessage('success', 'Settings saved.');
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'Settings could not be saved: ' . $e->getMessage());
		}
	}

	protected function getPostedSettings(array &$errors): array {
		$reference = $this->decodeReferenceInput(
			trim((string) $this->request->request('reference')),
			$errors
		);

		return $this->normalizeSettings([
			'service' => trim((string) $this->request->request('service')),
			'default_lang' => trim((string) $this->request->request('default_lang')),
			'use_markdown' => $this->request->request('use_markdown') !== null,
			'use_icons' => $this->request->request('use_icons') !== null,
			'use_voice' => $this->request->request('use_voice') !== null,
			'use_threads' => $this->request->request('use_threads') !== null,
			'transport_mode' => $this->normalizeEnum(
				(string) $this->request->request('transport_mode'),
				['auto', 'sse', 'websocket', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string) $this->request->request('reference_mode'),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference' => $reference,
			'reference_provider' => trim((string) $this->request->request('reference_provider')),
			'system_prompt' => $this->normalizeTextBlock((string) $this->request->request('system_prompt'))
		]);
	}

	protected function getPostedViewValues(): array {
		return [
			'service' => trim((string) $this->request->request('service')),
			'default_lang' => trim((string) $this->request->request('default_lang')),
			'use_markdown' => $this->request->request('use_markdown') !== null,
			'use_icons' => $this->request->request('use_icons') !== null,
			'use_voice' => $this->request->request('use_voice') !== null,
			'use_threads' => $this->request->request('use_threads') !== null,
			'transport_mode' => $this->normalizeEnum(
				(string) $this->request->request('transport_mode'),
				['auto', 'sse', 'websocket', 'rest'],
				'auto'
			),
			'reference_mode' => $this->normalizeEnum(
				(string) $this->request->request('reference_mode'),
				['none', 'url', 'custom', 'provider'],
				'url'
			),
			'reference_json' => (string) $this->request->request('reference'),
			'reference_provider' => trim((string) $this->request->request('reference_provider')),
			'system_prompt' => $this->normalizeTextBlock((string) $this->request->request('system_prompt'))
		];
	}

	protected function decodeReferenceInput(string $raw, array &$errors): array {
		if ($raw === '') {
			return [];
		}

		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e) {
			$errors[] = 'Reference must be valid JSON: ' . $e->getMessage();
			return [];
		}

		if (!is_array($decoded)) {
			$errors[] = 'Reference must decode to a JSON object or array.';
			return [];
		}

		return $decoded;
	}

	protected function addMessage(string $type, string $text): void {
		$this->messages[] = [
			'type' => $type,
			'text' => $text
		];
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	protected function loadSettings(array $context): array {
		try {
			return $this->normalizeSettings(
				$this->settingsStore->get($context['group'], $context['name'], $this->getDefaultSettings())
			);
		}
		catch (Throwable $e) {
			$this->addMessage('danger', 'Settings could not be loaded: ' . $e->getMessage());
			return $this->getDefaultSettings();
		}
	}

	protected function getDefaultSettings(): array {
		return [
			'service' => 'chatbotservice.php',

			// Client-side UI feature flags.
			'use_markdown' => true,
			'use_icons' => true,
			'use_voice' => true,
			'use_threads' => true,

			// Client-side transport and reference behavior.
			'transport_mode' => 'auto',
			'reference_mode' => 'url',
			'reference' => [],
			'reference_provider' => '',

			// Voice defaults.
			'default_lang' => 'auto',

			// Server-side prompt configuration.
			// This value is intentionally not meant for client-side rendering.
			// The chatbot service will load it by config_group/config_name later.
			'system_prompt' => ''
		];
	}

	protected function normalizeSettings(array $settings): array {
		$defaults = $this->getDefaultSettings();

		return [
			'service' => trim((string) ($settings['service'] ?? $defaults['service'])),
			'use_markdown' => $this->toBool($settings['use_markdown'] ?? $defaults['use_markdown']),
			'use_icons' => $this->toBool($settings['use_icons'] ?? $defaults['use_icons']),
			'use_voice' => $this->toBool($settings['use_voice'] ?? $defaults['use_voice']),
			'use_threads' => $this->toBool($settings['use_threads'] ?? $defaults['use_threads']),
			'transport_mode' => $this->normalizeEnum(
				(string) ($settings['transport_mode'] ?? $defaults['transport_mode']),
				['auto', 'sse', 'websocket', 'rest'],
				(string) $defaults['transport_mode']
			),
			'reference_mode' => $this->normalizeEnum(
				(string) ($settings['reference_mode'] ?? $defaults['reference_mode']),
				['none', 'url', 'custom', 'provider'],
				(string) $defaults['reference_mode']
			),
			'reference' => is_array($settings['reference'] ?? null) ? $settings['reference'] : $defaults['reference'],
			'reference_provider' => trim((string) ($settings['reference_provider'] ?? $defaults['reference_provider'])),
			'default_lang' => trim((string) ($settings['default_lang'] ?? $defaults['default_lang'])),
			'system_prompt' => $this->normalizeTextBlock((string) ($settings['system_prompt'] ?? $defaults['system_prompt']))
		];
	}

	protected function settingsToViewValues(array $settings): array {
		$settings = $this->normalizeSettings($settings);

		return [
			'service' => $settings['service'],
			'use_markdown' => $settings['use_markdown'],
			'use_icons' => $settings['use_icons'],
			'use_voice' => $settings['use_voice'],
			'use_threads' => $settings['use_threads'],
			'transport_mode' => $settings['transport_mode'],
			'reference_mode' => $settings['reference_mode'],
			'reference_json' => $this->formatReferenceJson($settings['reference']),
			'reference_provider' => $settings['reference_provider'],
			'default_lang' => $settings['default_lang'],
			'system_prompt' => $settings['system_prompt']
		];
	}

	protected function formatReferenceJson(array $reference): string {
		if ($reference === []) {
			return '{}';
		}

		try {
			return json_encode(
				$reference,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);
		}
		catch (JsonException) {
			return '{}';
		}
	}

	protected function normalizeEnum(string $value, array $allowed, string $default): string {
		return in_array($value, $allowed, true) ? $value : $default;
	}

	protected function normalizeTextBlock(string $value): string {
		return str_replace(["\r\n", "\r"], "\n", $value);
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
}
