<?php
	$values = is_array($this->_['values'] ?? null) ? $this->_['values'] : [];
	$bricks = is_array($this->_['bricks']['chatbot_configuration'] ?? null) ? $this->_['bricks']['chatbot_configuration'] : [];
	$messages = is_array($this->_['messages'] ?? null) ? $this->_['messages'] : [];
	$backendOptions = is_array($this->_['backend_options'] ?? null) ? $this->_['backend_options'] : [];
	$speechToTextServices = is_array($this->_['speech_to_text_services'] ?? null) ? $this->_['speech_to_text_services'] : [];
	$textToSpeechServices = is_array($this->_['text_to_speech_services'] ?? null) ? $this->_['text_to_speech_services'] : [];
	$mainHeadings = is_array($values['main_headings'] ?? null) ? $values['main_headings'] : [];
	$firstMessages = is_array($values['first_messages'] ?? null) ? $values['first_messages'] : [];

	if ($mainHeadings === []) {
		$mainHeadings = [''];
	}
	if ($firstMessages === []) {
		$firstMessages = [''];
	}

	$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$t = static fn(string $key, string $fallback): string => trim((string)($bricks[$key] ?? '')) !== '' ? (string)$bricks[$key] : $fallback;
	$languageOptions = [
		'auto' => $t('voice_language_auto', 'Automatic'),
		'de-DE' => $t('voice_language_de_de', 'German (Germany) - de-DE'),
		'de-AT' => $t('voice_language_de_at', 'German (Austria) - de-AT'),
		'de-CH' => $t('voice_language_de_ch', 'German (Switzerland) - de-CH'),
		'en-US' => $t('voice_language_en_us', 'English (United States) - en-US'),
		'en-GB' => $t('voice_language_en_gb', 'English (United Kingdom) - en-GB'),
		'fr-FR' => $t('voice_language_fr_fr', 'French (France) - fr-FR'),
		'es-ES' => $t('voice_language_es_es', 'Spanish (Spain) - es-ES'),
		'ru-RU' => $t('voice_language_ru_ru', 'Russian (Russia) - ru-RU'),
		'it-IT' => $t('voice_language_it_it', 'Italian (Italy) - it-IT'),
		'nl-NL' => $t('voice_language_nl_nl', 'Dutch (Netherlands) - nl-NL'),
		'pl-PL' => $t('voice_language_pl_pl', 'Polish (Poland) - pl-PL'),
		'pt-PT' => $t('voice_language_pt_pt', 'Portuguese (Portugal) - pt-PT'),
		'pt-BR' => $t('voice_language_pt_br', 'Portuguese (Brazil) - pt-BR'),
		'tr-TR' => $t('voice_language_tr_tr', 'Turkish (Türkiye) - tr-TR')
	];
	$checked = static fn($value): string => !empty($value) ? ' checked="checked"' : '';
	$selected = static fn($current, $value): string => (string)$current === (string)$value ? ' selected="selected"' : '';

	$formId = (string)($this->_['form_id'] ?? 'base3_chatbot_config');
	$group = (string)($this->_['group'] ?? '');
	$name = (string)($this->_['name'] ?? '');
	$renderForm = !empty($this->_['render_form']);
	$saveMode = (string)($this->_['save_mode'] ?? 'ajax');
	$saveUrl = (string)($this->_['save_url'] ?? '');
	$useAjax = $saveMode === 'ajax';
	$currentLang = trim((string)($values['default_lang'] ?? 'auto'));
	$currentBackend = trim((string)($values['chatbot_backend'] ?? ''));
	$currentSpeechToTextService = trim((string)($values['speech_to_text_service'] ?? ''));
	$currentTextToSpeechService = trim((string)($values['text_to_speech_service'] ?? ''));
	$currentFirstMessageMode = (string)($values['first_message_mode'] ?? 'none');
	$currentReferenceMode = (string)($values['reference_mode'] ?? 'url');
	if (!in_array($currentReferenceMode, ['none', 'url', 'custom', 'provider'], true)) {
		$currentReferenceMode = 'url';
	}
	$currentBackendUrl = '';
	$currentBackendDescription = '';
	$backendOptionIds = [];

	if ($currentLang === '') {
		$currentLang = 'auto';
	}

	foreach ($backendOptions as $backendOption) {
		$backendId = (string)($backendOption['id'] ?? '');

		if ($backendId === '') {
			continue;
		}

		$backendOptionIds[$backendId] = true;

		if ($backendId === $currentBackend) {
			$currentBackendUrl = (string)($backendOption['url'] ?? '');
			$currentBackendDescription = (string)($backendOption['description'] ?? '');
		}
	}
?>

