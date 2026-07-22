/**
 * Chatbot-specific POST -> turn id -> EventSource client.
 *
 * Large prompts are sent through POST. The returned single-use stream URL is
 * then opened with EventSource, which only supports GET.
 */
class ChatbotStreamClient {
	constructor(options = {}) {
		this.options = Object.assign({
			prepareUrl: null,
			service: null,
			events: [],
			payload: null
		}, options);

		this.onEventCallback = null;
		this.eventSource = null;
	}

	async connect(callback) {
		this.onEventCallback = typeof callback === 'function' ? callback : () => {};

		try {
			const response = await fetch(this.options.prepareUrl, {
				method: 'POST',
				credentials: 'include',
				headers: {
					'Content-Type': 'application/json; charset=UTF-8'
				},
				body: JSON.stringify({
					service: this.options.service,
					payload: this.options.payload || {}
				})
			});

			let info;
			try {
				info = await response.json();
			} catch (error) {
				throw new Error('Invalid chatbot turn preparation response.');
			}

			if (!response.ok || !info || info.ok !== true || !info.stream) {
				throw new Error(info && info.error ? String(info.error) : 'Chatbot turn preparation failed.');
			}

			this.openEventSource(String(info.stream));
		} catch (error) {
			const message = 'Der Chat-Stream konnte nicht gestartet werden.';
			this.onEventCallback('token', { text: message });
			this.onEventCallback('error', {
				message: error && error.message ? String(error.message) : String(error),
				user_message: message
			});
			this.onEventCallback('done', { status: 'error' });
		}
	}

	openEventSource(url) {
		const eventSource = new EventSource(url, { withCredentials: true });
		this.eventSource = eventSource;

		eventSource.onmessage = event => {
			this.onEventCallback('message', this.parseData(event.data));
		};

		const eventNames = new Set(['done', 'error']);
		if (Array.isArray(this.options.events)) {
			this.options.events.forEach(eventName => eventNames.add(String(eventName)));
		}

		eventNames.forEach(eventName => {
			eventSource.addEventListener(eventName, event => {
				this.onEventCallback(eventName, this.parseData(event.data));
			});
		});
	}

	close() {
		if (this.eventSource) {
			this.eventSource.close();
			this.eventSource = null;
		}
	}

	parseData(value) {
		try {
			return JSON.parse(value);
		} catch (error) {
			return value;
		}
	}
}

window.ChatbotStreamClient = ChatbotStreamClient;
