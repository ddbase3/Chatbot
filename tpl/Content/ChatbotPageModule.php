<section id="chatbot">
	<div class="frame">
		<div class="chat"></div>
		<form>
			<textarea name="prompt"></textarea>
			<div name="chatvoice"></div>
			<input type="submit" name="submit" value="Send" />
		</form>
	</div>
</section>

<script src="plugin/Chatbot/assets/chatvoice/chatvoice.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
	const chatControl = $('#chatbot .chat');
	const msgControl = $('#chatbot textarea');
	const form = $('#chatbot form');

	function scrollToBottom() {
		chatControl.stop().animate({ scrollTop: chatControl[0].scrollHeight }, 300);
	}

	function scrollToResponse() {
		const last = chatControl.children().last();
		const scrollOffset = last.offset().top - chatControl.offset().top + chatControl.scrollTop();
		chatControl.stop().animate({ scrollTop: scrollOffset - 10 }, 300);
	}

	// --- submit handler ---
	form.on('submit', function(e) {
		e.preventDefault();

		const message = msgControl.val().replace(/(?:\r\n|\r|\n)/g, '<br>');
		msgControl.val('');
		chatControl.append('<div class="message user">' + message + '</div>');
		scrollToBottom();

		const loader = $('<div class="loading"><div class="spinner"></div></div>');
		chatControl.append(loader);
		scrollToBottom();

		$.post('<?php echo $this->_['service']; ?>', { prompt: message }, function(result) {
			loader.remove();

			const response = result.replace(/(?:\r\n|\r|\n)/g, '<br>');
			chatControl.append('<div class="message assistent">' + response + '</div>');
			scrollToResponse();

			// pass reply to voice control
			voiceCtrl.handleAssistantReply(response);
		});
	});

	// --- enter-to-submit ---
	msgControl.on('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			form.submit();
		}
	});

	// --- init voice control ---
	const voiceCtrl = new ChatVoiceControl({
		stt: "browser",
		tts: "browser",
		lang: "auto",
		availableLangs: [
			{ code: "auto", label: "Auto" },
			{ code: "de-DE", label: "Deutsch" },
			{ code: "en-US", label: "English" },
			{ code: "fr-FR", label: "Français" },
			{ code: "es-ES", label: "Español" },
			{ code: "it-IT", label: "Italiano" },
			{ code: "pt-PT", label: "Português" },
			{ code: "bg-BG", label: "Български" },
			{ code: "ro-RO", label: "Română" },
			{ code: "uk-UA", label: "Українська" },
			{ code: "ru-RU", label: "Русский" }
		],
		events: {
			onUserFinishedSpeaking: (text) => {
				console.log("STT finished:", text);
				// text ins textarea übernehmen (Mitschrieb)
				const current = msgControl.val();
				msgControl.val(current ? current + " " + text : text);
			},
			onSendRequested: (text) => {
				console.log("Dialog autosend:", text);
				// im Dialogmodus sofort senden
				form.submit();
			},
			onAssistantReplied: (reply) => console.log("Assistant replied:", reply),
			onTtsStarted: (txt) => console.log("TTS started:", txt),
			onTtsFinished: () => console.log("TTS finished"),
			onRecordingEnded: () => console.log("Recording ended"),
			onError: (err) => console.error("VoiceControl Error:", err)
		}
	});

	// attach voice control UI
	voiceCtrl.attachTo(document.querySelector("#chatbot [name=chatvoice]"));
});
</script>


		<style>
			#chatbot .chat { height:400px; border: 1px solid #ddd; overflow-x:hidden; }
			#chatbot .chat > .message { margin:10px; padding:10px; border:1px solid #eee; background:#f7f7f7; border-radius:5px; overflow:auto; }
			#chatbot .chat > .user { margin-left:50px; color:#009; }
			#chatbot .chat > .assistent { margin-right:50px; color:#090; }
			#chatbot textarea { display:block; width:100%; height:80px; }


			.loading { text-align: center; margin: 10px; }
			.spinner {
				display: inline-block;
				width: 24px; height: 24px;
				border: 3px solid #ccc; border-top: 3px solid #0077cc; border-radius: 50%;
				animation: spin 1s linear infinite;
			}
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}



			#chatbot [name="chatvoice"] { float: right; width: auto; }
			#chatbot [name="chatvoice"] { text-align: right; }
			#chatbot [name="chatvoice"] button, #chatbot [name="chatvoice"] select { width: auto; vertical-align: middle; margin-left: 4px; }


			.info-box {
				border: 2px solid #ccc;
				border-left-width: 6px;
				border-radius: 6px;
				padding: 0.8em 1em;
				margin: 1em 0;
				background-color: #fdfdfd;
				font-family: sans-serif;
				font-size: 0.95em;
				line-height: 1.4;
				box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
			}

			.info-header {
				font-weight: bold;
				font-size: 1em;
				margin-bottom: 0.4em;
			}

			.info-fact   { border-left-color: #0077cc; }
			.info-weather{ border-left-color: #00aaff; }
			.info-joke   { border-left-color: #ffaa00; }
			.info-warning{ border-left-color: #cc0000; }

			.label {
				font-weight: bold;
				display: inline-block;
				width: 90px;
				color: #333;
			}

			.result.richtig { color: #007700; font-weight: bold; }
			.result.falsch  { color: #cc0000; font-weight: bold; }
		</style>