<style>
	.base3-chatbot-config-display,
	.base3-chatbot-config-display * {
		box-sizing: border-box;
	}

	.base3-chatbot-config-display {
		width: 100%;
		max-width: 980px;
		margin: 0;
	}

	.base3-chatbot-config-display h2 {
		margin: 0 0 8px;
	}

	.base3-chatbot-config-description {
		margin: 0 0 12px;
		color: #555;
	}

	.base3-chatbot-config-instance {
		margin: 0 0 18px;
		padding: 7px 10px;
		border-left: 3px solid #ddd;
		background: #fafafa;
		color: #666;
		font-size: 12px;
	}

	.base3-chatbot-config-instance code {
		color: inherit;
		font-size: 12px;
		background: transparent;
	}

	.base3-chatbot-config-messages {
		margin: 0 0 12px;
	}

	.base3-chatbot-config-message {
		margin: 0 0 12px;
		padding: 10px 12px;
		border: 1px solid #ddd;
		border-left-width: 4px;
		background: #fff;
	}

	.base3-chatbot-config-message-success {
		border-left-color: #5cb85c;
	}

	.base3-chatbot-config-message-danger {
		border-left-color: #d9534f;
	}

	.base3-chatbot-config-message-info {
		border-left-color: #5bc0de;
	}

	.base3-chatbot-config-section,
	.base3-chatbot-config-group {
		margin: 0 0 18px;
		padding: 16px;
		border: 1px solid #ddd;
		border-radius: 6px;
		background: #fff;
	}

	.base3-chatbot-config-section h3,
	.base3-chatbot-config-group h3 {
		margin: 0 0 14px;
		font-size: 18px;
	}

	.base3-chatbot-config-row {
		display: grid;
		grid-template-columns: minmax(150px, 220px) minmax(0, 1fr);
		gap: 8px 18px;
		margin: 0 0 14px;
	}

	.base3-chatbot-config-row:last-child {
		margin-bottom: 0;
	}

	.base3-chatbot-config-label {
		padding-top: 7px;
		font-weight: 600;
	}

	.base3-chatbot-config-fieldset {
		min-width: 0;
		margin: 0 0 14px;
		padding: 0;
		border: 0;
	}

	.base3-chatbot-config-fieldset[hidden] {
		display: none;
	}

	.base3-chatbot-config-fieldset > legend {
		float: left;
		width: 100%;
		margin: 0;
		padding: 7px 0 0;
		font-size: inherit;
		font-weight: 600;
		line-height: inherit;
	}

	.base3-chatbot-visually-hidden {
		position: absolute !important;
		width: 1px !important;
		height: 1px !important;
		padding: 0 !important;
		margin: -1px !important;
		overflow: hidden !important;
		clip: rect(0, 0, 0, 0) !important;
		white-space: nowrap !important;
		border: 0 !important;
	}

	.base3-chatbot-config-display input[type="text"],
	.base3-chatbot-config-display select,
	.base3-chatbot-config-display textarea {
		width: 100%;
		max-width: 760px;
		min-height: 34px;
		padding: 6px 8px;
		border: 1px solid #bbb;
		border-radius: 3px;
		background: #fff;
		color: inherit;
		font: inherit;
		line-height: 1.4;
	}

	.base3-chatbot-config-display textarea {
		max-width: 760px;
		resize: vertical;
		font-family: monospace;
	}

	.base3-chatbot-config-json {
		min-height: 140px;
	}

	.base3-chatbot-config-reference-field {
		margin-top: 12px;
	}

	.base3-chatbot-config-reference-field[hidden] {
		display: none;
	}

	.base3-chatbot-config-reference-field > label {
		display: block;
		margin: 0 0 5px;
		font-weight: 600;
	}

	.base3-chatbot-config-help {
		max-width: 800px;
		margin: 5px 0 0;
		color: #666;
		font-size: 12px;
		line-height: 1.4;
	}

	.base3-chatbot-config-service-url {
		display: block;
		max-width: 760px;
		margin-top: 4px;
		white-space: normal;
		word-break: break-all;
	}

	.base3-chatbot-config-checkboxes label {
		display: block;
		margin: 0 0 7px;
		font-weight: normal;
	}

	.base3-chatbot-config-checkboxes input {
		margin-right: 6px;
	}

	.base3-chatbot-config-message-list {
		max-width: 760px;
	}

	.base3-chatbot-config-message-row {
		display: flex;
		gap: 7px;
		align-items: center;
		margin: 0 0 7px;
	}

	.base3-chatbot-config-message-row input[type="text"] {
		max-width: none;
		flex: 1 1 auto;
	}

	.base3-chatbot-config-message-remove,
	.base3-chatbot-config-message-add {
		min-height: 34px;
		padding: 6px 10px;
		cursor: pointer;
		white-space: nowrap;
	}

	.base3-chatbot-config-message-add {
		margin-top: 1px;
	}

	.base3-chatbot-config-actions {
		margin-top: 4px;
	}

	.base3-chatbot-config-submit {
		min-width: 120px;
		padding: 7px 14px;
		cursor: pointer;
	}

	.base3-chatbot-config-submit[disabled] {
		cursor: wait;
		opacity: 0.65;
	}

	@media (max-width: 700px) {
		.base3-chatbot-config-section,
		.base3-chatbot-config-group {
			padding: 12px;
		}

		.base3-chatbot-config-row {
			display: block;
		}

		.base3-chatbot-config-label,
		.base3-chatbot-config-fieldset > legend {
			display: block;
			float: none;
			width: auto;
			padding-top: 0;
			margin: 0 0 5px;
		}

		.base3-chatbot-config-display input[type="text"],
		.base3-chatbot-config-display select,
		.base3-chatbot-config-display textarea {
			max-width: none;
		}

		.base3-chatbot-config-message-row {
			display: block;
		}

		.base3-chatbot-config-message-remove {
			margin-top: 5px;
		}
	}
</style>

