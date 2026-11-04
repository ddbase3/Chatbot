<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Chatbot\Api\IChatbotAppearanceProvider;
use Chatbot\Service\StaticChatbotAppearanceProvider;
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
