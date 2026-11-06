<?php declare(strict_types=1);

namespace Chatbot\SettingsTab;

use UiFoundation\Settings\AbstractSettingsTab;

final class ChatbotSettingsTab extends AbstractSettingsTab {

	public function getOrder(): int {
		return 38;
	}
}