<div class="base3-chatbot-config-display">
<?php if ($renderForm) { ?>
	<form
		id="<?php echo $e($formId); ?>"
		method="post"
		action="<?php echo $e($this->_['form_action'] ?? ''); ?>"
		data-base3-chatbot-config-root="1"
		data-save-url="<?php echo $e($saveUrl); ?>"
		data-save-mode="<?php echo $e($saveMode); ?>"
	>
<?php } else { ?>
	<div
		id="<?php echo $e($formId); ?>"
		class="base3-chatbot-config-fields"
		data-base3-chatbot-config-root="1"
		data-save-url="<?php echo $e($saveUrl); ?>"
		data-save-mode="<?php echo $e($saveMode); ?>"
	>
<?php } ?>
		<h2><?php echo $e($this->_['title'] ?? $t('title', 'Chatbot Configuration')); ?></h2>

<?php if (!empty($this->_['description'])) { ?>
		<p class="base3-chatbot-config-description"><?php echo $e($this->_['description']); ?></p>
<?php } ?>

		<div class="base3-chatbot-config-instance">
			<?php echo $e($t('instance_label', 'Instance')); ?>:
			<code><?php echo $e($group); ?></code>
			/
			<code><?php echo $e($name); ?></code>
		</div>

		<input type="hidden" name="chatbot_config_action" value="save" />
		<input type="hidden" name="chatbot_config_group" value="<?php echo $e($group); ?>" />
		<input type="hidden" name="chatbot_config_name" value="<?php echo $e($name); ?>" />

		<div class="base3-chatbot-config-group">
			<h3><?php echo $e($t('start_section', 'Chat start')); ?></h3>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_first_message_mode" class="base3-chatbot-config-label"><?php echo $e($t('first_message_mode_label', 'Start mode')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_first_message_mode" name="first_message_mode" class="form-control" aria-describedby="<?php echo $e($formId); ?>_first_message_mode_help">
						<option value="none"<?php echo $selected($currentFirstMessageMode, 'none'); ?>><?php echo $e($t('first_message_mode_none', 'The user starts the chat')); ?></option>
						<option value="random"<?php echo $selected($currentFirstMessageMode, 'random'); ?>><?php echo $e($t('first_message_mode_random', 'Random first assistant message')); ?></option>
						<option value="contextual_ai"<?php echo $selected($currentFirstMessageMode, 'contextual_ai'); ?>><?php echo $e($t('first_message_mode_contextual_ai', 'Contextual first AI message')); ?></option>
					</select>
					<p id="<?php echo $e($formId); ?>_first_message_mode_help" class="base3-chatbot-config-help"><?php echo $e($t('first_message_mode_help', 'Defines how a new chat starts. Depending on the selected mode, main headings or prepared first assistant messages can be configured.')); ?></p>
				</div>
			</div>

			<fieldset
				class="base3-chatbot-config-row base3-chatbot-config-fieldset"
				data-base3-chatbot-main-headings
				<?php echo $currentFirstMessageMode === 'none' ? '' : 'hidden'; ?>
			>
				<legend><?php echo $e($t('main_headings_label', 'Main headings')); ?></legend>
				<div>
					<div class="base3-chatbot-config-message-list" data-base3-chatbot-message-list="main_headings">
						<div data-base3-chatbot-message-list-items="main_headings">
<?php foreach ($mainHeadings as $mainHeadingIndex => $mainHeading) {
$mainHeadingId = $formId . '_main_heading_' . $mainHeadingIndex;
?>
							<div class="base3-chatbot-config-message-row">
								<label class="base3-chatbot-visually-hidden" for="<?php echo $e($mainHeadingId); ?>"><?php echo $e($t('main_heading_item_label', 'Main heading')); ?> <?php echo $e((string)($mainHeadingIndex + 1)); ?></label>
								<input id="<?php echo $e($mainHeadingId); ?>" type="text" name="main_headings[]" class="form-control" value="<?php echo $e($mainHeading); ?>" placeholder="<?php echo $e($t('main_heading_placeholder', 'What can I help you with?')); ?>" />
								<button type="button" class="btn btn-default base3-chatbot-config-message-remove" data-base3-chatbot-message-remove="main_headings"><?php echo $e($t('remove', 'Remove')); ?></button>
							</div>
<?php } ?>
						</div>
						<button type="button" class="btn btn-default base3-chatbot-config-message-add" data-base3-chatbot-message-add="main_headings">
							<?php echo $e($t('add_main_heading', 'Add main heading')); ?>
						</button>
						<p class="base3-chatbot-config-help"><?php echo $e($t('main_headings_help', 'One heading is fixed. With several headings one is selected randomly for each new chat. With no heading this area is omitted. The heading is not part of the conversation and disappears as soon as the chat contains a message.')); ?></p>
					</div>
				</div>
			</fieldset>

			<fieldset
				class="base3-chatbot-config-row base3-chatbot-config-fieldset"
				data-base3-chatbot-first-messages
				<?php echo $currentFirstMessageMode === 'random' ? '' : 'hidden'; ?>
			>
				<legend><?php echo $e($t('first_messages_label', 'First assistant messages')); ?></legend>
				<div>
					<div class="base3-chatbot-config-message-list" data-base3-chatbot-message-list="first_messages">
						<div data-base3-chatbot-message-list-items="first_messages">
