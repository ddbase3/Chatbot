<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IClassMap;
use Base3\Language\Api\ILanguage;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Content\ChatbotDisplay;
use Chatbot\Service\StaticChatbotAppearanceProvider;
use Chatbot\Service\ChatbotExtensionRegistry;
use Chatbot\Service\ChatbotExtensionService;
use Chatbot\Service\ChatbotSettingsService;
use PHPUnit\Framework\TestCase;
use UiFoundation\Api\IChatbotDisplay;

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
