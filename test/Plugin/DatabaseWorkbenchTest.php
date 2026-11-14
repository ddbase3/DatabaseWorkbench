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

namespace Base3\DatabaseWorkbench\Test\Plugin;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Session\Api\ISession;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use Base3\DatabaseWorkbench\DatabaseWorkbench;
use Base3\DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Service\DatabaseWorkbenchService;
use Base3\DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseWorkbench::class)]
final class DatabaseWorkbenchTest extends TestCase {

	public function testPluginRegistersItsTwoOwnedServicesAndItselfUsingExpectedFlags(): void {
		$registrations = [];
		$container = $this->createMock(IContainer::class);
		$container->expects(self::exactly(3))->method('set')->willReturnCallback(
			static function(string $name, mixed $definition, int $flags) use (&$registrations, $container): IContainer {
				$registrations[$name] = ['definition' => $definition, 'flags' => $flags];
				return $container;
			}
		);
		$plugin = new DatabaseWorkbench($container);

		self::assertInstanceOf(IPlugin::class, $plugin);
		self::assertSame('databaseworkbench', DatabaseWorkbench::getName());
		$plugin->init();

		self::assertSame([
			IDatabaseWorkbenchService::class,
			IDatabaseWorkbenchCsrf::class,
			'databaseworkbench',
		], array_keys($registrations));
		self::assertSame(IContainer::SHARED, $registrations[IDatabaseWorkbenchService::class]['flags']);
		self::assertSame(IContainer::SHARED | IContainer::NOOVERWRITE, $registrations[IDatabaseWorkbenchCsrf::class]['flags']);
		self::assertSame(IContainer::SHARED, $registrations['databaseworkbench']['flags']);
		self::assertSame($plugin, $registrations['databaseworkbench']['definition']);

		$database = WorkbenchFakeDatabase::withPeople();
		$session = $this->createStub(ISession::class);
		$container->method('get')->willReturnCallback(static function(string $name) use ($database, $session): mixed {
			return match ($name) {
				IDatabase::class => $database,
				ISession::class => $session,
			};
		});
		self::assertInstanceOf(
			DatabaseWorkbenchService::class,
			$registrations[IDatabaseWorkbenchService::class]['definition']($container)
		);
		self::assertInstanceOf(
			SessionDatabaseWorkbenchCsrf::class,
			$registrations[IDatabaseWorkbenchCsrf::class]['definition']($container)
		);
	}
}
