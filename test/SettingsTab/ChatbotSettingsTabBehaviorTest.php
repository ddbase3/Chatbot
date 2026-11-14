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

namespace Base3\Chatbot\Test\SettingsTab;

use Base3\CoreFoundation\Translation\Api\ITranslation;
use Base3\Chatbot\SettingsTab\ChatbotSettingsTab;
use PHPUnit\Framework\TestCase;

final class ChatbotSettingsTabBehaviorTest extends TestCase {

	public function testTechnicalNameAndRootNavigationContract(): void {
		$tab = new ChatbotSettingsTab($this->createStub(ITranslation::class));
		$this->assertSame('chatbotsettingstab', ChatbotSettingsTab::getName());
		$this->assertSame(38, $tab->getOrder());
		$this->assertNull($tab->getParentTabName());
		$this->assertNull($tab->getDisplayName());
		$this->assertNull($tab->getDisplayData());
		$this->assertTrue($tab->isVisible());
	}

	public function testRootTabUsesOwningTranslationServiceForLabelAndDescription(): void {
		$calls = [];
		$translation = $this->createStub(ITranslation::class);
		$translation->method('translate')->willReturnCallback(static function(string $set, string $section, string $key) use (&$calls): string {
			$calls[] = [$set, $section, $key];
			return $key === 'label' ? 'Chatbot' : 'Chatbot settings';
		});
		$tab = new ChatbotSettingsTab($translation);
		$this->assertSame('Chatbot', $tab->getLabel());
		$this->assertSame('Chatbot settings', $tab->getDescription());
		$this->assertSame([
			['Settings', 'chatbotsettingstab', 'label'],
			['Settings', 'chatbotsettingstab', 'description']
		], $calls);
	}
}