<?php foreach ($firstMessages as $firstMessageIndex => $firstMessage) {
$firstMessageId = $formId . '_first_message_' . $firstMessageIndex;
?>
							<div class="base3-chatbot-config-message-row">
								<label class="base3-chatbot-visually-hidden" for="<?php echo $e($firstMessageId); ?>"><?php echo $e($t('first_message_item_label', 'First assistant message')); ?> <?php echo $e((string)($firstMessageIndex + 1)); ?></label>
								<input id="<?php echo $e($firstMessageId); ?>" type="text" name="first_messages[]" class="form-control" value="<?php echo $e($firstMessage); ?>" placeholder="<?php echo $e($t('first_message_placeholder', 'How can I help you?')); ?>" />
								<button type="button" class="btn btn-default base3-chatbot-config-message-remove" data-base3-chatbot-message-remove="first_messages"><?php echo $e($t('remove', 'Remove')); ?></button>
							</div>
<?php } ?>
						</div>
						<button type="button" class="btn btn-default base3-chatbot-config-message-add" data-base3-chatbot-message-add="first_messages">
							<?php echo $e($t('add_first_message', 'Add first assistant message')); ?>
						</button>
						<p class="base3-chatbot-config-help"><?php echo $e($t('first_messages_help', 'Used only for the random first-message mode. Empty fields are ignored.')); ?></p>
					</div>
				</div>
			</fieldset>
		</div>


		<div class="base3-chatbot-config-group">
			<h3><?php echo $e($t('history_section', 'Chat history')); ?></h3>

			<fieldset class="base3-chatbot-config-row base3-chatbot-config-fieldset">
				<legend><?php echo $e($t('history_label', 'Chat history')); ?></legend>
				<div class="base3-chatbot-config-checkboxes">
					<label>
						<input type="checkbox" name="chat_history_enabled" value="1"<?php echo $checked($values['chat_history_enabled'] ?? false); ?> />
						<?php echo $e($t('history_enabled', 'Enable multiple chats and the chat list')); ?>
					</label>
					<label>
						<input type="checkbox" name="automatic_chat_titles" value="1"<?php echo $checked($values['automatic_chat_titles'] ?? false); ?> />
						<?php echo $e($t('automatic_titles', 'Generate chat titles automatically')); ?>
					</label>
					<p class="base3-chatbot-config-help"><?php echo $e($t('history_help', 'Conversation history is active only when the selected agent has a conversation memory profile.')); ?></p>
				</div>
			</fieldset>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_chat_history_panel_mode" class="base3-chatbot-config-label"><?php echo $e($t('history_panel_mode', 'Chat list display')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_chat_history_panel_mode" name="chat_history_panel_mode" class="form-control">
						<option value="responsive"<?php echo $selected($values['chat_history_panel_mode'] ?? 'responsive', 'responsive'); ?>><?php echo $e($t('history_panel_responsive', 'Responsive')); ?></option>
						<option value="open"<?php echo $selected($values['chat_history_panel_mode'] ?? 'responsive', 'open'); ?>><?php echo $e($t('history_panel_open', 'Initially open')); ?></option>
						<option value="closed"<?php echo $selected($values['chat_history_panel_mode'] ?? 'responsive', 'closed'); ?>><?php echo $e($t('history_panel_closed', 'Initially closed')); ?></option>
					</select>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-group">
			<h3><?php echo $e($t('features_transport_section', 'Features and transport')); ?></h3>

			<fieldset class="base3-chatbot-config-row base3-chatbot-config-fieldset">
				<legend><?php echo $e($t('features_label', 'Features')); ?></legend>
				<div class="base3-chatbot-config-checkboxes">
					<label>
						<input type="checkbox" name="use_markdown" value="1"<?php echo $checked($values['use_markdown'] ?? false); ?> />
						<?php echo $e($t('feature_markdown', 'Enable markdown rendering')); ?>
					</label>
					<label>
						<input type="checkbox" name="use_icons" value="1"<?php echo $checked($values['use_icons'] ?? false); ?> />
						<?php echo $e($t('feature_icons', 'Show dialog action icons')); ?>
					</label>
				</div>
			</fieldset>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_transport_mode" class="base3-chatbot-config-label"><?php echo $e($t('transport_mode_label', 'Response delivery')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_transport_mode" name="transport_mode" class="form-control" aria-describedby="<?php echo $e($formId); ?>_transport_mode_help">
						<option value="auto"<?php echo $selected($values['transport_mode'] ?? 'auto', 'auto'); ?>><?php echo $e($t('transport_option_auto', 'Automatic (recommended)')); ?></option>
						<option value="sse"<?php echo $selected($values['transport_mode'] ?? 'auto', 'sse'); ?>><?php echo $e($t('transport_option_sse', 'Show while generated (SSE)')); ?></option>
						<option value="rest"<?php echo $selected($values['transport_mode'] ?? 'auto', 'rest'); ?>><?php echo $e($t('transport_option_rest', 'Show when complete (REST)')); ?></option>
					</select>
					<p id="<?php echo $e($formId); ?>_transport_mode_help" class="base3-chatbot-config-help"><?php echo $e($t('transport_mode_help', 'Automatic selects the best available mode. Live delivery shows the answer while it is being generated. Complete delivery waits until the full answer is ready.')); ?></p>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_ai_notice_text" class="base3-chatbot-config-label"><?php echo $e($t('ai_notice_label', 'AI notice')); ?></label>
				<div>
					<textarea id="<?php echo $e($formId); ?>_ai_notice_text" name="ai_notice_text" class="form-control" rows="3" required="required" aria-describedby="<?php echo $e($formId); ?>_ai_notice_help"><?php echo $e($values['ai_notice_text'] ?? ''); ?></textarea>
					<p id="<?php echo $e($formId); ?>_ai_notice_help" class="base3-chatbot-config-help"><?php echo $e($t('ai_notice_help', 'This visible notice is displayed directly below the message composer.')); ?></p>
				</div>
			</div>

			<fieldset class="base3-chatbot-config-row base3-chatbot-config-fieldset">
				<legend><?php echo $e($t('reference_section', 'Reference context')); ?></legend>
				<div>
					<label for="<?php echo $e($formId); ?>_reference_mode" class="base3-chatbot-visually-hidden"><?php echo $e($t('reference_mode_label', 'Reference mode')); ?></label>
					<select id="<?php echo $e($formId); ?>_reference_mode" name="reference_mode" class="form-control" aria-describedby="<?php echo $e($formId); ?>_reference_mode_help">
						<option value="none"<?php echo $selected($currentReferenceMode, 'none'); ?>><?php echo $e($t('reference_option_none', 'None')); ?></option>
						<option value="url"<?php echo $selected($currentReferenceMode, 'url'); ?>><?php echo $e($t('reference_option_url', 'Current URL')); ?></option>
						<option value="custom"<?php echo $selected($currentReferenceMode, 'custom'); ?>><?php echo $e($t('reference_option_custom', 'Custom JSON')); ?></option>
						<option value="provider"<?php echo $selected($currentReferenceMode, 'provider'); ?>><?php echo $e($t('reference_option_provider', 'Provider')); ?></option>
					</select>
					<p id="<?php echo $e($formId); ?>_reference_mode_help" class="base3-chatbot-config-help">
						<?php echo $e($t('reference_mode_help', 'Controls which contextual reference is sent with requests. The service can store this in the agent context.')); ?>
					</p>

					<div
						class="base3-chatbot-config-reference-field"
						data-base3-chatbot-reference-custom
						<?php echo $currentReferenceMode === 'custom' ? '' : 'hidden'; ?>
					>
						<label for="<?php echo $e($formId); ?>_reference"><?php echo $e($t('static_reference_label', 'Static reference JSON')); ?></label>
						<textarea id="<?php echo $e($formId); ?>_reference" name="reference" class="form-control base3-chatbot-config-json"><?php echo $e($values['reference_json'] ?? '{}'); ?></textarea>
						<p class="base3-chatbot-config-help">
							<?php echo $e($t('static_reference_help', 'Used only for custom reference mode. Must be valid JSON.')); ?>
						</p>
					</div>

					<div
						class="base3-chatbot-config-reference-field"
						data-base3-chatbot-reference-provider
						<?php echo $currentReferenceMode === 'provider' ? '' : 'hidden'; ?>
					>
						<label for="<?php echo $e($formId); ?>_reference_provider"><?php echo $e($t('reference_provider_label', 'Reference provider')); ?></label>
						<input type="text" id="<?php echo $e($formId); ?>_reference_provider" name="reference_provider" class="form-control" value="<?php echo $e($values['reference_provider'] ?? ''); ?>" />
						<p class="base3-chatbot-config-help">
							<?php echo $e($t('reference_provider_help', 'Global JavaScript function name used by provider reference mode.')); ?>
						</p>
					</div>
				</div>
			</fieldset>
		</div>

		<div class="base3-chatbot-config-group">
			<h3><?php echo $e($t('voice_section', 'Voice control')); ?></h3>

			<div class="base3-chatbot-config-row">
				<div class="base3-chatbot-config-label"><?php echo $e($t('voice_enabled_label', 'Activation')); ?></div>
				<div class="base3-chatbot-config-checkboxes">
					<label>
						<input type="checkbox" name="use_voice" value="1"<?php echo $checked($values['use_voice'] ?? false); ?> />
						<?php echo $e($t('feature_voice', 'Enable voice controls')); ?>
					</label>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_default_lang" class="base3-chatbot-config-label"><?php echo $e($t('voice_language_label', 'Voice language')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_default_lang" name="default_lang" class="form-control">
