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

namespace Base3\Chatbot\Test\Content;

use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Language\Api\ILanguage;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Content\ChatbotDisplay;
use Base3\Chatbot\Service\StaticChatbotAppearanceProvider;
use Base3\Chatbot\Service\ChatbotExtensionRegistry;
use Base3\Chatbot\Service\ChatbotExtensionService;
use Base3\Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;
use Base3\UiFoundation\Api\IChatbotDisplay;

class FakeChatbotDisplay implements IChatbotDisplay {

	private array $data = [];
	private string $lastOutputFormat = '';
	private bool $lastOutputFinal = false;

	public static function getName(): string {
		return 'fakechatbotdisplay';
	}

	public function setData($data): void {
		$this->data = is_array($data) ? $data : [];
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$this->lastOutputFormat = $out;
		$this->lastOutputFinal = $final;

		return 'FAKE_CHATBOT_OUTPUT';
	}

	public function getHelp(): string {
		return 'Fake chatbot display.';
	}

	public function getData(): array {
		return $this->data;
	}

	public function getLastOutputFormat(): string {
		return $this->lastOutputFormat;
	}

	public function isLastOutputFinal(): bool {
		return $this->lastOutputFinal;
	}
}
