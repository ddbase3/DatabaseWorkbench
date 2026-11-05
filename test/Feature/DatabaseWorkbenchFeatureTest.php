<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Feature;

use Base3\Authorization\Api\IFeature;
use Base3\Translation\Api\ITranslation;
use DatabaseWorkbench\Feature\DatabaseWorkbenchFeature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseWorkbenchFeature::class)]
final class DatabaseWorkbenchFeatureTest extends TestCase {

	public function testDeclaresOneUnassignedWorkbenchPermission(): void {
		$feature = new DatabaseWorkbenchFeature($this->createStub(ITranslation::class));
		$permissions = $feature->getPermissions();

		self::assertInstanceOf(IFeature::class, $feature);
		self::assertSame('databaseworkbenchfeature', DatabaseWorkbenchFeature::getName());
		self::assertCount(1, $permissions);
		self::assertSame('feature', $permissions[0]->scope);
		self::assertSame(DatabaseWorkbenchFeature::getName(), $permissions[0]->permission);
		self::assertSame([], $feature->getDefaultAudience());
	}

	public function testFeatureMetadataUsesPluginOwnedTranslations(): void {
		$translation = $this->createMock(ITranslation::class);
		$translation->expects(self::exactly(2))->method('translate')->willReturnCallback(
			static function(string $set, string $section, string $key, string $fallback = ''): string {
				self::assertSame('Administration', $set);
				self::assertSame('feature', $section);
				self::assertSame('', $fallback);
				return $key;
			}
		);
		$feature = new DatabaseWorkbenchFeature($translation);

		self::assertSame('label_databaseworkbench', $feature->getLabel());
		self::assertSame('description_databaseworkbench', $feature->getDescription());
	}

	public function testEverySupportedLanguageContainsSecurityAndFeatureTexts(): void {
		$root = dirname(__DIR__, 2);
		$languages = array_map(
			static fn(string $file): string => basename($file, '.ini'),
			glob($root . '/lang/Display/*.ini') ?: []
		);
		self::assertNotEmpty($languages);

		foreach ($languages as $language) {
			$administration = parse_ini_file($root . '/lang/Administration/' . $language . '.ini', true, INI_SCANNER_RAW);
			$display = parse_ini_file($root . '/lang/Display/' . $language . '.ini', true, INI_SCANNER_RAW);
			self::assertIsArray($administration, $language);
			self::assertIsArray($display, $language);
			foreach (['label_databaseworkbench', 'description_databaseworkbench'] as $key) {
				self::assertNotEmpty($administration['feature'][$key] ?? '', $language . '/' . $key);
			}
			foreach (['access_denied', 'authentication_required', 'session_required'] as $key) {
				self::assertNotEmpty($display['databaseworkbench_display'][$key] ?? '', $language . '/' . $key);
			}
		}
	}
}
