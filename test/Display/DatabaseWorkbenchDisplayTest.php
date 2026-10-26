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
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
		?IAuthorizationService $authorization = null
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
		$usermanager = $this->createMock(IUsermanager::class);
		$usermanager->method('getUser')->willReturn(User::fromArray(['id' => 7]));
		$links = $this->createMock(ILinkTargetService::class);
		$links->method('getLink')->willReturn('index.php?name=databaseworkbenchapi');
		return new DatabaseWorkbenchDisplay(
			$view,
			$this->createMock(IAssetResolver::class),
			$links,
			$csrf,
			$usermanager,
			$authorization
		);
	}
}
