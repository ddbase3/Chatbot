		<section id="chatbot">
			<div class="frame">
				<div class="chat"></div>
				<form>
					<textarea name="prompt"></textarea>
					<input type="submit" name="submit" value="Send" />
				</form>
			</div>
		</section>

		<script>
			document.addEventListener('DOMContentLoaded', () => {
		                var chatControl = $('#chatbot .chat');
				var msgControl = $('#chatbot textarea');

				function scrollToBottom() {
					chatControl.stop().animate({ scrollTop: chatControl[0].scrollHeight }, 300);
				}

				function scrollToResponse() {
				        var last = chatControl.children().last();
					var scrollOffset = last.offset().top - chatControl.offset().top + chatControl.scrollTop();
					chatControl.stop().animate({ scrollTop: scrollOffset - 10 }, 300);
				}

				$('#chatbot form').on('submit', function(e) {
		                        e.preventDefault();
				        var message = msgControl.val().replace(/(?:\r\n|\r|\n)/g, '<br>');
		                        msgControl.val('');
				        chatControl.append('<div class="user">' + message + '</div>');
		                        scrollToBottom();

					$.post('<?php echo $this->_['service']; ?>', { prompt: message }, function(result) {
		                                var response = result.replace(/(?:\r\n|\r|\n)/g, '<br>');
						chatControl.append('<div class="assistent">' + response + '</div>');
				                scrollToResponse();
		                        });
				});
		        });
		</script>

		<style>
			#chatbot .chat { height:400px; border: 1px solid #ddd; overflow-x:hidden; }
			#chatbot .chat > div { margin:10px; padding:10px; border:1px solid #eee; background:#f7f7f7; border-radius:5px; }
			#chatbot .chat > .user { margin-left:50px; color:#009; }
			#chatbot .chat > .assistent { margin-right:50px; color:#090; }
			#chatbot textarea { display:block; width:100%; height:80px; }


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
