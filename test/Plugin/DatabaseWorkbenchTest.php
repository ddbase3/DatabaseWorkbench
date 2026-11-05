<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Plugin;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Database\Api\IDatabase;
use Base3\Session\Api\ISession;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use DatabaseWorkbench\DatabaseWorkbench;
use DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Service\DatabaseWorkbenchService;
use DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
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
