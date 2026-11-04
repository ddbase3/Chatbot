<?php declare(strict_types=1);

namespace Chatbot\Test\SettingsTab;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Translation\Api\ITranslation;
use Chatbot\SettingsTab\ChatbotconfigSettingsTab;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatbotconfigSettingsTabAuthorizationTest extends TestCase {

	public function testFeatureTabHasOneCanonicalPermissionAndParent(): void {
		$tab = $this->newTabWithDecision(AuthorizationDecision::deny());
		$this->assertSame('chatbotconfigsettingstab', ChatbotconfigSettingsTab::getName());
		$this->assertSame('chatbotsettingstab', $tab->getParentTabName());
		$this->assertSame('chatbotconfigdisplay', $tab->getDisplayName());
		$this->assertSame(10, $tab->getOrder());
		$this->assertNull($tab->getDisplayData());
		$this->assertSame(['group' => 'administration'], $tab->getDefaultAudience());
		$this->assertCount(1, $tab->getPermissions());
		$this->assertSame('feature', $tab->getPermissions()[0]->scope);
		$this->assertSame('chatbotconfigsettingstab', $tab->getPermissions()[0]->permission);
	}

	public function testOnlyExplicitAllowShowsConfigurationTab(): void {
		foreach ([AuthorizationDecision::allow(), AuthorizationDecision::deny(), AuthorizationDecision::indeterminate()] as $decision) {
			$tab = $this->newTabWithDecision($decision);
			$this->assertSame($decision->isAllowed(), $tab->isVisible());
		}
	}

	public function testDecisionIsBoundToHostAuthorityAndOwnFeaturePermission(): void {
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('ilias:course-server');
		$authorization->expects($this->once())->method('decide')->willReturnCallback(function(AuthorizationRequest $request): AuthorizationDecision {
			$this->assertSame('ilias:course-server', $request->authority);
			$this->assertSame('feature', $request->permission->scope);
			$this->assertSame(ChatbotconfigSettingsTab::getName(), $request->permission->permission);
			$this->assertNull($request->subject);
			$this->assertNull($request->resource);
			return AuthorizationDecision::allow();
		});
		$tab = new ChatbotconfigSettingsTab($this->createStub(ITranslation::class), $authorization);
		$this->assertTrue($tab->isVisible());
	}

	public function testMissingAuthorityFailsClosedWithoutDecision(): void {
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('');
		$authorization->expects($this->never())->method('decide');
		$tab = new ChatbotconfigSettingsTab($this->createStub(ITranslation::class), $authorization);
		$this->assertFalse($tab->isVisible());
	}

	public function testNativeAuthorityFailureFailsClosed(): void {
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturnCallback(static function(): never { throw new RuntimeException('Native authority unavailable'); });
		$tab = new ChatbotconfigSettingsTab($this->createStub(ITranslation::class), $authorization);
		$this->assertFalse($tab->isVisible());
	}

	public function testFeatureLabelAndDescriptionUseItsOwnTranslationKeys(): void {
		$requests = [];
		$translation = $this->createStub(ITranslation::class);
		$translation->method('translate')->willReturnCallback(static function(string $set, string $section, string $key) use (&$requests): string {
			$requests[] = [$set, $section, $key];
			return $key;
		});
		$tab = new ChatbotconfigSettingsTab($translation, $this->createStub(IAuthorizationService::class));
		$this->assertSame('label', $tab->getLabel());
		$this->assertSame('description', $tab->getDescription());
		$this->assertSame([
			['Settings', 'chatbotconfigsettingstab', 'label'],
			['Settings', 'chatbotconfigsettingstab', 'description']
		], $requests);
	}

	private function newTabWithDecision(AuthorizationDecision $decision): ChatbotconfigSettingsTab {
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturn($decision);
		return new ChatbotconfigSettingsTab($this->createStub(ITranslation::class), $authorization);
	}
}
