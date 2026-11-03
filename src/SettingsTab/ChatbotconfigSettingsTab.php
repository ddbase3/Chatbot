<?php declare(strict_types=1);

namespace Chatbot\SettingsTab;

use UiFoundation\Settings\AbstractFeatureSettingsTab;

final class ChatbotconfigSettingsTab extends AbstractFeatureSettingsTab {

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
