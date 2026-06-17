<?php
        $values = is_array($this->_['values'] ?? null) ? $this->_['values'] : [];
        $messages = is_array($this->_['messages'] ?? null) ? $this->_['messages'] : [];
        $serviceOptions = is_array($this->_['service_options'] ?? null) ? $this->_['service_options'] : [];
        $llmOptions = is_array($this->_['llm_options'] ?? null) ? $this->_['llm_options'] : [];
        $agentComponentPresets = is_array($this->_['agent_component_presets'] ?? null) ? $this->_['agent_component_presets'] : [];
        $agentComponents = is_array($values['agent_components'] ?? null) ? $values['agent_components'] : [];
        $basePrompts = is_array($values['base_prompts'] ?? null) ? $values['base_prompts'] : [];

        if ($basePrompts === []) {
                $basePrompts = [''];
        }

        $languageOptions = [
                'auto' => 'auto',
                'de-DE' => 'German (Germany) - de-DE',
                'de-AT' => 'German (Austria) - de-AT',
                'de-CH' => 'German (Switzerland) - de-CH',
                'en-US' => 'English (United States) - en-US',
                'en-GB' => 'English (United Kingdom) - en-GB',
                'fr-FR' => 'French (France) - fr-FR',
                'es-ES' => 'Spanish (Spain) - es-ES',
                'it-IT' => 'Italian (Italy) - it-IT',
                'nl-NL' => 'Dutch (Netherlands) - nl-NL',
                'pl-PL' => 'Polish (Poland) - pl-PL',
                'pt-PT' => 'Portuguese (Portugal) - pt-PT',
                'pt-BR' => 'Portuguese (Brazil) - pt-BR',
                'tr-TR' => 'Turkish (Turkey) - tr-TR'
        ];

        $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $checked = static fn($value): string => !empty($value) ? ' checked="checked"' : '';
        $selected = static fn($current, $value): string => (string) $current === (string) $value ? ' selected="selected"' : '';
        $fixedConfigValue = static function(array $config, string $key, $default = '') {
                $value = $config[$key] ?? null;

                if (!is_array($value) || (string) ($value['mode'] ?? '') !== 'fixed') {
                        return $default;
                }

                return $value['value'] ?? $default;
        };
        $componentHasAttach = static fn(array $component, string $type): bool => in_array($type, is_array($component['attach_as'] ?? null) ? $component['attach_as'] : [], true);
        $presetCapabilities = [];
        foreach ($agentComponentPresets as $presetOption) {
                $presetOptionId = (string) ($presetOption['id'] ?? '');

                if ($presetOptionId === '') {
                        continue;
                }

                $presetOptionCapabilities = is_array($presetOption['capabilities'] ?? null) ? $presetOption['capabilities'] : [];
                $presetCapabilities[$presetOptionId] = array_values(array_filter(array_map('strval', $presetOptionCapabilities)));
        }
        $capabilityText = static function(array $capabilities): string {
                $capabilities = array_values(array_filter(array_map('strval', $capabilities)));

                return $capabilities === [] ? '-' : implode(', ', $capabilities);
        };
        $componentCapabilityText = static function(array $component) use ($presetCapabilities, $capabilityText): string {
                $presetId = (string) ($component['preset'] ?? '');

                if ($presetId !== '' && isset($presetCapabilities[$presetId])) {
                        return $capabilityText($presetCapabilities[$presetId]);
                }

                return $capabilityText(is_array($component['attach_as'] ?? null) ? $component['attach_as'] : []);
        };

        $formId = (string) ($this->_['form_id'] ?? 'base3_chatbot_config');
        $group = (string) ($this->_['group'] ?? '');
        $name = (string) ($this->_['name'] ?? '');
        $renderForm = !empty($this->_['render_form']);
        $saveMode = (string) ($this->_['save_mode'] ?? 'ajax');
        $saveUrl = (string) ($this->_['save_url'] ?? '');
        $useAjax = $saveMode === 'ajax';
        $currentLang = trim((string) ($values['default_lang'] ?? 'auto'));
        $currentService = trim((string) ($values['service'] ?? ''));
        $currentServiceUrl = '';
        $currentServiceDescription = '';
        $serviceOptionIds = [];

        if ($currentLang === '') {
                $currentLang = 'auto';
        }

        foreach ($serviceOptions as $serviceOption) {
                $serviceId = (string) ($serviceOption['id'] ?? '');

                if ($serviceId === '') {
                        continue;
                }

                $serviceOptionIds[$serviceId] = true;

                if ($serviceId === $currentService) {
                        $currentServiceUrl = (string) ($serviceOption['url'] ?? '');
                        $currentServiceDescription = (string) ($serviceOption['description'] ?? '');
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
                min-height: 320px;
        }

        .base3-chatbot-config-json {
                min-height: 140px;
        }

        .base3-chatbot-config-agent-flow {
                min-height: 420px;
        }

        .base3-chatbot-config-collapsible {
                max-width: 760px;
                margin: 14px 0 0;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: #fafafa;
        }

        .base3-chatbot-config-collapsible summary {
                padding: 9px 12px;
                cursor: pointer;
                font-weight: bold;
        }

        .base3-chatbot-config-collapsible-content {
                padding: 0 12px 12px;
        }

        .base3-chatbot-config-help {
                max-width: 760px;
                margin: 5px 0 0;
                color: #666;
                font-size: 12px;
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

        .base3-chatbot-config-base-prompts {
                max-width: 760px;
        }

        .base3-chatbot-config-base-prompt-row {
                display: flex;
                gap: 7px;
                align-items: center;
                margin: 0 0 7px;
        }

        .base3-chatbot-config-base-prompt-row input[type="text"] {
                max-width: none;
                flex: 1 1 auto;
        }

        .base3-chatbot-config-base-prompt-remove,
        .base3-chatbot-config-base-prompt-add {
                min-height: 34px;
                padding: 6px 10px;
                cursor: pointer;
                white-space: nowrap;
        }

        .base3-chatbot-config-base-prompt-add {
                margin-top: 1px;
        }


        .base3-chatbot-config-agent-components {
                max-width: 900px;
        }

        .base3-chatbot-config-agent-component-row {
                display: grid;
                grid-template-columns: minmax(170px, 1.5fr) 74px minmax(100px, 0.8fr) minmax(90px, 0.8fr) minmax(120px, 1fr) minmax(140px, 1.2fr) minmax(180px, 1.8fr) auto;
                gap: 7px;
                align-items: start;
                margin: 0 0 8px;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: #fafafa;
        }

        .base3-chatbot-config-agent-component-row label {
                display: block;
                margin: 0 0 4px;
                color: #666;
                font-size: 11px;
                font-weight: normal;
        }

        .base3-chatbot-config-agent-component-row input[type="text"],
        .base3-chatbot-config-agent-component-row select {
                max-width: none;
        }

        .base3-chatbot-config-agent-component-capabilities {
                min-height: 34px;
                padding: 7px 0 0;
                color: #333;
                font-size: 12px;
                line-height: 1.35;
        }

        .base3-chatbot-config-agent-component-muted {
                opacity: 0.55;
        }

        .base3-chatbot-config-agent-component-check {
                padding-top: 24px;
                text-align: center;
        }

        .base3-chatbot-config-agent-component-remove,
        .base3-chatbot-config-agent-component-add {
                min-height: 34px;
                padding: 6px 10px;
                cursor: pointer;
                white-space: nowrap;
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

                .base3-chatbot-config-base-prompt-row {
                        display: block;
                }


                .base3-chatbot-config-agent-component-row {
                        display: block;
                }

                .base3-chatbot-config-agent-component-row > div {
                        margin: 0 0 7px;
                }

                .base3-chatbot-config-agent-component-check {
                        padding-top: 0;
                        text-align: left;
                }

                .base3-chatbot-config-base-prompt-remove {
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

                <input type="hidden" name="chatbot_config_action" value="save" />
                <input type="hidden" name="chatbot_config_group" value="<?php echo $e($group); ?>" />
                <input type="hidden" name="chatbot_config_name" value="<?php echo $e($name); ?>" />

                <div class="base3-chatbot-config-section">
                        <h3>Chatbot UI</h3>

                        <div class="base3-chatbot-config-row">
                                <div class="base3-chatbot-config-label">Base prompts</div>
                                <div>
                                        <div class="base3-chatbot-config-base-prompts" data-base3-chatbot-base-prompts>
                                                <div data-base3-chatbot-base-prompts-items>
<?php foreach ($basePrompts as $basePrompt) { ?>
                                                        <div class="base3-chatbot-config-base-prompt-row">
                                                                <input
                                                                        type="text"
                                                                        name="base_prompts[]"
                                                                        class="form-control"
                                                                        value="<?php echo $e($basePrompt); ?>"
                                                                        placeholder="Initial greeting prompt"
                                                                />
                                                                <button
                                                                        type="button"
                                                                        class="btn btn-default base3-chatbot-config-base-prompt-remove"
                                                                        data-base3-chatbot-base-prompt-remove="1"
                                                                >
                                                                        Remove
                                                                </button>
                                                        </div>
<?php } ?>
                                                </div>

                                                <button
                                                        type="button"
                                                        class="btn btn-default base3-chatbot-config-base-prompt-add"
                                                        data-base3-chatbot-base-prompt-add="1"
                                                >
                                                        Add base prompt
                                                </button>

                                                <p class="base3-chatbot-config-help">
                                                        Initial greeting prompts shown before the user starts chatting. Empty fields are ignored when saving.
                                                </p>
                                        </div>
                                </div>
                        </div>

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

                        <div class="base3-chatbot-config-row">
                                <label for="<?php echo $e($formId); ?>_default_lang" class="base3-chatbot-config-label">Voice language</label>
                                <div>
                                        <select id="<?php echo $e($formId); ?>_default_lang" name="default_lang" class="form-control">
<?php foreach ($languageOptions as $languageValue => $languageLabel) { ?>
                                                <option value="<?php echo $e($languageValue); ?>"<?php echo $selected($currentLang, $languageValue); ?>><?php echo $e($languageLabel); ?></option>
<?php } ?>
<?php if (!array_key_exists($currentLang, $languageOptions)) { ?>
                                                <option value="<?php echo $e($currentLang); ?>" selected="selected">Current custom value: <?php echo $e($currentLang); ?></option>
<?php } ?>
                                        </select>
                                        <p class="base3-chatbot-config-help">
                                                Language hint for browser text-to-speech output. Use <code>auto</code> unless the integration should force a specific speech language.
                                        </p>
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
                        <h3>Chatbot service</h3>

                        <div class="base3-chatbot-config-row">
                                <label for="<?php echo $e($formId); ?>_service" class="base3-chatbot-config-label">Chatbot service</label>
                                <div>
                                        <select
                                                id="<?php echo $e($formId); ?>_service"
                                                name="service"
                                                class="form-control"
                                                data-base3-chatbot-service-select="1"
                                        >
<?php if ($serviceOptions === []) { ?>
                                                <option value="">No chatbot services found</option>
<?php } else { ?>
                                                <option value="">Select chatbot service</option>
<?php if ($currentService !== '' && !isset($serviceOptionIds[$currentService])) { ?>
                                                <option value="<?php echo $e($currentService); ?>" selected="selected" disabled="disabled">Unknown service: <?php echo $e($currentService); ?></option>
<?php } ?>
<?php foreach ($serviceOptions as $serviceOption) {
        $serviceId = (string) ($serviceOption['id'] ?? '');

        if ($serviceId === '') {
                continue;
        }

        $label = trim((string) ($serviceOption['label'] ?? ''));

        if ($label === '') {
                $label = $serviceId;
        }

        $description = (string) ($serviceOption['description'] ?? '');
        $url = (string) ($serviceOption['url'] ?? '');
?>
                                                <option
                                                        value="<?php echo $e($serviceId); ?>"
                                                        data-description="<?php echo $e($description); ?>"
                                                        data-url="<?php echo $e($url); ?>"
                                                        <?php echo $selected($currentService, $serviceId); ?>
                                                >
                                                        <?php echo $e($label); ?> (<?php echo $e($serviceId); ?>)
                                                </option>
<?php } ?>
<?php } ?>
                                        </select>

                                        <p class="base3-chatbot-config-help" data-base3-chatbot-service-description>
                                                <?php echo $e($currentServiceDescription !== '' ? $currentServiceDescription : 'Select the service implementation used by this chatbot instance.'); ?>
                                        </p>

                                        <p class="base3-chatbot-config-help">
                                                Generated endpoint:
                                                <code class="base3-chatbot-config-service-url" data-base3-chatbot-service-url><?php echo $e($currentServiceUrl !== '' ? $currentServiceUrl : 'No endpoint generated.'); ?></code>
                                        </p>

                                        <p class="base3-chatbot-config-help">
                                                The SettingsStore value is only the technical service name. The concrete endpoint URL is generated by the host system.
                                        </p>
                                </div>
                        </div>
                </div>

                <div class="base3-chatbot-config-section">
                        <h3>LLM</h3>

                        <div class="base3-chatbot-config-row">
                                <label for="<?php echo $e($formId); ?>_llm" class="base3-chatbot-config-label">LLM</label>
                                <div>
                                        <select id="<?php echo $e($formId); ?>_llm" name="llm" class="form-control">
                                                <option value="">Use AgentFlow JSON value</option>
<?php foreach ($llmOptions as $llm) {
        $llmId = (string) ($llm['id'] ?? '');
        if ($llmId === '') {
                continue;
        }

        $parts = [];
        $label = trim((string) ($llm['label'] ?? ''));

        if ($label === '') {
                $label = $llmId;
        }

        $parts[] = $label;

        if (!empty($llm['model'])) {
                $parts[] = (string) $llm['model'];
        }

        if (!empty($llm['driver'])) {
                $parts[] = (string) $llm['driver'];
        }

        $text = implode(' / ', $parts);

        if (empty($llm['enabled'])) {
                $text .= ' [disabled]';
        }
?>
                                                <option value="<?php echo $e($llmId); ?>"<?php echo $selected($values['llm'] ?? '', $llmId); ?>><?php echo $e($text); ?></option>
<?php } ?>
                                        </select>
                                        <p class="base3-chatbot-config-help">
                                                If an LLM is selected here, the AgentFlow resource <code>chatllm</code> is updated to use this configured LLM. Leave empty to keep the raw AgentFlow value unchanged.
                                        </p>
                                </div>
                        </div>
                </div>

                <div class="base3-chatbot-config-section">
                        <h3>Tools &amp; Memory</h3>

                        <div class="base3-chatbot-config-row">
                                <div class="base3-chatbot-config-label">Components</div>
                                <div>
                                        <div class="base3-chatbot-config-agent-components" data-base3-chatbot-agent-components>
                                                <div data-base3-chatbot-agent-component-items>
<?php foreach ($agentComponents as $componentIndex => $component) {
        if (!is_array($component)) {
                continue;
        }

        $presetId = (string) ($component['preset'] ?? '');
        $memoryConfig = is_array($component['memory_config'] ?? null) ? $component['memory_config'] : [];
        $toolConfig = is_array($component['tool_config'] ?? null) ? $component['tool_config'] : [];
        $order = (string) ($component['order'] ?? $fixedConfigValue($memoryConfig, 'priority', ''));
        $namespace = (string) $fixedConfigValue($toolConfig, 'namespace', '');
        $label = (string) $fixedConfigValue($toolConfig, 'label', '');
        $description = (string) $fixedConfigValue($toolConfig, 'description', '');
?>
                                                        <div class="base3-chatbot-config-agent-component-row" data-base3-chatbot-agent-component-row="1">
                                                                <div>
                                                                        <label>Preset</label>
                                                                        <select name="agent_components[<?php echo $e($componentIndex); ?>][preset]" class="form-control">
                                                                                <option value="">Select preset</option>
<?php foreach ($agentComponentPresets as $preset) {
        $optionId = (string) ($preset['id'] ?? '');
        if ($optionId === '') {
                continue;
        }

        $optionLabel = trim((string) ($preset['label'] ?? ''));
        if ($optionLabel === '') {
                $optionLabel = $optionId;
        }
?>
                                                                                <option value="<?php echo $e($optionId); ?>"<?php echo $selected($presetId, $optionId); ?>><?php echo $e($optionLabel); ?> (<?php echo $e($optionId); ?>)</option>
<?php } ?>
                                                                        </select>
                                                                </div>
                                                                <div class="base3-chatbot-config-agent-component-check">
                                                                        <input type="hidden" name="agent_components[<?php echo $e($componentIndex); ?>][enabled]" value="0" />
                                                                        <label>
                                                                                <input type="checkbox" name="agent_components[<?php echo $e($componentIndex); ?>][enabled]" value="1"<?php echo $checked($component['enabled'] ?? true); ?> />
                                                                                Active
                                                                        </label>
                                                                </div>
                                                                <div>
                                                                        <label>Use as</label>
                                                                        <div class="base3-chatbot-config-agent-component-capabilities" data-base3-chatbot-agent-component-capabilities="1"><?php echo $e($componentCapabilityText($component)); ?></div>
                                                                </div>
                                                                <div data-base3-chatbot-agent-component-memory-fields="1">
                                                                        <label>Order</label>
                                                                        <input type="text" name="agent_components[<?php echo $e($componentIndex); ?>][order]" class="form-control" value="<?php echo $e($order); ?>" placeholder="10" />
                                                                </div>
                                                                <div data-base3-chatbot-agent-component-tool-fields="1">
                                                                        <label>Namespace</label>
                                                                        <input type="text" name="agent_components[<?php echo $e($componentIndex); ?>][namespace]" class="form-control" value="<?php echo $e($namespace); ?>" placeholder="web" />
                                                                </div>
                                                                <div data-base3-chatbot-agent-component-tool-fields="1">
                                                                        <label>Label</label>
                                                                        <input type="text" name="agent_components[<?php echo $e($componentIndex); ?>][label]" class="form-control" value="<?php echo $e($label); ?>" placeholder="Visible tool label" />
                                                                </div>
                                                                <div data-base3-chatbot-agent-component-tool-fields="1">
                                                                        <label>Description</label>
                                                                        <input type="text" name="agent_components[<?php echo $e($componentIndex); ?>][description]" class="form-control" value="<?php echo $e($description); ?>" placeholder="Visible tool description" />
                                                                </div>
                                                                <div>
                                                                        <label>&nbsp;</label>
                                                                        <button type="button" class="btn btn-default base3-chatbot-config-agent-component-remove" data-base3-chatbot-agent-component-remove="1">Remove</button>
                                                                </div>
                                                        </div>
<?php } ?>
                                                </div>

                                                <button
                                                        type="button"
                                                        class="btn btn-default base3-chatbot-config-agent-component-add"
                                                        data-base3-chatbot-agent-component-add="1"
                                                >
                                                        Add component
                                                </button>

                                                <p class="base3-chatbot-config-help">
                                                        Components are stored as <code>agent_components</code>. Memory/tool exposure is derived from the selected preset resource implementation; the runtime builds the configured wrappers during flow construction.
                                                </p>
<?php if ($agentComponentPresets === []) { ?>
                                                <p class="base3-chatbot-config-help">
                                                        No presets found in SettingsStore group <code>agent-component-preset</code>.
                                                </p>
<?php } ?>
                                        </div>
                                </div>
                        </div>

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

                        <div class="base3-chatbot-config-row">
                                <div class="base3-chatbot-config-label">AgentFlow</div>
                                <div>
                                        <details class="base3-chatbot-config-collapsible">
                                                <summary>AgentFlow configuration JSON</summary>
                                                <div class="base3-chatbot-config-collapsible-content">
                                                        <textarea
                                                                id="<?php echo $e($formId); ?>_agent_flow"
                                                                name="agent_flow"
                                                                class="form-control base3-chatbot-config-agent-flow"
                                                        ><?php echo $e($values['agent_flow_json'] ?? '{}'); ?></textarea>
                                                        <p class="base3-chatbot-config-help">
                                                                Temporary raw JSON configuration for the service-side AgentFlow. Selecting an LLM above updates only the <code>chatllm</code> resource during save.
                                                        </p>
                                                </div>
                                        </details>
                                </div>
                        </div>
                </div>
                <div class="base3-chatbot-config-row base3-chatbot-config-actions">
                        <div></div>
                        <div>
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

                                <button
                                        type="<?php echo $renderForm ? 'submit' : 'button'; ?>"
                                        class="btn btn-primary base3-chatbot-config-submit"
                                        data-base3-chatbot-config-save="1"
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

        if (!root || root.getAttribute('data-base3-chatbot-config-ready') === '1') {
                return;
        }

        root.setAttribute('data-base3-chatbot-config-ready', '1');

        var button = root.querySelector('[data-base3-chatbot-config-save]');
        var messages = root.querySelector('[data-base3-chatbot-config-messages]');
        var saveUrl = root.getAttribute('data-save-url') || <?php echo json_encode($saveUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var basePromptsRoot = root.querySelector('[data-base3-chatbot-base-prompts]');
        var basePromptsItems = root.querySelector('[data-base3-chatbot-base-prompts-items]');
        var basePromptsAdd = root.querySelector('[data-base3-chatbot-base-prompt-add]');
        var serviceSelect = root.querySelector('[data-base3-chatbot-service-select]');
        var serviceDescription = root.querySelector('[data-base3-chatbot-service-description]');
        var serviceUrl = root.querySelector('[data-base3-chatbot-service-url]');
        var agentComponentsRoot = root.querySelector('[data-base3-chatbot-agent-components]');
        var agentComponentsItems = root.querySelector('[data-base3-chatbot-agent-component-items]');
        var agentComponentsAdd = root.querySelector('[data-base3-chatbot-agent-component-add]');
        var agentComponentPresets = <?php echo json_encode(array_values($agentComponentPresets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var nextAgentComponentIndex = <?php echo json_encode(count($agentComponents), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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

        function updateServicePreview() {
                if (!serviceSelect) {
                        return;
                }

                var option = serviceSelect.options[serviceSelect.selectedIndex];
                var description = option ? option.getAttribute('data-description') || '' : '';
                var url = option ? option.getAttribute('data-url') || '' : '';

                if (serviceDescription) {
                        serviceDescription.textContent = description || 'Select the service implementation used by this chatbot instance.';
                }

                if (serviceUrl) {
                        serviceUrl.textContent = url || 'No endpoint generated.';
                }
        }

        function createBasePromptRow(value) {
                var row = document.createElement('div');
                var input = document.createElement('input');
                var remove = document.createElement('button');

                row.className = 'base3-chatbot-config-base-prompt-row';

                input.type = 'text';
                input.name = 'base_prompts[]';
                input.className = 'form-control';
                input.value = value || '';
                input.placeholder = 'Initial greeting prompt';

                remove.type = 'button';
                remove.className = 'btn btn-default base3-chatbot-config-base-prompt-remove';
                remove.setAttribute('data-base3-chatbot-base-prompt-remove', '1');
                remove.appendChild(document.createTextNode('Remove'));
                remove.addEventListener('click', function() {
                        if (row.parentNode) {
                                row.parentNode.removeChild(row);
                        }

                        if (basePromptsItems && !basePromptsItems.querySelector('[name="base_prompts[]"]')) {
                                basePromptsItems.appendChild(createBasePromptRow(''));
                        }
                });

                row.appendChild(input);
                row.appendChild(remove);

                return row;
        }

        function renderBasePrompts(items) {
                if (!basePromptsItems) {
                        return;
                }

                if (!Array.isArray(items) || items.length === 0) {
                        items = [''];
                }

                basePromptsItems.innerHTML = '';

                items.forEach(function(item) {
                        basePromptsItems.appendChild(createBasePromptRow(item));
                });
        }


        function getFixedConfigValue(config, key, defaultValue) {
                var value = config && typeof config === 'object' ? config[key] : null;

                if (!value || typeof value !== 'object' || value.mode !== 'fixed') {
                        return defaultValue;
                }

                return Object.prototype.hasOwnProperty.call(value, 'value') ? value.value : defaultValue;
        }

        function componentHasAttach(component, type) {
                return Array.isArray(component.attach_as) && component.attach_as.indexOf(type) !== -1;
        }

        function getAgentComponentPresetById(id) {
                id = String(id || '');

                for (var i = 0; i < agentComponentPresets.length; i++) {
                        if (String(agentComponentPresets[i].id || '') === id) {
                                return agentComponentPresets[i];
                        }
                }

                return null;
        }

        function getAgentComponentCapabilities(id, fallbackComponent) {
                var preset = getAgentComponentPresetById(id);

                if (preset && Array.isArray(preset.capabilities)) {
                        return preset.capabilities.map(String).filter(Boolean);
                }

                if (fallbackComponent && Array.isArray(fallbackComponent.attach_as)) {
                        return fallbackComponent.attach_as.map(String).filter(Boolean);
                }

                return [];
        }

        function formatAgentComponentCapabilities(capabilities) {
                capabilities = Array.isArray(capabilities) ? capabilities.map(String).filter(Boolean) : [];

                return capabilities.length ? capabilities.join(', ') : '-';
        }

        function updateAgentComponentRowState(row) {
                if (!row) {
                        return;
                }

                var select = row.querySelector('select[name$="[preset]"]');
                var capabilitiesNode = row.querySelector('[data-base3-chatbot-agent-component-capabilities]');
                var capabilities = getAgentComponentCapabilities(select ? select.value : '', null);
                var hasMemory = capabilities.indexOf('memory') !== -1;
                var hasTool = capabilities.indexOf('tool') !== -1;

                if (capabilitiesNode) {
                        capabilitiesNode.textContent = formatAgentComponentCapabilities(capabilities);
                }

                row.querySelectorAll('[data-base3-chatbot-agent-component-memory-fields]').forEach(function(cell) {
                        cell.classList.toggle('base3-chatbot-config-agent-component-muted', !hasMemory);
                });

                row.querySelectorAll('[data-base3-chatbot-agent-component-tool-fields]').forEach(function(cell) {
                        cell.classList.toggle('base3-chatbot-config-agent-component-muted', !hasTool);
                });
        }

        function createAgentComponentRow(component) {
                component = component && typeof component === 'object' ? component : {};

                var index = nextAgentComponentIndex++;
                var row = document.createElement('div');
                var memoryConfig = component.memory_config && typeof component.memory_config === 'object' ? component.memory_config : {};
                var toolConfig = component.tool_config && typeof component.tool_config === 'object' ? component.tool_config : {};
                var order = Object.prototype.hasOwnProperty.call(component, 'order') ? component.order : getFixedConfigValue(memoryConfig, 'priority', '');
                var namespace = getFixedConfigValue(toolConfig, 'namespace', '');
                var label = getFixedConfigValue(toolConfig, 'label', '');
                var description = getFixedConfigValue(toolConfig, 'description', '');
                var enabled = !Object.prototype.hasOwnProperty.call(component, 'enabled') || !!component.enabled;

                row.className = 'base3-chatbot-config-agent-component-row';
                row.setAttribute('data-base3-chatbot-agent-component-row', '1');

                function fieldName(name) {
                        return 'agent_components[' + index + '][' + name + ']';
                }

                function makeCell(labelText) {
                        var cell = document.createElement('div');
                        var labelNode = document.createElement('label');
                        labelNode.appendChild(document.createTextNode(labelText));
                        cell.appendChild(labelNode);
                        return cell;
                }

                function makeInput(name, value, placeholder) {
                        var input = document.createElement('input');
                        input.type = 'text';
                        input.name = fieldName(name);
                        input.className = 'form-control';
                        input.value = value || '';
                        input.placeholder = placeholder || '';
                        return input;
                }

                function makeHidden(name, value) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = fieldName(name);
                        hidden.value = value;
                        return hidden;
                }

                function makeCheckbox(name, checkedValue, labelText, isChecked) {
                        var cell = document.createElement('div');
                        var labelNode = document.createElement('label');
                        var checkbox = document.createElement('input');

                        cell.className = 'base3-chatbot-config-agent-component-check';
                        checkbox.type = 'checkbox';
                        checkbox.name = fieldName(name);
                        checkbox.value = checkedValue;
                        checkbox.checked = !!isChecked;

                        cell.appendChild(makeHidden(name, '0'));
                        labelNode.appendChild(checkbox);
                        labelNode.appendChild(document.createTextNode(' ' + labelText));
                        cell.appendChild(labelNode);

                        return cell;
                }

                var presetCell = makeCell('Preset');
                var select = document.createElement('select');
                var emptyOption = document.createElement('option');

                select.name = fieldName('preset');
                select.className = 'form-control';
                emptyOption.value = '';
                emptyOption.appendChild(document.createTextNode('Select preset'));
                select.appendChild(emptyOption);

                agentComponentPresets.forEach(function(preset) {
                        var id = String(preset.id || '');
                        var text = String(preset.label || id);
                        var option = document.createElement('option');

                        if (!id) {
                                return;
                        }

                        option.value = id;
                        option.setAttribute('data-capabilities', Array.isArray(preset.capabilities) ? preset.capabilities.join(',') : '');
                        option.appendChild(document.createTextNode(text + ' (' + id + ')'));

                        if (String(component.preset || '') === id) {
                                option.selected = true;
                        }

                        select.appendChild(option);
                });

                select.addEventListener('change', function() {
                        updateAgentComponentRowState(row);
                });

                presetCell.appendChild(select);
                row.appendChild(presetCell);
                row.appendChild(makeCheckbox('enabled', '1', 'Active', enabled));

                var capabilitiesCell = makeCell('Use as');
                var capabilitiesValue = document.createElement('div');
                capabilitiesValue.className = 'base3-chatbot-config-agent-component-capabilities';
                capabilitiesValue.setAttribute('data-base3-chatbot-agent-component-capabilities', '1');
                capabilitiesValue.appendChild(document.createTextNode(formatAgentComponentCapabilities(getAgentComponentCapabilities(component.preset || '', component))));
                capabilitiesCell.appendChild(capabilitiesValue);
                row.appendChild(capabilitiesCell);

                var orderCell = makeCell('Order');
                orderCell.setAttribute('data-base3-chatbot-agent-component-memory-fields', '1');
                orderCell.appendChild(makeInput('order', order, '10'));
                row.appendChild(orderCell);

                var namespaceCell = makeCell('Namespace');
                namespaceCell.setAttribute('data-base3-chatbot-agent-component-tool-fields', '1');
                namespaceCell.appendChild(makeInput('namespace', namespace, 'web'));
                row.appendChild(namespaceCell);

                var labelCell = makeCell('Label');
                labelCell.setAttribute('data-base3-chatbot-agent-component-tool-fields', '1');
                labelCell.appendChild(makeInput('label', label, 'Visible tool label'));
                row.appendChild(labelCell);

                var descriptionCell = makeCell('Description');
                descriptionCell.setAttribute('data-base3-chatbot-agent-component-tool-fields', '1');
                descriptionCell.appendChild(makeInput('description', description, 'Visible tool description'));
                row.appendChild(descriptionCell);

                var actionCell = makeCell('\u00a0');
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-default base3-chatbot-config-agent-component-remove';
                remove.setAttribute('data-base3-chatbot-agent-component-remove', '1');
                remove.appendChild(document.createTextNode('Remove'));
                remove.addEventListener('click', function() {
                        if (row.parentNode) {
                                row.parentNode.removeChild(row);
                        }
                });
                actionCell.appendChild(remove);
                row.appendChild(actionCell);
                updateAgentComponentRowState(row);

                return row;
        }

        function renderAgentComponents(items) {
                if (!agentComponentsItems) {
                        return;
                }

                if (!Array.isArray(items)) {
                        items = [];
                }

                nextAgentComponentIndex = 0;
                agentComponentsItems.innerHTML = '';

                items.forEach(function(item) {
                        agentComponentsItems.appendChild(createAgentComponentRow(item));
                });
        }

        function collectFormData() {
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
                        service: 'service',
                        llm: 'llm',
                        default_lang: 'default_lang',
                        transport_mode: 'transport_mode',
                        reference_mode: 'reference_mode',
                        reference_json: 'reference',
                        reference_provider: 'reference_provider',
                        system_prompt: 'system_prompt',
                        agent_flow_json: 'agent_flow'
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

                if (Object.prototype.hasOwnProperty.call(values, 'base_prompts')) {
                        renderBasePrompts(values.base_prompts);
                }

                if (Object.prototype.hasOwnProperty.call(values, 'agent_components')) {
                        renderAgentComponents(values.agent_components);
                }

                ['use_markdown', 'use_icons', 'use_voice', 'use_threads'].forEach(function(key) {
                        var field = root.querySelector('[name="' + key + '"]');

                        if (field) {
                                field.checked = !!values[key];
                        }
                });

                updateServicePreview();
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
                                                text: 'Settings could not be saved: ' + error.message
                                        }
                                ]);
                        })
                        .finally(function() {
                                button.disabled = false;
                        });
        }

        if (basePromptsRoot && basePromptsAdd && basePromptsItems) {
                basePromptsAdd.addEventListener('click', function() {
                        basePromptsItems.appendChild(createBasePromptRow(''));
                });

                basePromptsRoot.querySelectorAll('[data-base3-chatbot-base-prompt-remove]').forEach(function(remove) {
                        remove.addEventListener('click', function() {
                                var row = remove.closest('.base3-chatbot-config-base-prompt-row');

                                if (row && row.parentNode) {
                                        row.parentNode.removeChild(row);
                                }

                                if (!basePromptsItems.querySelector('[name="base_prompts[]"]')) {
                                        basePromptsItems.appendChild(createBasePromptRow(''));
                                }
                        });
                });
        }

        if (serviceSelect) {
                serviceSelect.addEventListener('change', updateServicePreview);
                updateServicePreview();
        }

        if (agentComponentsRoot && agentComponentsAdd && agentComponentsItems) {
                agentComponentsAdd.addEventListener('click', function() {
                        agentComponentsItems.appendChild(createAgentComponentRow({ enabled: true, attach_as: [] }));
                });

                agentComponentsRoot.querySelectorAll('[data-base3-chatbot-agent-component-row]').forEach(function(row) {
                        var select = row.querySelector('select[name$="[preset]"]');

                        if (select) {
                                select.addEventListener('change', function() {
                                        updateAgentComponentRowState(row);
                                });
                        }

                        updateAgentComponentRowState(row);
                });

                agentComponentsRoot.querySelectorAll('[data-base3-chatbot-agent-component-remove]').forEach(function(remove) {
                        remove.addEventListener('click', function() {
                                var row = remove.closest('[data-base3-chatbot-agent-component-row]');

                                if (row && row.parentNode) {
                                        row.parentNode.removeChild(row);
                                }
                        });
                });
        }

        if (root.tagName && root.tagName.toLowerCase() === 'form') {
                root.addEventListener('submit', save);
        } else {
                button.addEventListener('click', save);
        }
})();
</script>
<?php } ?>
