<?php
	$values = is_array($this->_['values'] ?? null) ? $this->_['values'] : [];
	$messages = is_array($this->_['messages'] ?? null) ? $this->_['messages'] : [];

	$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$checked = static fn($value): string => !empty($value) ? ' checked="checked"' : '';
	$selected = static fn($current, $value): string => (string) $current === (string) $value ? ' selected="selected"' : '';

	$formId = (string) ($this->_['form_id'] ?? 'base3_chatbot_config');
	$group = (string) ($this->_['group'] ?? '');
	$name = (string) ($this->_['name'] ?? '');
?>

<style>
	.base3-chatbot-config-display {
		max-width: 1100px;
	}

	.base3-chatbot-config-display .base3-chatbot-config-section {
		margin: 0 0 24px;
		padding: 18px;
		border: 1px solid #ddd;
		border-radius: 4px;
		background: #fff;
	}

	.base3-chatbot-config-display .base3-chatbot-config-section h3 {
		margin: 0 0 14px;
		font-size: 18px;
	}

	.base3-chatbot-config-display .base3-chatbot-config-help {
		margin: 4px 0 0;
		color: #666;
		font-size: 12px;
	}

	.base3-chatbot-config-display textarea.base3-chatbot-config-system-prompt {
		min-height: 220px;
		font-family: monospace;
	}

	.base3-chatbot-config-display textarea.base3-chatbot-config-json {
		min-height: 140px;
		font-family: monospace;
	}

	.base3-chatbot-config-display .base3-chatbot-config-checkboxes label {
		display: block;
		margin: 6px 0;
		font-weight: normal;
	}

	.base3-chatbot-config-display .base3-chatbot-config-instance {
		color: #555;
		font-family: monospace;
	}
</style>

<div class="base3-chatbot-config-display">
	<h2><?php echo $e($this->_['title'] ?? 'Chatbot Configuration'); ?></h2>

<?php if (!empty($this->_['description'])) { ?>
	<p><?php echo $e($this->_['description']); ?></p>
<?php } ?>

<?php foreach ($messages as $message) {
	$type = preg_replace('/[^a-z]/', '', (string) ($message['type'] ?? 'info'));
	if ($type === '') {
		$type = 'info';
	}
?>
	<div class="alert alert-<?php echo $e($type); ?>">
		<?php echo $e($message['text'] ?? ''); ?>
	</div>
<?php } ?>

	<form id="<?php echo $e($formId); ?>" method="post" action="<?php echo $e($this->_['form_action'] ?? ''); ?>" class="form-horizontal">
		<input type="hidden" name="chatbot_config_action" value="save" />
		<input type="hidden" name="chatbot_config_group" value="<?php echo $e($group); ?>" />
		<input type="hidden" name="chatbot_config_name" value="<?php echo $e($name); ?>" />

		<div class="base3-chatbot-config-section">
			<h3>Instance</h3>

			<div class="form-group">
				<label class="col-sm-3 control-label">Settings group</label>
				<div class="col-sm-9">
					<p class="form-control-static base3-chatbot-config-instance"><?php echo $e($group); ?></p>
					<p class="base3-chatbot-config-help">
						The integration layer defines this group, e.g. UI hook, repository object or page component.
					</p>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-3 control-label">Instance name</label>
				<div class="col-sm-9">
					<p class="form-control-static base3-chatbot-config-instance"><?php echo $e($name); ?></p>
					<p class="base3-chatbot-config-help">
						This is the concrete chatbot instance id used as SettingsStore name.
					</p>
				</div>
			</div>
		</div>

		<div class="base3-chatbot-config-section">
			<h3>Service</h3>

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_service" class="col-sm-3 control-label">Service endpoint</label>
				<div class="col-sm-9">
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

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_default_lang" class="col-sm-3 control-label">Default language</label>
				<div class="col-sm-9">
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

			<div class="form-group">
				<label class="col-sm-3 control-label">Features</label>
				<div class="col-sm-9 base3-chatbot-config-checkboxes">
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

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_transport_mode" class="col-sm-3 control-label">Transport mode</label>
				<div class="col-sm-9">
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

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_reference_mode" class="col-sm-3 control-label">Reference mode</label>
				<div class="col-sm-9">
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

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_reference" class="col-sm-3 control-label">Static reference JSON</label>
				<div class="col-sm-9">
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

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_reference_provider" class="col-sm-3 control-label">Reference provider</label>
				<div class="col-sm-9">
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

			<div class="form-group">
				<label for="<?php echo $e($formId); ?>_system_prompt" class="col-sm-3 control-label">System prompt</label>
				<div class="col-sm-9">
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

		<div class="form-group">
			<div class="col-sm-offset-3 col-sm-9">
				<button type="submit" class="btn btn-primary">
					<?php echo $e($this->_['submit_label'] ?? 'Save'); ?>
				</button>
			</div>
		</div>
	</form>
</div>