<?php foreach ($languageOptions as $languageValue => $languageLabel) { ?>
						<option value="<?php echo $e($languageValue); ?>"<?php echo $selected($currentLang, $languageValue); ?>><?php echo $e($languageLabel); ?></option>
<?php } ?>
<?php if (!array_key_exists($currentLang, $languageOptions)) { ?>
						<option value="<?php echo $e($currentLang); ?>" selected="selected"><?php echo $e($t('current_custom_value', 'Current custom value')); ?>: <?php echo $e($currentLang); ?></option>
<?php } ?>
					</select>
					<p class="base3-chatbot-config-help"><?php echo $e($t('voice_language_help', 'Language hint for browser text-to-speech output. Use auto unless the integration should force a specific speech language.')); ?></p>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_speech_to_text_service" class="base3-chatbot-config-label"><?php echo $e($t('speech_to_text_label', 'Speech-to-text service')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_speech_to_text_service" name="speech_to_text_service" class="form-control">
						<option value=""<?php echo $selected($currentSpeechToTextService, ''); ?>><?php echo $e($t('speech_to_text_browser', 'Browser speech recognition')); ?></option>
<?php foreach ($speechToTextServices as $speechService) {
$speechServiceId = (string)($speechService['id'] ?? '');
if ($speechServiceId === '') continue;
$speechServiceLabel = trim((string)($speechService['name'] ?? '')) ?: $speechServiceId;
$driverLabel = trim((string)($speechService['driver'] ?? ''));
?>
						<option value="<?php echo $e($speechServiceId); ?>"<?php echo $selected($currentSpeechToTextService, $speechServiceId); ?>><?php echo $e($speechServiceLabel . ($driverLabel !== '' ? ' — ' . $driverLabel : '')); ?></option>
<?php } ?>
					</select>
					<p class="base3-chatbot-config-help"><?php echo $e($t('speech_to_text_help', 'A configured realtime service displays interim transcripts while the user is speaking. Browser speech recognition remains available without a service.')); ?></p>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_text_to_speech_service" class="base3-chatbot-config-label"><?php echo $e($t('text_to_speech_label', 'Text-to-speech service')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_text_to_speech_service" name="text_to_speech_service" class="form-control">
						<option value=""<?php echo $selected($currentTextToSpeechService, ''); ?>><?php echo $e($t('text_to_speech_browser', 'Browser speech synthesis')); ?></option>
