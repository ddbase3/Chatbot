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
		        $(function() {
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

				        $.post('chatbotservice.php', { prompt: message }, function(result) {
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
		</style>
