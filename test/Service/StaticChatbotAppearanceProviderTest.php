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

namespace Base3\Chatbot\Test\Service;

use Base3\Chatbot\Api\IChatbotAppearanceProvider;
use Base3\Chatbot\Service\StaticChatbotAppearanceProvider;
use PHPUnit\Framework\TestCase;

final class StaticChatbotAppearanceProviderTest extends TestCase {

	public function testDefaultAppearanceContainsNoHostSpecificAssets(): void {
		$provider = new StaticChatbotAppearanceProvider();
		$this->assertInstanceOf(IChatbotAppearanceProvider::class, $provider);
		$this->assertSame('', $provider->getStylesheet());
		$this->assertSame('', $provider->getUserMessageIcon());
		$this->assertSame('', $provider->getAssistantMessageIcon());
		$this->assertSame('', $provider->getThinkingIcon());
		$this->assertSame('', $provider->getOpeningMessageIcon());
		$this->assertSame('', $provider->getInitialAssistantMessageLogo());
		$this->assertSame('', $provider->getInitialAssistantMessageTitle());
		$this->assertSame([], $provider->getDomClasses());
		$this->assertSame([], $provider->getControlIcons());
	}

	public function testConfiguredAppearanceReturnsExactlyInjectedValues(): void {
		$dom = ['chat' => 'chat-dark', 'message' => 'chat-message'];
		$controls = ['send' => 'arrow.svg', 'close' => 'close.svg'];
		$provider = new StaticChatbotAppearanceProvider('theme.css', 'user.svg', 'assistant.svg', 'thinking.svg', 'welcome.svg', $dom, $controls, 'logo.svg', 'Title');
		$this->assertSame('theme.css', $provider->getStylesheet());
		$this->assertSame('user.svg', $provider->getUserMessageIcon());
		$this->assertSame('assistant.svg', $provider->getAssistantMessageIcon());
		$this->assertSame('thinking.svg', $provider->getThinkingIcon());
		$this->assertSame('welcome.svg', $provider->getOpeningMessageIcon());
		$this->assertSame('logo.svg', $provider->getInitialAssistantMessageLogo());
		$this->assertSame('Title', $provider->getInitialAssistantMessageTitle());
		$this->assertSame($dom, $provider->getDomClasses());
		$this->assertSame($controls, $provider->getControlIcons());
	}
}