<?php foreach ($textToSpeechServices as $speechService) {
$speechServiceId = (string)($speechService['id'] ?? '');
if ($speechServiceId === '') continue;
$speechServiceLabel = trim((string)($speechService['name'] ?? '')) ?: $speechServiceId;
$driverLabel = trim((string)($speechService['driver'] ?? ''));
$voiceLabel = trim((string)($speechService['voice'] ?? ''));
$details = array_values(array_filter([$driverLabel, $voiceLabel], static fn($value): bool => $value !== ''));
?>
						<option value="<?php echo $e($speechServiceId); ?>"<?php echo $selected($currentTextToSpeechService, $speechServiceId); ?>><?php echo $e($speechServiceLabel . ($details !== [] ? ' — ' . implode(' / ', $details) : '')); ?></option>
<?php } ?>
					</select>
					<p class="base3-chatbot-config-help"><?php echo $e($t('text_to_speech_help', 'A configured service generates assistant speech through the server. Browser speech synthesis remains available without a service.')); ?></p>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-section">
			<h3><?php echo $e($t('backend_section', 'Chatbot backend')); ?></h3>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_backend" class="base3-chatbot-config-label"><?php echo $e($t('backend_label', 'Backend')); ?></label>
				<div>
					<select id="<?php echo $e($formId); ?>_backend" name="chatbot_backend" class="form-control" data-base3-chatbot-backend-select="1">
<?php if ($backendOptions === []) { ?>
						<option value=""><?php echo $e($t('backend_none', 'No chatbot backends found')); ?></option>
<?php } else { ?>
						<option value=""><?php echo $e($t('backend_select', 'Select chatbot backend')); ?></option>
<?php if ($currentBackend !== '' && !isset($backendOptionIds[$currentBackend])) { ?>
						<option value="<?php echo $e($currentBackend); ?>" selected="selected" disabled="disabled"><?php echo $e($t('backend_unknown_prefix', 'Unknown backend:')); ?> <?php echo $e($currentBackend); ?></option>
<?php } ?>
<?php foreach ($backendOptions as $backendOption) {
	$backendId = (string)($backendOption['id'] ?? '');
	if ($backendId === '') {
		continue;
	}
	$label = trim((string)($backendOption['label'] ?? '')) ?: $backendId;
	$description = (string)($backendOption['description'] ?? '');
	$url = (string)($backendOption['url'] ?? '');
?>
						<option value="<?php echo $e($backendId); ?>" data-description="<?php echo $e($description); ?>" data-url="<?php echo $e($url); ?>"<?php echo $selected($currentBackend, $backendId); ?>><?php echo $e($label); ?></option>
<?php } ?>
<?php } ?>
					</select>

					<p class="base3-chatbot-config-help" data-base3-chatbot-backend-description>
						<?php echo $e($currentBackendDescription !== '' ? $currentBackendDescription : $t('backend_description_default', 'Select the direct dummy service or one of the registered agent runtimes.')); ?>
					</p>

					<p class="base3-chatbot-config-help">
						<?php echo $e($t('generated_endpoint_label', 'Generated endpoint:')); ?>
						<code class="base3-chatbot-config-service-url" data-base3-chatbot-backend-url><?php echo $e($currentBackendUrl !== '' ? $currentBackendUrl : $t('no_endpoint', 'No endpoint generated.')); ?></code>
					</p>
				</div>
			</div>
		</div>

<?php
	$agentConfigTemplate = (string)($this->_['agent_config_template'] ?? '');
	if ($agentConfigTemplate !== '' && is_file($agentConfigTemplate)) {
		include $agentConfigTemplate;
	}
?>

		<div class="base3-chatbot-config-row base3-chatbot-config-actions">
			<div>
				<div class="base3-chatbot-config-messages" data-base3-chatbot-config-messages role="status" aria-live="polite" aria-atomic="true">
<?php foreach ($messages as $message) {
	$type = preg_replace('/[^a-z]/', '', (string)($message['type'] ?? 'info'));
	if ($type === '') {
		$type = 'info';
	}
?>
					<div class="base3-chatbot-config-message base3-chatbot-config-message-<?php echo $e($type); ?> alert alert-<?php echo $e($type); ?>">
						<?php echo $e($message['text'] ?? ''); ?>
					</div>
<?php } ?>
				</div>

				<button type="<?php echo $renderForm ? 'submit' : 'button'; ?>" class="btn btn-primary base3-chatbot-config-submit" data-base3-chatbot-config-save="1">
					<?php echo $e($this->_['submit_label'] ?? $t('save', 'Save')); ?>
				</button>
			</div>
		</div>

<?php if ($renderForm) { ?>
	</form>
<?php } else { ?>
	</div>
<?php } ?>
</div>

