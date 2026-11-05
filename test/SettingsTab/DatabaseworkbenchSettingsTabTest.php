<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\SettingsTab;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Translation\Api\ITranslation;
use DatabaseWorkbench\Feature\DatabaseWorkbenchFeature;
use DatabaseWorkbench\SettingsTab\DatabaseworkbenchSettingsTab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseworkbenchSettingsTab::class)]
final class DatabaseworkbenchSettingsTabTest extends TestCase {

	public function testTabIdentityPlacementAndTranslations(): void {
		$translation = $this->createMock(ITranslation::class);
		$translation->expects(self::exactly(2))->method('translate')->willReturnCallback(
			static function(string $set, string $section, string $key): string {
				self::assertSame('Settings', $set);
				self::assertSame('databaseworkbenchsettingstab', $section);
				return $key;
			}
		);
		$tab = new DatabaseworkbenchSettingsTab($translation, $this->authorization(AuthorizationDecision::allow()));
		self::assertSame('databaseworkbenchsettingstab', $tab::getName());
		self::assertSame('base3settingstab', $tab->getParentTabName());
		self::assertSame('databaseworkbenchdisplay', $tab->getDisplayName());
		self::assertSame(70, $tab->getOrder());
		self::assertNull($tab->getDisplayData());
		self::assertSame('label', $tab->getLabel());
		self::assertSame('description', $tab->getDescription());
	}

	public function testTabVisibilityRequiresAnExplicitAllowedFeatureDecision(): void {
		foreach ([
			[AuthorizationDecision::allow(), true],
			[AuthorizationDecision::deny(), false],
			[AuthorizationDecision::indeterminate(), false],
		] as [$decision, $expected]) {
			$tab = new DatabaseworkbenchSettingsTab($this->createStub(ITranslation::class), $this->authorization($decision));
			self::assertSame($expected, $tab->isVisible());
		}
	}

	public function testTabDoesNotSilentlyAllowAuthorizationExceptions(): void {
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willThrowException(new RuntimeException('authorization unavailable'));
		$tab = new DatabaseworkbenchSettingsTab($this->createStub(ITranslation::class), $authorization);
		$this->expectException(RuntimeException::class);
		$tab->isVisible();
	}

	private function authorization(AuthorizationDecision $decision): IAuthorizationService {
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturnCallback(static function(AuthorizationRequest $request) use ($decision): AuthorizationDecision {
			self::assertSame('host', $request->authority);
			self::assertSame('feature', $request->permission->scope);
			self::assertSame(DatabaseWorkbenchFeature::getName(), $request->permission->permission);
			return $decision;
		});
		return $authorization;
	}
}
