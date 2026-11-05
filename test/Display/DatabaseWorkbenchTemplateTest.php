<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Display;

use DatabaseWorkbench\Test\Support\WorkbenchTemplateRenderer;
use PHPUnit\Framework\TestCase;

final class DatabaseWorkbenchTemplateTest extends TestCase {

	public function testTemplateEncodesBrowserConfigAndEscapesHtmlAttributes(): void {
		$resolved = [];
		$html = (new WorkbenchTemplateRenderer())->render([
			'endpoint' => '/api?test=1&other=2',
			'csrf' => 'safe-csrf',
			'page_size' => 50,
			'title' => 'Workbench <admin> "quoted"',
			'translations' => ['asset_load_failed' => 'Could not load file'],
			'resolve' => static function(string $path) use (&$resolved): string {
				$resolved[] = $path;
				return '/asset?name=' . basename($path) . '&mode=light';
			},
		]);
		self::assertSame([
			'plugin/DatabaseWorkbench/assets/databaseworkbench/databaseworkbench.css',
			'plugin/DatabaseWorkbench/assets/databaseworkbench/databaseworkbench.js',
		], $resolved);
		self::assertStringContainsString('data-title="Workbench &lt;admin&gt; &quot;quoted&quot;"', $html);
		self::assertStringContainsString('databaseworkbench.css&amp;mode=light', $html);
		self::assertMatchesRegularExpression('/id="databaseworkbench_[0-9a-f]{12}"/', $html);
		self::assertSame(1, preg_match('/var config = (\{[^;]+\});/', $html, $match));
		$config = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('/api?test=1&other=2', $config['endpoint']);
		self::assertSame('safe-csrf', $config['csrf']);
		self::assertSame(50, $config['pageSize']);
		self::assertSame(['asset_load_failed' => 'Could not load file'], $config['strings']);
		self::assertMatchesRegularExpression('/^databaseworkbench_[0-9a-f]{12}$/', $config['containerId']);
	}

	public function testTemplateHandlesMissingTranslationsWithoutWarnings(): void {
		$html = (new WorkbenchTemplateRenderer())->render([
			'endpoint' => '/api', 'csrf' => 'token', 'page_size' => 1,
			'title' => 'SQL Workbench', 'translations' => null,
			'resolve' => static fn(string $path): string => $path,
		]);
		self::assertSame(1, preg_match('/var config = (\{[^;]+\});/', $html, $match));
		self::assertSame([], json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['strings']);
		self::assertStringContainsString('Unable to load DatabaseWorkbench assets.', $html);
	}
}
