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

use Base3\AssistantFoundation\Api\IAgentConfigFormService;
use Base3\AssistantFoundation\Api\IAgentRuntimeRegistry;
use Base3\AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\CoreFoundation\Api\IAssetResolver;
use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IMvcView;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\Chatbot\Content\ChatbotConfigDisplay;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestableChatbotConfigDisplay extends ChatbotConfigDisplay {

	/** @return array<string,mixed> */
	public function getTestResourcesConfig(): array {
		return $this->getResourcesConfig();
	}

	/** @return array<string,mixed> */
	public function getTestContext(): array {
		return $this->getContext(false);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function loadTestSettings(array $context): array {
		return $this->loadSettings($context);
	}

	/** @return array<string,mixed> */
	public function getTestDefaultSettings(): array {
		return $this->getDefaultSettings();
	}

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	public function normalizeTestSettings(array $settings): array {
		return $this->normalizeSettings($settings);
	}
}
