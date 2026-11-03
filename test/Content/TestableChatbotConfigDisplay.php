<?php declare(strict_types=1);

namespace Chatbot\Test\Content;

use AssistantFoundation\Api\IAgentConfigFormService;
use AssistantFoundation\Api\IAgentRuntimeRegistry;
use AssistantFoundation\Api\IAgentRuntimeSelector;
use Base3\Api\IAssetResolver;
use Base3\Api\IClassMap;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Settings\Api\ISettingsStore;
use Chatbot\Content\ChatbotConfigDisplay;
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
