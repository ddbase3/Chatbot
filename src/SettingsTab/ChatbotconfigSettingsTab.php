<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of Chatbot for BASE3 Framework.
 *
 * Chatbot extends the BASE3 framework with a modular API
 * foundation for flow-based chatbot services and interfaces.
 * It provides reusable components for AI-driven conversations.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/chatbot
 * https://github.com/ddbase3/Chatbot
 **********************************************************************/

namespace Base3\Chatbot\SettingsTab;

use Base3\UiFoundation\Settings\AbstractFeatureSettingsTab;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Translation\Api\ITranslation;

final class ChatbotconfigSettingsTab extends AbstractFeatureSettingsTab {

	/**
	 * @param array<string,mixed> $chatbotConfigDisplayData Project-composed instance
	 * and optional resources configuration. An empty array keeps the existing
	 * default chatbot configuration display unchanged.
	 */
	public function __construct(
		ITranslation $translation,
		IAuthorizationService $authorization,
		private readonly array $chatbotConfigDisplayData = []
	) {
		parent::__construct($translation, $authorization);
	}

	public function getDisplayData(): mixed {
		return $this->chatbotConfigDisplayData !== [] ? $this->chatbotConfigDisplayData : null;
	}

	public function getParentTabName(): ?string {
		return 'chatbotsettingstab';
	}

	public function getDisplayName(): ?string {
		return 'chatbotconfigdisplay';
	}

	public function getOrder(): int {
		return 10;
	}
}
