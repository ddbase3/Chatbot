class ChatThreadsControl {
	constructor(config = {}) {
		this.config = Object.assign({
			events: {}
		}, config);

		this.threads = [];
		this.activeThreadId = null;
		this._popoverOpen = false;

		this._buildUI();
	}

	// ---------- UI ----------
	_buildUI() {
		this.container = document.createElement("div");
		this.container.className = "chat-threads-controls";

		this.popoverWrap = document.createElement("div");
		this.popoverWrap.className = "chat-action-popover";

		this.btnList = this._makeButton("Show chat threads", "list popover-toggle");
		this.popoverWrap.appendChild(this.btnList);

		this.popoverPanel = document.createElement("div");
		this.popoverPanel.className = "popover-panel";
		this.popoverPanel.setAttribute("aria-hidden", "true");

		const title = document.createElement("div");
		title.className = "popover-title";
		title.textContent = "Chat threads";
		this.popoverPanel.appendChild(title);

		const text = document.createElement("p");
		text.className = "popover-text";
		text.textContent = "Thread list is currently under development.";
		this.popoverPanel.appendChild(text);

		this.popoverWrap.appendChild(this.popoverPanel);

		this.btnNew = this._makeButton("Start new chat", "plus");

		this.container.append(this.popoverWrap, this.btnNew);

		this.btnList.addEventListener("click", e => {
			e.preventDefault();
			e.stopPropagation();
			this.togglePopover();
			this._trigger("onListRequested", this.threads, this.activeThreadId);
		});

		this.btnNew.addEventListener("click", () => {
			this._trigger("onNewThreadRequested");
		});

		this.popoverPanel.addEventListener("click", e => {
			e.stopPropagation();
		});

		document.addEventListener("click", e => {
			if (!this.popoverWrap.contains(e.target)) {
				this.closePopover();
			}
		});

		document.addEventListener("keydown", e => {
			if (e.key === "Escape") {
				this.closePopover();
			}
		});
	}

	_makeButton(label, className) {
		const b = document.createElement("button");
		b.type = "button";
		b.title = label;
		b.setAttribute("aria-label", label);
		if (className) {
			className.split(/\s+/).forEach(cls => {
				if (cls) b.classList.add(cls);
			});
		}
		return b;
	}

	attachTo(el) {
		if (!el) return;
		el.innerHTML = "";
		el.appendChild(this.container);
	}

	openPopover() {
		this._popoverOpen = true;
		this.popoverWrap.classList.add("open");
		this.btnList.classList.add("active");
		this.popoverPanel.setAttribute("aria-hidden", "false");
	}

	closePopover() {
		this._popoverOpen = false;
		this.popoverWrap.classList.remove("open");
		this.btnList.classList.remove("active");
		this.popoverPanel.setAttribute("aria-hidden", "true");
	}

	togglePopover() {
		if (this._popoverOpen) this.closePopover();
		else this.openPopover();
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

	// ---------- State placeholders ----------
	setThreads(threads = []) {
		this.threads = Array.isArray(threads) ? threads.slice() : [];
		return this;
	}

	setActiveThread(threadId = null) {
		this.activeThreadId = threadId;
		return this;
	}
}

window.ChatThreadsControl = ChatThreadsControl;
