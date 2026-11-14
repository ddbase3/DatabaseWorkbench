<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of DatabaseWorkbench for BASE3 Framework.
 *
 * DatabaseWorkbench provides embedded MySQL and MariaDB administration.
 * It supports browsing, schema inspection, row changes and SQL queries.
 * Server-side operations use a dedicated authorized JSON endpoint.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 * https://github.com/ddbase3/DatabaseWorkbench
 **********************************************************************/

namespace Base3\DatabaseWorkbench\Test\Api;

use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use Base3\DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Service\DatabaseWorkbenchService;
use Base3\DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use Base3\CoreFoundation\Session\Api\ISession;
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
