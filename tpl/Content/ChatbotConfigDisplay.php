<?php
	$values = is_array($this->_['values'] ?? null) ? $this->_['values'] : [];
	$messages = is_array($this->_['messages'] ?? null) ? $this->_['messages'] : [];

	$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$checked = static fn($value): string => !empty($value) ? ' checked="checked"' : '';
	$selected = static fn($current, $value): string => (string) $current === (string) $value ? ' selected="selected"' : '';

	$formId = (string) ($this->_['form_id'] ?? 'base3_chatbot_config');
	$group = (string) ($this->_['group'] ?? '');
	$name = (string) ($this->_['name'] ?? '');
	$renderForm = !empty($this->_['render_form']);
	$saveMode = (string) ($this->_['save_mode'] ?? 'post');
	$saveUrl = (string) ($this->_['save_url'] ?? '');
	$useAjax = $saveMode === 'ajax';
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

	.base3-chatbot-config-section {
		margin: 0 0 20px;
		padding: 16px;
		border: 1px solid #ddd;
		border-radius: 4px;
		background: #fff;
	}

	.base3-chatbot-config-section h3 {
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
		font-weight: bold;
	}

	.base3-chatbot-config-display input[type="text"],
	.base3-chatbot-config-display select,
	.base3-chatbot-config-display textarea {
		width: 100%;
		max-width: 620px;
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

	.base3-chatbot-config-system-prompt {
		min-height: 220px;
	}

	.base3-chatbot-config-json {
		min-height: 140px;
	}

	.base3-chatbot-config-help {
		max-width: 760px;
		margin: 5px 0 0;
		color: #666;
		font-size: 12px;
	}

	.base3-chatbot-config-checkboxes label {
		display: block;
		margin: 0 0 7px;
		font-weight: normal;
	}

	.base3-chatbot-config-checkboxes input {
		margin-right: 6px;
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
		.base3-chatbot-config-section {
			padding: 12px;
		}

		.base3-chatbot-config-row {
			display: block;
		}

		.base3-chatbot-config-label {
			display: block;
			padding-top: 0;
			margin: 0 0 5px;
		}

		.base3-chatbot-config-display input[type="text"],
		.base3-chatbot-config-display select,
		.base3-chatbot-config-display textarea {
			max-width: none;
		}
	}
</style>

<div class="base3-chatbot-config-display">
<?php if ($renderForm) { ?>
	<form id="<?php echo $e($formId); ?>" method="post" action="<?php echo $e($this->_['form_action'] ?? ''); ?>">
<?php } else { ?>
	<div
		id="<?php echo $e($formId); ?>"
		class="base3-chatbot-config-fields"
		data-base3-chatbot-config-root="1"
		data-save-url="<?php echo $e($saveUrl); ?>"
	>
<?php } ?>

		<h2><?php echo $e($this->_['title'] ?? 'Chatbot Configuration'); ?></h2>

<?php if (!empty($this->_['description'])) { ?>
		<p class="base3-chatbot-config-description"><?php echo $e($this->_['description']); ?></p>
<?php } ?>

		<div class="base3-chatbot-config-instance">
			Instance:
			<code><?php echo $e($group); ?></code>
			/
			<code><?php echo $e($name); ?></code>
		</div>

		<div class="base3-chatbot-config-messages" data-base3-chatbot-config-messages>
<?php foreach ($messages as $message) {
	$type = preg_replace('/[^a-z]/', '', (string) ($message['type'] ?? 'info'));
	if ($type === '') {
		$type = 'info';
	}
?>
			<div class="base3-chatbot-config-message base3-chatbot-config-message-<?php echo $e($type); ?> alert alert-<?php echo $e($type); ?>">
				<?php echo $e($message['text'] ?? ''); ?>
			</div>
<?php } ?>
		</div>

		<input type="hidden" name="chatbot_config_action" value="save" />
		<input type="hidden" name="chatbot_config_group" value="<?php echo $e($group); ?>" />
		<input type="hidden" name="chatbot_config_name" value="<?php echo $e($name); ?>" />

		<div class="base3-chatbot-config-section">
			<h3>Service</h3>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_service" class="base3-chatbot-config-label">Service endpoint</label>
				<div>
					<input
						type="text"
						id="<?php echo $e($formId); ?>_service"
						name="service"
						class="form-control"
						value="<?php echo $e($values['service'] ?? ''); ?>"
					/>
					<p class="base3-chatbot-config-help">
						Client-side endpoint used by the chatbot widget. In later steps, the display will append the configuration group and name to this URL.
					</p>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_default_lang" class="base3-chatbot-config-label">Default language</label>
				<div>
					<input
						type="text"
						id="<?php echo $e($formId); ?>_default_lang"
						name="default_lang"
						class="form-control"
						value="<?php echo $e($values['default_lang'] ?? 'auto'); ?>"
					/>
					<p class="base3-chatbot-config-help">
						Used by voice-related client features. Use "auto" unless the integration should force a language.
					</p>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-section">
			<h3>Chatbot UI</h3>

			<div class="base3-chatbot-config-row">
				<div class="base3-chatbot-config-label">Features</div>
				<div class="base3-chatbot-config-checkboxes">
					<label>
						<input type="checkbox" name="use_markdown" value="1"<?php echo $checked($values['use_markdown'] ?? false); ?> />
						Enable markdown rendering
					</label>

					<label>
						<input type="checkbox" name="use_icons" value="1"<?php echo $checked($values['use_icons'] ?? false); ?> />
						Show dialog action icons
					</label>

					<label>
						<input type="checkbox" name="use_voice" value="1"<?php echo $checked($values['use_voice'] ?? false); ?> />
						Enable voice controls
					</label>

					<label>
						<input type="checkbox" name="use_threads" value="1"<?php echo $checked($values['use_threads'] ?? false); ?> />
						Enable chat threads
					</label>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_transport_mode" class="base3-chatbot-config-label">Transport mode</label>
				<div>
					<select id="<?php echo $e($formId); ?>_transport_mode" name="transport_mode" class="form-control">
						<option value="auto"<?php echo $selected($values['transport_mode'] ?? 'auto', 'auto'); ?>>auto</option>
						<option value="sse"<?php echo $selected($values['transport_mode'] ?? 'auto', 'sse'); ?>>sse</option>
						<option value="websocket"<?php echo $selected($values['transport_mode'] ?? 'auto', 'websocket'); ?>>websocket</option>
						<option value="rest"<?php echo $selected($values['transport_mode'] ?? 'auto', 'rest'); ?>>rest</option>
					</select>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-section">
			<h3>Reference context</h3>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_reference_mode" class="base3-chatbot-config-label">Reference mode</label>
				<div>
					<select id="<?php echo $e($formId); ?>_reference_mode" name="reference_mode" class="form-control">
						<option value="none"<?php echo $selected($values['reference_mode'] ?? 'url', 'none'); ?>>none</option>
						<option value="url"<?php echo $selected($values['reference_mode'] ?? 'url', 'url'); ?>>url</option>
						<option value="custom"<?php echo $selected($values['reference_mode'] ?? 'url', 'custom'); ?>>custom</option>
						<option value="provider"<?php echo $selected($values['reference_mode'] ?? 'url', 'provider'); ?>>provider</option>
					</select>
					<p class="base3-chatbot-config-help">
						Controls which contextual reference is sent with requests. The service can store this in the agent context.
					</p>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_reference" class="base3-chatbot-config-label">Static reference JSON</label>
				<div>
					<textarea
						id="<?php echo $e($formId); ?>_reference"
						name="reference"
						class="form-control base3-chatbot-config-json"
					><?php echo $e($values['reference_json'] ?? '{}'); ?></textarea>
					<p class="base3-chatbot-config-help">
						Only used for custom reference mode. Must be valid JSON.
					</p>
				</div>
			</div>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_reference_provider" class="base3-chatbot-config-label">Reference provider</label>
				<div>
					<input
						type="text"
						id="<?php echo $e($formId); ?>_reference_provider"
						name="reference_provider"
						class="form-control"
						value="<?php echo $e($values['reference_provider'] ?? ''); ?>"
					/>
					<p class="base3-chatbot-config-help">
						Global JavaScript function name used by provider reference mode.
					</p>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-section">
			<h3>Service prompt</h3>

			<div class="base3-chatbot-config-row">
				<label for="<?php echo $e($formId); ?>_system_prompt" class="base3-chatbot-config-label">System prompt</label>
				<div>
					<textarea
						id="<?php echo $e($formId); ?>_system_prompt"
						name="system_prompt"
						class="form-control base3-chatbot-config-system-prompt"
					><?php echo $e($values['system_prompt'] ?? ''); ?></textarea>
					<p class="base3-chatbot-config-help">
						Server-side prompt for the chatbot service. This value should not be rendered into the client-side chatbot configuration.
					</p>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-row base3-chatbot-config-actions">
			<div></div>
			<div>
				<button
					type="<?php echo $useAjax ? 'button' : 'submit'; ?>"
					class="btn btn-primary base3-chatbot-config-submit"
					<?php echo $useAjax ? 'data-base3-chatbot-config-save="1"' : ''; ?>
				>
					<?php echo $e($this->_['submit_label'] ?? 'Save'); ?>
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

	if (!root) {
		return;
	}

	var button = root.querySelector('[data-base3-chatbot-config-save]');
	var messages = root.querySelector('[data-base3-chatbot-config-messages]');
	var saveUrl = root.getAttribute('data-save-url') || <?php echo json_encode($saveUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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

	function collectFormData() {
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

		if (!formData.has('chatbot_config_action')) {
			formData.append('chatbot_config_action', 'save');
		}

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
			service: 'service',
			default_lang: 'default_lang',
			transport_mode: 'transport_mode',
			reference_mode: 'reference_mode',
			reference_json: 'reference',
			reference_provider: 'reference_provider',
			system_prompt: 'system_prompt'
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

		['use_markdown', 'use_icons', 'use_voice', 'use_threads'].forEach(function(key) {
			var field = root.querySelector('[name="' + key + '"]');

			if (field) {
				field.checked = !!values[key];
			}
		});
	}

	button.addEventListener('click', function() {
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
						text: 'Settings could not be saved: ' + error.message
					}
				]);
			})
			.finally(function() {
				button.disabled = false;
			});
	});
})();
</script>
<?php } ?>
