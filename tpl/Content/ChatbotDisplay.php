<div id="chatbot" role="region" aria-label="Chatbot" data-service="<?php echo $this->_['service']; ?>">
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
document.addEventListener('DOMContentLoaded', () => {
	(async function() {
		await AssetLoader.loadCssAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.css'); ?>');
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatvoice/chatvoice.js'); ?>');
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.js'); ?>');

		initChatbot('#chatbot');
	})();
});
</script>

