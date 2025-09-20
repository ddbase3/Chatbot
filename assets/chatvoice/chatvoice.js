class ChatVoiceControl {
	constructor(config = {}) {
		this.config = Object.assign({
			stt: "browser",        // "off" | "browser" | "service"
			tts: "browser",        // "off" | "browser" | "service"
			serviceUrls: {
				stt: "/transcribe.php",
				tts: "/speak.php"
			},
			lang: "auto",
			availableLangs: null,
			events: {}
		}, config);

		this.isRecording = false;
		this.keepOn = false;
		this.speechEnabled = false;
		this.activeAudio = null;

		this._buildUI();
		this._bindShortcuts();
	}

	// ---------- UI ----------
	_buildUI() {
		this.container = document.createElement("div");
		this.container.className = "chat-voice-controls";

		this.btnMic = this._makeButton("🎤", "Start/stop microphone");
		this.btnSpeaker = this._makeButton("🔈", "Toggle text-to-speech");
		this.btnDialog = this._makeButton("🔁", "Toggle dialog mode");

		this.container.append(this.btnMic, this.btnSpeaker, this.btnDialog);

		if (this.config.availableLangs) {
			this.langSelect = document.createElement("select");
			this.config.availableLangs.forEach(opt => {
				const o = document.createElement("option");
				o.value = opt.code;
				o.textContent = opt.label;
				if (opt.code === this.config.lang) o.selected = true;
				this.langSelect.appendChild(o);
			});
			this.langSelect.addEventListener("change", () => {
				this.config.lang = this.langSelect.value;
			});
			this.container.appendChild(this.langSelect);
		}

		this.btnMic.addEventListener("click", () => this.toggleMic());
		this.btnSpeaker.addEventListener("click", () => this.toggleSpeaker());
		this.btnDialog.addEventListener("click", () => this.toggleDialog());
	}

	_makeButton(icon, label) {
		const b = document.createElement("button");
		b.type = "button";
		b.textContent = icon;
		b.title = label;
		b.setAttribute("aria-label", label);
		return b;
	}

	attachTo(el) {
		el.appendChild(this.container);
	}

	// ---------- Events ----------
	_trigger(event, ...args) {
		if (this.config.events && typeof this.config.events[event] === "function") {
			try {
				this.config.events[event](...args);
			} catch (e) {
				console.error("Event handler error", e);
			}
		}
	}

	// ---------- Microphone / STT ----------
	async startRecording() {
		if (this.config.stt === "off") return;
		try {
			if (this.config.stt === "browser") {
				const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
				if (!SpeechRecognition) throw new Error("Browser STT not supported");
				this.recog = new SpeechRecognition();
				this.recog.lang = this.config.lang === "auto" ? "de-DE" : this.config.lang;
				this.recog.onresult = e => {
					const text = e.results[0][0].transcript;
					this._trigger("onUserFinishedSpeaking", text);

					// in dialog mode: request sending
					if (this.keepOn) {
						this._trigger("onSendRequested", text);
					}
				};
				this.recog.onend = () => {
					this.isRecording = false;
					this.btnMic.textContent = "🎤";
					this._trigger("onRecordingEnded");
				};
				this.recog.onerror = e => {
					if (e.error === "no-speech" && this.keepOn) {
						// auto-exit dialog mode on silence
						this.keepOn = false;
						this.btnMic.style.display = "";
						this.btnSpeaker.style.display = "";
						this.btnDialog.textContent = "🔁";
						this._trigger("onDialogEnded");
					} else {
						this._trigger("onError", e);
					}
				};
				this.recog.start();
			} else if (this.config.stt === "service") {
				console.log("STT service mode started");
			}
			this.isRecording = true;
			this.btnMic.textContent = "🛑";
		} catch (err) {
			this._trigger("onError", err);
		}
	}

	stopRecording() {
		if (!this.isRecording) return;
		if (this.recog) this.recog.stop();
		this.isRecording = false;
		this.btnMic.textContent = "🎤";
	}

	toggleMic() {
		if (this.isRecording) this.stopRecording();
		else this.startRecording();
	}

	// ---------- TTS ----------
	speak(text, onEnd) {
		if (this.config.tts === "off") return;
		try {
			const cleanText = this._stripHtml(text);
			if (this.config.tts === "browser") {
				const u = new SpeechSynthesisUtterance(cleanText);
				u.lang = this.config.lang === "auto" ? "de-DE" : this.config.lang;
				if (onEnd) u.onend = onEnd;
				speechSynthesis.speak(u);
				this._trigger("onTtsStarted", cleanText);
			} else if (this.config.tts === "service") {
				fetch(this.config.serviceUrls.tts, {
					method: "POST",
					headers: { "Content-Type": "application/json" },
					body: JSON.stringify({ text: cleanText, lang: this.config.lang })
				})
					.then(r => r.blob())
					.then(blob => {
						this.activeAudio = new Audio(URL.createObjectURL(blob));
						if (onEnd) this.activeAudio.onended = onEnd;
						this.activeAudio.play();
						this._trigger("onTtsStarted", cleanText);
					})
					.catch(err => this._trigger("onError", err));
			}
		} catch (err) {
			this._trigger("onError", err);
		}
	}

	toggleSpeaker() {
		this.speechEnabled = !this.speechEnabled;
		this.btnSpeaker.textContent = this.speechEnabled ? "🔊" : "🔈";

		if (!this.speechEnabled) {
			if (this.config.tts === "browser") {
				speechSynthesis.cancel();
			} else if (this.config.tts === "service" && this.activeAudio) {
				this.activeAudio.pause();
				this.activeAudio.currentTime = 0;
				this.activeAudio = null;
			}
		}

		this._trigger("onSpeakerToggled", this.speechEnabled);
	}

	// ---------- Dialog mode ----------
	toggleDialog() {
		this.keepOn = !this.keepOn;
		if (this.keepOn) {
			this.btnMic.style.display = "none";
			this.btnSpeaker.style.display = "none";
			this.btnDialog.textContent = "⏹";
			this.startRecording();
		} else {
			this.btnMic.style.display = "";
			this.btnSpeaker.style.display = "";
			this.btnDialog.textContent = "🔁";
			this.stopRecording();

			// stop any ongoing TTS immediately
			if (this.config.tts === "browser") {
				speechSynthesis.cancel();
			} else if (this.config.tts === "service" && this.activeAudio) {
				this.activeAudio.pause();
				this.activeAudio.currentTime = 0;
				this.activeAudio = null;
			}
			this._trigger("onDialogEnded");
		}
	}

	handleAssistantReply(replyText) {
		this._trigger("onAssistantReplied", replyText);
		if (this.speechEnabled || this.keepOn) {
			this.speak(replyText, () => {
				this._trigger("onTtsFinished");
				if (this.keepOn) this.startRecording();
			});
		}
	}

	// ---------- Helpers ----------
	_stripHtml(input) {
		const tmp = document.createElement("div");
		tmp.innerHTML = input;
		return tmp.textContent || tmp.innerText || "";
	}

	// ---------- Accessibility ----------
	_bindShortcuts() {
		document.addEventListener("keydown", e => {
			if (e.target.tagName === "TEXTAREA" || e.target.tagName === "INPUT") return;
			if (e.code === "Space") { e.preventDefault(); this.toggleMic(); }
			if (e.key === "l") this.toggleSpeaker();
			if (e.key === "d") this.toggleDialog();
		});
	}
}

