<?php declare(strict_types=1);

namespace Chatbot\Test\SettingsTab;

use Base3\Translation\Api\ITranslation;
use Chatbot\SettingsTab\ChatbotSettingsTab;
use PHPUnit\Framework\TestCase;

final class ChatbotSettingsTabBehaviorTest extends TestCase {

	public function testTechnicalNameAndRootNavigationContract(): void {
		$tab = new ChatbotSettingsTab($this->createStub(ITranslation::class));
		$this->assertSame('chatbotsettingstab', ChatbotSettingsTab::getName());
		$this->assertSame(40, $tab->getOrder());
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
