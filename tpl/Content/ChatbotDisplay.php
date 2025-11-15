<div id="chatbot" role="region" aria-label="Chatbot" data-service="<?php echo $this->_['service']; ?>" data-sse="<?php echo !empty($this->_['sse']) ? '1' : '0'; ?>">
	<p class="baseprompt"></p>
	<div class="chat chatempty" aria-live="polite"></div>

	<div class="chatform">
		<textarea id="chatMessage" name="prompt" aria-label="Type your message"></textarea>
		<button type="button" id="chatSend" aria-label="Send message">
			<img src="<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/send.svg'); ?>" alt="Send" />
		</button>
		<div name="chatvoice"></div>
	</div>
</div>

<script>
	var chatbotInitialized = false;

	async function initChatbotWidget() {
		if (chatbotInitialized) return;
		chatbotInitialized = true;

		await AssetLoader.loadCssAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.css'); ?>');
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatvoice/chatvoice.js'); ?>');
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.js'); ?>');

		initChatbot('#chatbot', {
			<?php if (!empty($this->_['lang'])) { ?>defaultLang: '<?php echo $this->_['lang']; ?>',<?php } ?>
			icons: {
				copy: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/copy.svg'); ?>',
				check: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/check.svg'); ?>',
				thumbsup: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsup.svg'); ?>',
				thumbsupfill: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsupfill.svg'); ?>',
				thumbsdown: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsdown.svg'); ?>',
				thumbsdownfill: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsdownfill.svg'); ?>',
				reload: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/reload.svg'); ?>'
			}
		});
	}

	if (document.readyState !== 'loading') {
		initChatbotWidget();
	} else {
		document.addEventListener('DOMContentLoaded', initChatbotWidget);
	}
</script>

