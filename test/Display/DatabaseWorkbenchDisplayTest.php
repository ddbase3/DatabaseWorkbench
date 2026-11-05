<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IMvcView;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Display\DatabaseWorkbenchDisplay;
use DatabaseWorkbench\Feature\DatabaseWorkbenchFeature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseWorkbenchDisplay::class)]
final class DatabaseWorkbenchDisplayTest extends TestCase {

	public function testDeniedAndIndeterminateDecisionsDoNotRenderOrIssueToken(): void {
		foreach ([AuthorizationDecision::deny(), AuthorizationDecision::indeterminate()] as $decision) {
			$view = $this->createView();
			$view->expects(self::never())->method('loadTemplate');
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::never())->method('issueToken');
			$display = $this->createDisplay($decision, $view, $csrf);

			self::assertSame('<div class="databaseworkbench-error" role="alert">Not permitted &amp; restricted</div>', $display->getOutput());
		}
	}

	public function testAuthorizationFailureDeniesRendering(): void {
		$view = $this->createView();
		$view->expects(self::never())->method('loadTemplate');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::never())->method('issueToken');
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('ilias');
		$authorization->method('decide')->willThrowException(new RuntimeException('authority offline'));
		$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf, $authorization);

		self::assertStringContainsString('Not permitted &amp; restricted', $display->getOutput());
	}

	public function testAllowedRequestIssuesTokenAndRendersWorkbench(): void {
		$view = $this->createView();
		$view->expects(self::once())->method('loadTemplate')->willReturn('rendered workbench');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::once())->method('issueToken')->willReturn('token');
		$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf);

		self::assertSame('rendered workbench', $display->getOutput());
	}

	public function testAnonymousAndInvalidUserIdsFailBeforeAuthorizationOrCsrf(): void {
		foreach ([null, 0, -1, '', ' ', '0', ' 0 ', 1.5, []] as $id) {
			$usermanager = $this->createMock(IUsermanager::class);
			$usermanager->method('getUser')->willReturn($id === null ? null : User::fromArray(['id' => $id]));
			$view = $this->createView();
			$view->expects(self::never())->method('loadTemplate');
			$authorization = $this->createMock(IAuthorizationService::class);
			$authorization->expects(self::never())->method('decide');
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::never())->method('issueToken');
			$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf, $authorization, $usermanager);
			self::assertSame('<div class="databaseworkbench-error" role="alert"></div>', $display->getOutput());
		}
	}

	public function testAuthenticatedStringIdentifiersAreAccepted(): void {
		foreach (['8', ' 8 ', '00'] as $id) {
			$usermanager = $this->createMock(IUsermanager::class);
			$usermanager->method('getUser')->willReturn(User::fromArray(['id' => $id]));
			$view = $this->createView();
			$view->method('loadTemplate')->willReturn('allowed');
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::once())->method('issueToken')->willReturn('csrf');
			$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf, null, $usermanager);
			self::assertSame('allowed', $display->getOutput());
		}
	}

	public function testCsrfStartupFailureDoesNotRenderWorkbench(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(['session_required' => 'Session <expired>']);
		$view->expects(self::never())->method('loadTemplate');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->method('issueToken')->willThrowException(new RuntimeException('internal session error'));
		$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf);
		self::assertSame(
			'<div class="databaseworkbench-error" role="alert">Session &lt;expired&gt;</div>',
			$display->getOutput()
		);
	}

	public function testNullTranslationBricksCannotBypassAuthorization(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(null);
		$view->expects(self::never())->method('loadTemplate');
		$display = $this->createDisplay(
			AuthorizationDecision::deny(), $view, $this->createMock(IDatabaseWorkbenchCsrf::class)
		);
		self::assertSame('<div class="databaseworkbench-error" role="alert"></div>', $display->getOutput());
	}

	public function testAllowedOutputSuppliesResolvedLinksTranslationsAndDataToTemplate(): void {
		$view = $this->createMock(IMvcView::class);
		$view->expects(self::once())->method('setPath')->with(dirname(__DIR__, 2));
		$view->expects(self::once())->method('loadBricks')->with('Display');
		$view->method('getBricks')->willReturn(['title' => 'Localized title', 'access_denied' => 'Denied']);
		$view->expects(self::once())->method('setTemplate')->with('Display/DatabaseWorkbenchDisplay.php');
		$assigned = [];
		$view->expects(self::exactly(6))->method('assign')->willReturnCallback(
			static function(string $name, mixed $value) use (&$assigned): void {
				$assigned[$name] = $value;
			}
		);
		$view->expects(self::once())->method('loadTemplate')->willReturn('rendered custom workbench');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->method('issueToken')->willReturn('known-token');
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects(self::once())->method('getLink')->with([
			'name' => 'databaseworkbenchapi', 'out' => 'json',
		])->willReturn('/api/workbench');
		$assets = $this->createMock(IAssetResolver::class);
		$assets->expects(self::once())->method('resolve')->with('css/file.css')->willReturn('/resolved/file.css');
		$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf, null, null, $assets, $links);
		$display->setData(['title' => 'My Workbench', 'page_size' => 300]);

		self::assertSame('databaseworkbenchdisplay', DatabaseWorkbenchDisplay::getName());
		self::assertSame('rendered custom workbench', $display->getOutput('html', true));
		self::assertSame('My Workbench', $assigned['title']);
		self::assertSame(200, $assigned['page_size']);
		self::assertSame('/api/workbench', $assigned['endpoint']);
		self::assertSame('known-token', $assigned['csrf']);
		self::assertSame('Localized title', $assigned['translations']['title']);
		self::assertSame('/resolved/file.css', $assigned['resolve']('css/file.css'));
	}

	public function testDataResetAndPageSizeMinimumUseExistingViewDefaults(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(['title' => '   ']);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		$view->method('loadTemplate')->willReturn('done');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->method('issueToken')->willReturn('ok');
		$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf);
		$display->setData(['title' => 'removed', 'page_size' => -11]);
		self::assertSame('done', $display->getOutput());
		self::assertSame(1, $assigned['page_size']);
		self::assertSame('removed', $assigned['title']);

		$display->setData('not an array');
		self::assertSame('done', $display->getOutput());
		self::assertSame(50, $assigned['page_size']);
		self::assertSame('Database Workbench', $assigned['title']);
	}

	public function testTranslatedDefaultTitleAndConfiguredPageSizeAreUsed(): void {
		$view = $this->createView();
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		$view->method('loadTemplate')->willReturn('ok');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->method('issueToken')->willReturn('ok');
		$display = $this->createDisplay(AuthorizationDecision::allow(), $view, $csrf);
		$display->setData(['page_size' => '32']);
		self::assertSame('ok', $display->getOutput());
		self::assertSame('Database Workbench', $assigned['title']);
		self::assertSame(32, $assigned['page_size']);
	}

	private function createView(): IMvcView {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->with('databaseworkbench_display')->willReturn([
			'access_denied' => 'Not permitted & restricted',
			'title' => 'Database Workbench',
		]);
		return $view;
	}

	private function createDisplay(
		AuthorizationDecision $decision,
		IMvcView $view,
		IDatabaseWorkbenchCsrf $csrf,
		?IAuthorizationService $authorization = null,
		?IUsermanager $usermanager = null,
		?IAssetResolver $assets = null,
		?ILinkTargetService $links = null
	): DatabaseWorkbenchDisplay {
		if ($authorization === null) {
			$authorization = $this->createMock(IAuthorizationService::class);
			$authorization->method('getDefaultAuthority')->willReturn('ilias');
			$authorization->method('decide')->willReturnCallback(static function(AuthorizationRequest $request) use ($decision): AuthorizationDecision {
				self::assertSame('ilias', $request->authority);
				self::assertSame('feature', $request->permission->scope);
				self::assertSame(DatabaseWorkbenchFeature::getName(), $request->permission->permission);
				return $decision;
			});
		}
		if ($usermanager === null) {
			$usermanager = $this->createMock(IUsermanager::class);
			$usermanager->method('getUser')->willReturn(User::fromArray(['id' => 7]));
		}
		if ($links === null) {
			$links = $this->createMock(ILinkTargetService::class);
			$links->method('getLink')->willReturn('index.php?name=databaseworkbenchapi');
		}
		return new DatabaseWorkbenchDisplay(
			$view,
			$assets ?? $this->createMock(IAssetResolver::class),
			$links,
			$csrf,
			$usermanager,
			$authorization
		);
	}
}
