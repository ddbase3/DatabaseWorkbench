<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Api;

use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Service\DatabaseWorkbenchService;
use DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use Base3\Session\Api\ISession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DatabaseWorkbenchContractsTest extends TestCase {

	public function testDatabaseServiceDeclaresAllPublicWorkbenchOperations(): void {
		$reflection = new ReflectionClass(IDatabaseWorkbenchService::class);
		self::assertTrue($reflection->isInterface());
		$names = array_map(static fn($method): string => $method->getName(), $reflection->getMethods());
		self::assertSame([
			'getOverview', 'getTables', 'getStructure', 'browse', 'insert', 'update',
			'delete', 'truncate', 'drop', 'executeSql', 'exportTable',
		], $names);
		self::assertInstanceOf(IDatabaseWorkbenchService::class, new DatabaseWorkbenchService(new WorkbenchFakeDatabase()));
	}

	public function testCsrfContractIsImplementedByTheSessionOwnedService(): void {
		$reflection = new ReflectionClass(IDatabaseWorkbenchCsrf::class);
		self::assertTrue($reflection->isInterface());
		$names = array_map(static fn($method): string => $method->getName(), $reflection->getMethods());
		self::assertSame(['issueToken', 'isValid', 'assertValid'], $names);
		self::assertInstanceOf(
			IDatabaseWorkbenchCsrf::class,
			new SessionDatabaseWorkbenchCsrf($this->createStub(ISession::class))
		);
	}
}