<?php if ($useAjax) { ?>
<script>
(function() {
	var root = document.getElementById(<?php echo json_encode($formId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);

	if (!root || root.getAttribute('data-base3-chatbot-config-ready') === '1') {
		return;
	}

	root.setAttribute('data-base3-chatbot-config-ready', '1');

	var button = root.querySelector('[data-base3-chatbot-config-save]');
	var messages = root.querySelector('[data-base3-chatbot-config-messages]');
	var saveUrl = root.getAttribute('data-save-url') || <?php echo json_encode($saveUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var backendSelect = root.querySelector('[data-base3-chatbot-backend-select]');
	var backendDescription = root.querySelector('[data-base3-chatbot-backend-description]');
	var backendUrl = root.querySelector('[data-base3-chatbot-backend-url]');
	var agentConfigRoot = root.querySelector('[data-base3-agent-config-root]');
	var firstMessageMode = root.querySelector('[name="first_message_mode"]');
	var referenceMode = root.querySelector('[name="reference_mode"]');
	var referenceCustomField = root.querySelector('[data-base3-chatbot-reference-custom]');
	var referenceProviderField = root.querySelector('[data-base3-chatbot-reference-provider]');
	var mainHeadingsFieldset = root.querySelector('[data-base3-chatbot-main-headings]');
	var firstMessagesFieldset = root.querySelector('[data-base3-chatbot-first-messages]');
	var removeLabel = <?php echo json_encode($t('remove', 'Remove'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var saveErrorPrefix = <?php echo json_encode($t('save_error_prefix', 'Settings could not be saved:'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var backendDescriptionFallback = <?php echo json_encode($t('backend_description_default', 'Select the direct dummy service or one of the registered agent runtimes.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var noEndpointLabel = <?php echo json_encode($t('no_endpoint', 'No endpoint generated.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var messageLists = {
		main_headings: {
			items: root.querySelector('[data-base3-chatbot-message-list-items="main_headings"]'),
			add: root.querySelector('[data-base3-chatbot-message-add="main_headings"]'),
			label: <?php echo json_encode($t('main_heading_item_label', 'Main heading'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
			placeholder: <?php echo json_encode($t('main_heading_placeholder', 'What can I help you with?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
			idPrefix: <?php echo json_encode($formId . '_main_heading_dynamic_', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
		},
		first_messages: {
			items: root.querySelector('[data-base3-chatbot-message-list-items="first_messages"]'),
			add: root.querySelector('[data-base3-chatbot-message-add="first_messages"]'),
			label: <?php echo json_encode($t('first_message_item_label', 'First assistant message'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
			placeholder: <?php echo json_encode($t('first_message_placeholder', 'How can I help you?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
			idPrefix: <?php echo json_encode($formId . '_first_message_dynamic_', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
		}
	};
	Object.keys(messageLists).forEach(function(key) {
		var config = messageLists[key];
		config.counter = config.items ? config.items.querySelectorAll('[name="' + key + '[]"]').length : 0;
	});

	if (!button || !saveUrl) {
		return;
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function getRuntimeIdFromBackend(value) {
		value = String(value || '');
		return value.indexOf('runtime:') === 0 ? value.substring(8) : '';
	}

	function updateStartModeFields() {
		var mode = firstMessageMode ? firstMessageMode.value : 'none';

		if (mainHeadingsFieldset) {
			mainHeadingsFieldset.hidden = mode !== 'none';
		}
		if (firstMessagesFieldset) {
			firstMessagesFieldset.hidden = mode !== 'random';
		}
	}

	function updateReferenceFields() {
		var mode = referenceMode ? referenceMode.value : 'url';

		if (referenceCustomField) {
			referenceCustomField.hidden = mode !== 'custom';
		}
		if (referenceProviderField) {
			referenceProviderField.hidden = mode !== 'provider';
		}
	}

	function updateBackend() {
		if (!backendSelect) {
			return;
		}

		var option = backendSelect.options[backendSelect.selectedIndex];
		var description = option ? option.getAttribute('data-description') || '' : '';
		var url = option ? option.getAttribute('data-url') || '' : '';
		var runtimeId = getRuntimeIdFromBackend(backendSelect.value);

		if (backendDescription) {
			backendDescription.textContent = description || backendDescriptionFallback;
		}
		if (backendUrl) {
			backendUrl.textContent = url || noEndpointLabel;
		}
		if (agentConfigRoot && typeof agentConfigRoot.__base3AgentConfigSelectRuntime === 'function') {
			agentConfigRoot.__base3AgentConfigSelectRuntime(runtimeId, runtimeId !== '');
		}
	}

	function createMessageRow(key, value) {
		var config = messageLists[key];
		if (!config) {
			return document.createDocumentFragment();
		}

		var row = document.createElement('div');
		var label = document.createElement('label');
		var input = document.createElement('input');
		var remove = document.createElement('button');
		var inputId = config.idPrefix + config.counter;
		config.counter += 1;

		row.className = 'base3-chatbot-config-message-row';
		label.className = 'base3-chatbot-visually-hidden';
		label.htmlFor = inputId;
		label.appendChild(document.createTextNode(config.label + ' ' + config.counter));

		input.id = inputId;
		input.type = 'text';
		input.name = key + '[]';
		input.className = 'form-control';
		input.value = value || '';
		input.placeholder = config.placeholder;

		remove.type = 'button';
		remove.className = 'btn btn-default base3-chatbot-config-message-remove';
		remove.setAttribute('data-base3-chatbot-message-remove', key);
		remove.appendChild(document.createTextNode(removeLabel));
		remove.addEventListener('click', function() {
			if (row.parentNode) {
				row.parentNode.removeChild(row);
			}
			if (config.items && !config.items.querySelector('[name="' + key + '[]"]')) {
				config.items.appendChild(createMessageRow(key, ''));
			}
		});

		row.appendChild(label);
		row.appendChild(input);
		row.appendChild(remove);
		return row;
	}

	function renderMessageList(key, items) {
		var config = messageLists[key];
		if (!config || !config.items) {
			return;
		}
		if (!Array.isArray(items) || items.length === 0) {
			items = [''];
		}

		config.items.innerHTML = '';
		items.forEach(function(item) {
			config.items.appendChild(createMessageRow(key, item));
		});
	}

	function collectFormData() {
		if (agentConfigRoot && typeof agentConfigRoot.__base3AgentConfigPrepareSubmit === 'function') {
			agentConfigRoot.__base3AgentConfigPrepareSubmit();
		}

		if (root.tagName && root.tagName.toLowerCase() === 'form') {
			return new FormData(root);
		}

		var formData = new FormData();
		var fields = root.querySelectorAll('input, select, textarea');

		fields.forEach(function(field) {
			if (!field.name || field.disabled) {
				return;
			}

			if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
				return;
			}

			formData.append(field.name, field.value);
		});

		return formData;
	}

	function renderMessages(items) {
		if (!messages) {
			return;
		}

		if (!Array.isArray(items) || items.length === 0) {
			messages.innerHTML = '';
			return;
		}

		messages.innerHTML = items.map(function(item) {
			var type = String(item.type || 'info').replace(/[^a-z]/g, '') || 'info';
			var text = item.text || '';

			return '<div class="base3-chatbot-config-message base3-chatbot-config-message-' + escapeHtml(type) + ' alert alert-' + escapeHtml(type) + '">' + escapeHtml(text) + '</div>';
		}).join('');
	}

	function updateValues(values) {
		if (!values || typeof values !== 'object') {
			return;
		}

		var map = {
			chatbot_backend: 'chatbot_backend',
			first_message_mode: 'first_message_mode',
			chat_history_panel_mode: 'chat_history_panel_mode',
			ai_notice_text: 'ai_notice_text',
			default_lang: 'default_lang',
			speech_to_text_service: 'speech_to_text_service',
			text_to_speech_service: 'text_to_speech_service',
			transport_mode: 'transport_mode',
			reference_mode: 'reference_mode',
			reference_json: 'reference',
			reference_provider: 'reference_provider'
		};

		Object.keys(map).forEach(function(key) {
			if (!Object.prototype.hasOwnProperty.call(values, key)) {
				return;
			}

			var field = root.querySelector('[name="' + map[key] + '"]');

			if (field) {
				field.value = values[key];
			}
		});

		['main_headings', 'first_messages'].forEach(function(key) {
			if (Object.prototype.hasOwnProperty.call(values, key)) {
				renderMessageList(key, values[key]);
			}
		});

		['use_markdown', 'use_icons', 'use_voice', 'chat_history_enabled', 'automatic_chat_titles'].forEach(function(key) {
			var field = root.querySelector('[name="' + key + '"]');

			if (field) {
				field.checked = !!values[key];
			}
		});

		if (agentConfigRoot && typeof agentConfigRoot.__base3AgentConfigUpdateValues === 'function') {
			agentConfigRoot.__base3AgentConfigUpdateValues(values);
		}

		updateBackend();
		updateStartModeFields();
		updateReferenceFields();
	}

	function save(event) {
		if (event) {
			event.preventDefault();
		}

		button.disabled = true;

		fetch(saveUrl, {
			method: 'POST',
			body: collectFormData(),
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		})
			.then(function(response) {
				return response.json();
			})
			.then(function(json) {
				renderMessages(json.messages || []);
				updateValues(json.values || null);
			})
			.catch(function(error) {
				renderMessages([
					{
						type: 'danger',
						text: saveErrorPrefix + ' ' + error.message
					}
				]);
			})
			.finally(function() {
				button.disabled = false;
			});
	}

	Object.keys(messageLists).forEach(function(key) {
		var config = messageLists[key];
		if (!config.items || !config.add) {
			return;
		}
		config.add.addEventListener('click', function() {
			config.items.appendChild(createMessageRow(key, ''));
		});
		config.items.querySelectorAll('[data-base3-chatbot-message-remove="' + key + '"]').forEach(function(remove) {
			remove.addEventListener('click', function() {
				var row = remove.closest('.base3-chatbot-config-message-row');
				if (row && row.parentNode) {
					row.parentNode.removeChild(row);
				}
				if (!config.items.querySelector('[name="' + key + '[]"]')) {
					config.items.appendChild(createMessageRow(key, ''));
				}
			});
		});
	});

	if (backendSelect) {
		backendSelect.addEventListener('change', updateBackend);
		updateBackend();
	}
	if (firstMessageMode) {
		firstMessageMode.addEventListener('change', updateStartModeFields);
	}
	if (referenceMode) {
		referenceMode.addEventListener('change', updateReferenceFields);
	}
	updateStartModeFields();
	updateReferenceFields();

	if (root.tagName && root.tagName.toLowerCase() === 'form') {
		root.addEventListener('submit', save);
	}
	else {
		button.addEventListener('click', save);
	}
})();
</script>
<?php } ?>
