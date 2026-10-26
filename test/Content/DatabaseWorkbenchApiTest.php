<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Content;

use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Translation\Api\ITranslation;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use DatabaseWorkbench\Content\DatabaseWorkbenchApi;
use DatabaseWorkbench\Feature\DatabaseWorkbenchFeature;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseWorkbenchApiTest extends TestCase {

	protected function tearDown(): void {
		http_response_code(200);
		parent::tearDown();
	}

	public function testUnauthorizedDecisionsRejectRequestsBeforeReadingInputOrCallingCsrf(): void {
		foreach ([AuthorizationDecision::deny(), AuthorizationDecision::indeterminate()] as $decision) {
			$request = $this->createMock(IRequest::class);
			$request->expects(self::never())->method('getJsonBody');
			$request->expects(self::never())->method('allPost');
			$service = $this->createMock(IDatabaseWorkbenchService::class);
			$service->expects(self::never())->method('getTables');
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::never())->method('assertValid');

			$api = $this->createApi($decision, $request, $service, $csrf);
			self::assertSame(['ok' => false, 'error' => 'access_denied'], json_decode($api->getOutput(), true));
			self::assertSame(403, http_response_code());
		}
	}

	public function testAuthorizationFailureIsClosedWithoutExposingInternalException(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects(self::never())->method('getJsonBody');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::never())->method('assertValid');
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('ilias');
		$authorization->method('decide')->willThrowException(new RuntimeException('internal details'));

		$api = $this->createApi(AuthorizationDecision::allow(), $request, $this->createMock(IDatabaseWorkbenchService::class), $csrf, $authorization);
		self::assertSame(['ok' => false, 'error' => 'access_denied'], json_decode($api->getOutput(), true));
		self::assertSame(403, http_response_code());
	}

	public function testAllowedRequestPassesCsrfValidationAndCallsItsAction(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getJsonBody')->willReturn(['csrf' => 'valid', 'action' => 'tables']);
		$service = $this->createMock(IDatabaseWorkbenchService::class);
		$service->expects(self::once())->method('getTables')->willReturn(['audit']);
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::once())->method('assertValid')->with('valid');

		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
		self::assertSame(['ok' => true, 'data' => ['tables' => ['audit']]], json_decode($api->getOutput(), true));
	}

	public function testAnonymousUserCannotCallApi(): void {
		$usermanager = $this->createMock(IUsermanager::class);
		$usermanager->method('getUser')->willReturn(null);
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->expects(self::never())->method('decide');
		$request = $this->createMock(IRequest::class);
		$request->expects(self::never())->method('getJsonBody');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::never())->method('assertValid');
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $this->createMock(IDatabaseWorkbenchService::class), $csrf, $authorization, $usermanager);

		self::assertSame(['ok' => false, 'error' => 'authentication_required'], json_decode($api->getOutput(), true));
		self::assertSame(403, http_response_code());
	}

	private function createApi(
		AuthorizationDecision $decision,
		IRequest $request,
		IDatabaseWorkbenchService $service,
		IDatabaseWorkbenchCsrf $csrf,
		?IAuthorizationService $authorization = null,
		?IUsermanager $usermanager = null
	): DatabaseWorkbenchApi {
		if ($usermanager === null) {
			$usermanager = $this->createMock(IUsermanager::class);
			$usermanager->method('getUser')->willReturn(User::fromArray(['id' => 7]));
		}
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
		$translation = $this->createMock(ITranslation::class);
		$translation->method('translate')->willReturnCallback(static function(string $set, string $section, string $key): string {
			self::assertSame('Display', $set);
			self::assertSame('databaseworkbench_display', $section);
			return $key;
		});
		return new DatabaseWorkbenchApi($request, $service, $csrf, $usermanager, $authorization, $translation);
	}
}
