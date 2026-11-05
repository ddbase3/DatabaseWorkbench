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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseWorkbenchApi::class)]
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
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('ilias');
		$authorization->method('decide')->willThrowException(new RuntimeException('internal details'));

		$api = $this->createApi(AuthorizationDecision::allow(), $request, $this->createStub(IDatabaseWorkbenchService::class), $csrf, $authorization);
		self::assertSame(['ok' => false, 'error' => 'access_denied'], json_decode($api->getOutput(), true));
		self::assertSame(403, http_response_code());
	}

	public function testAllowedRequestPassesCsrfValidationAndCallsItsAction(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn(['csrf' => 'valid', 'action' => 'tables']);
		$service = $this->createMock(IDatabaseWorkbenchService::class);
		$service->expects(self::once())->method('getTables')->willReturn(['audit']);
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::once())->method('assertValid')->with('valid');

		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
		self::assertSame(['ok' => true, 'data' => ['tables' => ['audit']]], json_decode($api->getOutput(), true));
	}

	public function testAnonymousUserCannotCallApi(): void {
		$usermanager = $this->createStub(IUsermanager::class);
		$usermanager->method('getUser')->willReturn(null);
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->expects(self::never())->method('decide');
		$request = $this->createMock(IRequest::class);
		$request->expects(self::never())->method('getJsonBody');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::never())->method('assertValid');
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $this->createStub(IDatabaseWorkbenchService::class), $csrf, $authorization, $usermanager);

		self::assertSame(['ok' => false, 'error' => 'authentication_required'], json_decode($api->getOutput(), true));
		self::assertSame(403, http_response_code());
	}

	public function testAllSupportedActionsDispatchThroughTheExistingServiceContract(): void {
		$cases = [
			['overview', 'getOverview', [], [], ['database' => 'example']],
			['tables', 'getTables', [], [], ['tables' => ['people']]],
			['structure', 'getStructure', ['table' => 123], ['123'], ['columns' => []]],
			['browse', 'browse', [
				'table' => 'people', 'page' => '3', 'page_size' => '25',
				'order_by' => 'name', 'order_direction' => 'DESC',
			], ['people', 3, 25, 'name', 'DESC'], ['rows' => []]],
			['insert', 'insert', [
				'table' => 'people', 'values' => ['name' => 'Test'],
				'null_columns' => [3, 'name', null, 'extra', false],
			], ['people', ['name' => 'Test'], ['name', 'extra']], ['insert_id' => 3]],
			['update', 'update', [
				'table' => 'people', 'key' => ['id' => 3], 'values' => ['name' => 'New'],
				'null_columns' => ['name'],
			], ['people', ['id' => 3], ['name' => 'New'], ['name']], ['affected_rows' => 1]],
			['delete', 'delete', ['table' => 'people', 'key' => ['id' => 3]], ['people', ['id' => 3]], ['affected_rows' => 1]],
			['truncate', 'truncate', ['table' => 'people'], ['people'], ['table' => 'people']],
			['drop', 'drop', ['table' => 'people'], ['people'], ['table' => 'people']],
			['sql', 'executeSql', ['sql' => 'SELECT 1'], ['SELECT 1'], ['type' => 'rows']],
			['export', 'exportTable', ['table' => 'people'], ['people'], ['filename' => 'people.sql']],
		];

		foreach ($cases as [$action, $method, $params, $expectedArgs, $expectedData]) {
			$request = $this->createMock(IRequest::class);
			$request->expects(self::once())->method('getJsonBody')->willReturn(array_merge([
				'csrf' => 'approved', 'action' => $action,
			], $params));
			$request->expects(self::never())->method('allPost');
			$service = $this->createMock(IDatabaseWorkbenchService::class);
			$invocation = $service->expects(self::once())->method($method);
			if ($expectedArgs !== []) $invocation = $invocation->with(...$expectedArgs);
			$invocation->willReturn($action === 'tables' ? ['people'] : $expectedData);
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::once())->method('assertValid')->with('approved');
			$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
			self::assertSame(['ok' => true, 'data' => $expectedData], json_decode($api->getOutput(), true), $action);
		}
	}

	public function testPostFallbackAndInvalidPayloadTypesUseDocumentedDefaults(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects(self::once())->method('getJsonBody')->willReturn([]);
		$request->expects(self::once())->method('allPost')->willReturn([
			'csrf' => 'post', 'action' => 'browse', 'table' => ['invalid'],
			'page' => 'not numeric', 'page_size' => [], 'order_by' => [], 'order_direction' => [],
		]);
		$service = $this->createMock(IDatabaseWorkbenchService::class);
		$service->expects(self::once())->method('browse')->with('', 1, 50, '', 'asc')->willReturn(['rows' => []]);
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::once())->method('assertValid')->with('post');
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
		self::assertSame(['ok' => true, 'data' => ['rows' => []]], json_decode($api->getOutput(), true));
	}

	public function testInvalidArrayValuesAreConvertedToEmptyArrays(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn([
			'csrf' => 'ok', 'action' => 'update', 'table' => 'people',
			'key' => 'not an array', 'values' => 23, 'null_columns' => 'not an array',
		]);
		$service = $this->createMock(IDatabaseWorkbenchService::class);
		$service->expects(self::once())->method('update')->with('people', [], [], [])->willReturn(['affected_rows' => 0]);
		$csrf = $this->createStub(IDatabaseWorkbenchCsrf::class);
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
		self::assertSame(['ok' => true, 'data' => ['affected_rows' => 0]], json_decode($api->getOutput(), true));
	}

	public function testUnsupportedOrMissingActionIsRejectedWithoutDatabaseAccess(): void {
		foreach (['no-such-action', '  '] as $action) {
			$request = $this->createStub(IRequest::class);
			$request->method('getJsonBody')->willReturn(['csrf' => 'ok', 'action' => $action]);
			$service = $this->createMock(IDatabaseWorkbenchService::class);
			$service->expects(self::never())->method('getOverview');
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::once())->method('assertValid')->with('ok');
			$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
			self::assertSame([
				'ok' => false, 'error' => 'Unknown DatabaseWorkbench action: ' . trim($action),
			], json_decode($api->getOutput(), true));
		}
	}

	public function testCsrfFailureIsReturnedAsAnErrorWithoutCallingDatabase(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn(['action' => 'tables']);
		$service = $this->createMock(IDatabaseWorkbenchService::class);
		$service->expects(self::never())->method('getTables');
		$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
		$csrf->expects(self::once())->method('assertValid')->with('')->willThrowException(new RuntimeException('invalid token'));
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $csrf);
		self::assertSame(['ok' => false, 'error' => 'invalid token'], json_decode($api->getOutput(), true));
	}

	public function testServiceExceptionsProduceErrorsRatherThanSuccessResponses(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn(['csrf' => 'ok', 'action' => 'tables']);
		$service = $this->createStub(IDatabaseWorkbenchService::class);
		$service->method('getTables')->willThrowException(new RuntimeException('database unavailable'));
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $this->createStub(IDatabaseWorkbenchCsrf::class));
		self::assertSame(['ok' => false, 'error' => 'database unavailable'], json_decode($api->getOutput(), true));
	}

	public function testAllInvalidUserIdentifiersDenyBeforeAuthorization(): void {
		foreach ([0, -7, null, '', ' ', '0', ' 0 ', 1.5, ['id']] as $id) {
			$usermanager = $this->createStub(IUsermanager::class);
			$usermanager->method('getUser')->willReturn(User::fromArray(['id' => $id]));
			$authorization = $this->createMock(IAuthorizationService::class);
			$authorization->expects(self::never())->method('decide');
			$request = $this->createMock(IRequest::class);
			$request->expects(self::never())->method('getJsonBody');
			$csrf = $this->createMock(IDatabaseWorkbenchCsrf::class);
			$csrf->expects(self::never())->method('assertValid');
			$api = $this->createApi(
				AuthorizationDecision::allow(), $request, $this->createStub(IDatabaseWorkbenchService::class),
				$csrf, $authorization, $usermanager
			);
			self::assertSame(['ok' => false, 'error' => 'authentication_required'], json_decode($api->getOutput(), true));
		}
	}

	public function testPositiveIntegerAndNonzeroStringIdsCanAccessAuthorizedService(): void {
		foreach ([1, ' 7 ', '00'] as $id) {
			$usermanager = $this->createStub(IUsermanager::class);
			$usermanager->method('getUser')->willReturn(User::fromArray(['id' => $id]));
			$request = $this->createStub(IRequest::class);
			$request->method('getJsonBody')->willReturn(['csrf' => 'ok', 'action' => 'tables']);
			$service = $this->createStub(IDatabaseWorkbenchService::class);
			$service->method('getTables')->willReturn([]);
			$api = $this->createApi(
				AuthorizationDecision::allow(), $request, $service, $this->createStub(IDatabaseWorkbenchCsrf::class),
				null, $usermanager
			);
			self::assertSame(['ok' => true, 'data' => ['tables' => []]], json_decode($api->getOutput(), true));
		}
	}

	public function testJsonEncoderSubstitutesInvalidUtf8InSuccessfulData(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn(['csrf' => 'ok', 'action' => 'overview']);
		$service = $this->createStub(IDatabaseWorkbenchService::class);
		$service->method('getOverview')->willReturn(['name' => "\xFF"]);
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $this->createStub(IDatabaseWorkbenchCsrf::class));
		self::assertSame(['ok' => true, 'data' => ['name' => "\u{FFFD}"]], json_decode($api->getOutput(), true));
	}

	public function testJsonEncoderReturnsErrorForRecursiveServiceResult(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getJsonBody')->willReturn(['csrf' => 'ok', 'action' => 'overview']);
		$recursive = [];
		$recursive['self'] = &$recursive;
		$service = $this->createStub(IDatabaseWorkbenchService::class);
		$service->method('getOverview')->willReturnCallback(static fn(): array => $recursive);
		$api = $this->createApi(AuthorizationDecision::allow(), $request, $service, $this->createStub(IDatabaseWorkbenchCsrf::class));
		self::assertSame(['ok' => false, 'error' => 'Unable to encode response.'], json_decode($api->getOutput(), true));
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
			$usermanager = $this->createStub(IUsermanager::class);
			$usermanager->method('getUser')->willReturn(User::fromArray(['id' => 7]));
		}
		if ($authorization === null) {
			$authorization = $this->createStub(IAuthorizationService::class);
			$authorization->method('getDefaultAuthority')->willReturn('ilias');
			$authorization->method('decide')->willReturnCallback(static function(AuthorizationRequest $request) use ($decision): AuthorizationDecision {
				self::assertSame('ilias', $request->authority);
				self::assertSame('feature', $request->permission->scope);
				self::assertSame(DatabaseWorkbenchFeature::getName(), $request->permission->permission);
				return $decision;
			});
		}
		$translation = $this->createStub(ITranslation::class);
		$translation->method('translate')->willReturnCallback(static function(string $set, string $section, string $key): string {
			self::assertSame('Display', $set);
			self::assertSame('databaseworkbench_display', $section);
			return $key;
		});
		return new DatabaseWorkbenchApi($request, $service, $csrf, $usermanager, $authorization, $translation);
	}
}
