<?php
	$chatbotClasses = ['canvas-side'];  // canvas-side optional for different layout variants
	if (empty($this->_['use_icons'])) $chatbotClasses[] = 'no-icons';
	if (empty($this->_['use_voice'])) $chatbotClasses[] = 'no-voice';
	if (empty($this->_['use_threads'])) $chatbotClasses[] = 'no-threads';
	$classAttr = $chatbotClasses ? ' class="' . implode(' ', $chatbotClasses) . '"' : '';
?>

<style>
	/* Critical CSS for initial render before async chatbot.css arrives */
	#chatbot {
		--chatbot-h: 350px;
		width: 100%;
		max-width: 64em;
		margin: 0 auto;
		height: var(--chatbot-h);
		overflow: hidden;
		display: grid;
		gap: 16px;
		grid-template-columns: 1fr;
		grid-template-rows: auto 1fr auto;
		grid-template-areas:
			"baseprompt"
			"main"
			"form";
		min-height: 0;
	}

	#chatbot.chatstarted {
		--chatbot-h: 650px;
	}

	#chatbot .baseprompt {
		min-height: 40px;
		margin: 100px 0 0;
		font-size: 18pt;
		text-align: center;
		overflow: hidden;
	}

	#chatbot .chatbot-main {
		display: block;
		min-width: 0;
		min-height: 0;
		position: relative;
	}

	#chatbot .chat {
		height: 100%;
		margin: 0;
		padding: 0 10px;
		overflow: auto;
		overflow-x: hidden;
		min-height: 0;
	}

	#chatbot .chat.chatempty {
		height: 100%;
	}

	#chatbot .chatbot-canvas {
		display: none;
	}

	#chatbot .chatform {
		position: relative;
		padding: 15px 30px;
		border: 1px solid #ddd;
		border-radius: 30px;
		min-height: 0;
	}

	#chatbot textarea {
		width: 100%;
		box-sizing: border-box;
		min-height: 50px;
		max-height: 150px;
		overflow-y: auto;
		margin-bottom: 12px;
		padding: 3px 10px;
		background: transparent;
		border: 0;
		outline: none;
		resize: none;
	}

	#chatbot .chat-actions {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		flex-wrap: wrap;
		min-height: 40px;
	}

	#chatbot .chat-actions-left,
	#chatbot .chat-actions-right {
		display: flex;
		align-items: center;
		gap: 8px;
		min-width: 0;
	}

	#chatbot .chat-actions-right {
		margin-left: auto;
	}

	#chatbot button#chatSend {
		box-sizing: content-box;
		width: 20px;
		height: 20px;
		padding: 10px;
		margin: 0 10px;
		background: #333;
		border: 0;
		border-radius: 20px;
		cursor: pointer;
		flex: 0 0 auto;
	}

	#chatbot button#chatSend img {
		width: 20px;
		height: 20px;
		filter: invert(1);
	}

	#chatbot.no-icons .chat-tools {
		display: none !important;
	}

	#chatbot.no-voice [name="chatvoice"] {
		display: none !important;
	}

	#chatbot.no-threads [name="chatthreads"] {
		display: none !important;
	}
</style>

<div id="chatbot"<?php echo $classAttr; ?> role="region" aria-label="Chatbot">
	<p class="baseprompt"></p>

	<div class="chatbot-main">
		<div class="chat chatempty" aria-live="polite"></div>
	</div>

	<aside class="chatbot-canvas" aria-label="Canvas" aria-hidden="true">
		<div class="canvas-header">
			<div class="canvas-title">Canvas</div>
			<button type="button" class="canvas-close" aria-label="Close canvas">×</button>
		</div>
		<div class="canvas-content"></div>
	</aside>

	<div class="chatform">
		<textarea id="chatMessage" name="prompt" aria-label="Type your message"></textarea>

		<div class="chat-actions">
			<div class="chat-actions-left">
				<div name="chatthreads"></div>
			</div>

			<div class="chat-actions-right">
				<div name="chatvoice"></div>
				<button type="button" id="chatSend" aria-label="Send message">
					<img src="<?php echo $this->_['resolve']('plugin/Chatbot/assets/icons/send.svg'); ?>" alt="Send" />
				</button>
			</div>
		</div>
	</div>
</div>

<script>

function initChatbotWidget() {
	(async function() {
		await AssetLoader.loadCssAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatbot/chatbot.css'); ?>');
<?php if (!empty($this->_['use_voice'])) { ?>
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatvoice/chatvoice.js'); ?>');
<?php } ?>
<?php if (!empty($this->_['use_threads'])) { ?>
		await AssetLoader.loadScriptAsync('<?php echo $this->_['resolve']('plugin/Chatbot/assets/chatthreads/chatthreads.js'); ?>');
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
			useThreads: <?php echo !empty($this->_['use_threads']) ? 'true' : 'false'; ?>,

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
