<?php declare(strict_types=1);

namespace Chatbot\SettingsTab;

use UiFoundation\Settings\AbstractFeatureSettingsTab;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Translation\Api\ITranslation;

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
