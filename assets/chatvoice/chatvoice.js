class ChatVoiceControl {
	constructor(config = {}) {
		this.config = Object.assign({
			stt: "browser",
			tts: "browser",
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

		// state flags
		this._hadSpeechResult = false;
		this._ttsInProgress = false;

		this._buildUI();
		this._bindShortcuts();
	}

	// ---------- UI ----------
	_buildUI() {
		this.container = document.createElement("div");
		this.container.className = "chat-voice-controls";

		this.btnMic = this._makeButton("Start/stop microphone", "microphone");
		this.btnSpeaker = this._makeButton("Toggle text-to-speech", "speaker");
		this.btnDialog = this._makeButton("Toggle dialog mode", "dialogue");

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

	_makeButton(label, className) {
		const b = document.createElement("button");
		b.type = "button";
		b.title = label;
		b.setAttribute("aria-label", label);
		if (className) b.classList.add(className);
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

				// reset flags for this recognition turn
				this._hadSpeechResult = false;

				this.recog.onresult = e => {
					this._hadSpeechResult = true;
					const text = e.results[0][0].transcript;
					this._trigger("onUserFinishedSpeaking", text);
					if (this.keepOn) this._trigger("onSendRequested", text);
				};

				this.recog.onend = () => {
					this.isRecording = false;
					this.btnMic.classList.remove("active");
					this._trigger("onRecordingEnded");

					// auto-end dialog only when silence (no speech) and not in TTS
					if (this.keepOn && !this._hadSpeechResult && !this._ttsInProgress) {
						this._endDialogMode();
					}
				};

				this.recog.onerror = e => {
					if (e.error === "no-speech" && this.keepOn) {
						this._endDialogMode();
					} else {
						this._trigger("onError", e);
					}
				};

				this.recog.start();
			} else if (this.config.stt === "service") {
				console.log("STT service mode started");
			}
			this.isRecording = true;
			this.btnMic.classList.add("active");
		} catch (err) {
			this._trigger("onError", err);
		}
	}

	stopRecording() {
		if (!this.isRecording) return;
		if (this.recog) this.recog.stop();
		this.isRecording = false;
		this.btnMic.classList.remove("active");
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
				this._ttsInProgress = true;
				u.onend = () => {
					this._ttsInProgress = false;
					onEnd && onEnd();
				};
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
					this._ttsInProgress = true;
					this.activeAudio.onended = () => {
						this._ttsInProgress = false;
						onEnd && onEnd();
					};
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
		if (this.speechEnabled) {
			this.btnSpeaker.classList.add("active");
		} else {
			this.btnSpeaker.classList.remove("active");
			if (this.config.tts === "browser") {
				speechSynthesis.cancel();
			} else if (this.config.tts === "service" && this.activeAudio) {
				this.activeAudio.pause();
				this.activeAudio.currentTime = 0;
				this.activeAudio = null;
			}
			this._ttsInProgress = false;
		}
		this._trigger("onSpeakerToggled", this.speechEnabled);
	}

	// ---------- Dialog mode ----------
	toggleDialog() {
		this.keepOn = !this.keepOn;
		if (this.keepOn) {
			this.btnDialog.classList.add("active");

			// mic on + speaker initially off
			this.btnMic.classList.add("active");
			this.btnMic.disabled = true;
			this.btnSpeaker.classList.remove("active");
			this.btnSpeaker.disabled = true;

			this.startRecording();
		} else {
			this._endDialogMode();
		}
	}

	_endDialogMode() {
		this.keepOn = false;
		this.btnDialog.classList.remove("active");

		// reset mic + speaker
		this.btnMic.classList.remove("active");
		this.btnMic.disabled = false;
		this.btnSpeaker.classList.remove("active");
		this.btnSpeaker.disabled = false;

		this.stopRecording();

		// stop TTS immediately
		if (this.config.tts === "browser") {
			speechSynthesis.cancel();
		} else if (this.config.tts === "service" && this.activeAudio) {
			this.activeAudio.pause();
			this.activeAudio.currentTime = 0;
			this.activeAudio = null;
		}
		this._ttsInProgress = false;
		this._trigger("onDialogEnded");
	}

	handleAssistantReply(replyText) {
		this._trigger("onAssistantReplied", replyText);
		if (this.speechEnabled || this.keepOn) {
			if (this.keepOn) this.btnSpeaker.classList.add("active");

			this.speak(replyText, () => {
				if (this.keepOn) this.btnSpeaker.classList.remove("active");

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

