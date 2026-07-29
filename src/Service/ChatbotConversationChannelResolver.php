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

namespace Chatbot\Service;

/**
 * Resolves the canonical server-side conversation channel of one chatbot.
 *
 * A configured chatbot is identified by its SettingsStore group and name.
 * Browser-provided display ids are not part of the conversation identity.
 */
final class ChatbotConversationChannelResolver {

	public static function getName(): string {
		return 'chatbotconversationchannelresolver';
	}

	public function resolve(string $configGroup, string $configName): string {
		$configGroup = trim($configGroup);
		$configName = trim($configName);

		if($configGroup === '' || $configName === '') {
			return '';
		}

		return 'chatbot:' . hash('sha256', $configGroup . "\0" . $configName);
	}
}
