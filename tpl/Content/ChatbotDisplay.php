<?php
$chatbotClasses = [];
if (empty($this->_['use_icons'])) $chatbotClasses[] = 'no-icons';
if (empty($this->_['use_voice'])) $chatbotClasses[] = 'no-voice';
$classAttr = $chatbotClasses ? ' class="' . implode(' ', $chatbotClasses) . '"' : '';
?>

<div id="chatbot"<?php echo $classAttr; ?> role="region" aria-label="Chatbot">
	<p class="baseprompt"></p>

	<div class="chatbot-main">
		<div class="chat chatempty" aria-live="polite"></div>

		<!-- Canvas Panel (initially hidden, controlled by JS via .canvas-open class + aria-hidden) -->
		<aside class="chatbot-canvas" aria-label="Canvas" aria-hidden="true">
			<div class="canvas-header">
				<div class="canvas-title">Canvas</div>
				<button type="button" class="canvas-close" aria-label="Close canvas">×</button>
			</div>
			<div class="canvas-content"></div>
		</aside>
	</div>

	<div class="chatform">
		<textarea id="chatMessage" name="prompt" aria-label="Type your message"></textarea>
		<button type="button" id="chatSend" aria-label="Send message">
			<img src="<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/send.svg'); ?>" alt="Send" />
		</button>
		<div name="chatvoice"></div>
	</div>
</div>


<script>

function initChatbotWidget() {
	(async function() {
		await AssetLoader.loadCssAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.css'); ?>');
<?php if (!empty($this->_['use_voice'])) { ?>
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatvoice/chatvoice.js'); ?>');
<?php } ?>
<?php if (!empty($this->_['use_markdown'])) { ?>
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/ClientStack/assets/marked/marked.js'); ?>');
<?php } ?>
<?php if ($this->_['transport_mode'] !== 'rest') { ?>
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/EventTransport/assets/eventtransportclient.js'); ?>');
<?php } ?>
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.js'); ?>');

		const chatbotConfig = {
			useMarkdown: <?php echo $this->_['use_markdown'] ? 'true' : 'false'; ?>,
			useIcons: <?php echo $this->_['use_icons'] ? 'true' : 'false'; ?>,
			useVoice: <?php echo $this->_['use_voice'] ? 'true' : 'false'; ?>,

			serviceUrl: '<?php echo $this->_['service']; ?>',
			transportMode: '<?php echo $this->_['transport_mode']; ?>',

<?php if ($this->_['use_voice']) { ?>
			defaultLang: '<?php echo $this->_['default_lang']; ?>',
			icons: {
				copy: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/copy.svg'); ?>',
				check: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/check.svg'); ?>',
				thumbsup: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsup.svg'); ?>',
				thumbsupfill: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsupfill.svg'); ?>',
				thumbsdown: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsdown.svg'); ?>',
				thumbsdownfill: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/thumbsdownfill.svg'); ?>',
				reload: '<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/reload.svg'); ?>'
			}
<?php } ?>
		};

		initChatbot('#chatbot', chatbotConfig);
	})();
}

if (document.readyState !== 'loading') {
	initChatbotWidget();
} else {
	document.addEventListener('DOMContentLoaded', initChatbotWidget);
}

window.addEventListener('chatbot:init', initChatbotWidget);

</script>
