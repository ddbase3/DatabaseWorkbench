<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Service;

use DatabaseWorkbench\Exception\DatabaseWorkbenchException;
use DatabaseWorkbench\Service\DatabaseWorkbenchService;
use DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseWorkbenchService::class)]
final class DatabaseWorkbenchServiceDiscoveryTest extends TestCase {

	public function testOverviewReadsDatabaseMetadataAndCountsTables(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->scalar['SELECT DATABASE()'] = 'reporting';
		$db->scalar['SELECT VERSION()'] = '11.4.0-MariaDB';
		$db->single["SHOW VARIABLES LIKE 'character_set_database'"] = ['Value' => 'utf8mb4'];
		$db->single["SHOW VARIABLES LIKE 'collation_database'"] = ['Value' => 'utf8mb4_unicode_ci'];

		self::assertSame([
			'database' => 'reporting',
			'version' => '11.4.0-MariaDB',
			'charset' => 'utf8mb4',
			'collation' => 'utf8mb4_unicode_ci',
			'tables' => 1,
		], (new DatabaseWorkbenchService($db))->getOverview());
		self::assertGreaterThanOrEqual(2, $db->connectCalls);
	}

	public function testOverviewToleratesAbsentOptionalMetadata(): void {
		$db = new WorkbenchFakeDatabase();
		self::assertSame([
			'database' => '', 'version' => '', 'charset' => '', 'collation' => '', 'tables' => 0,
		], (new DatabaseWorkbenchService($db))->getOverview());
	}

	public function testTableListingNormalizesMissingStatusFields(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi['SHOW TABLE STATUS'][] = ['Name' => 'empty'];
		self::assertSame([
			'name' => 'people', 'engine' => 'InnoDB', 'rows' => 2,
			'data_length' => 1024, 'index_length' => 128,
			'collation' => 'utf8mb4_unicode_ci', 'comment' => 'persons',
		], (new DatabaseWorkbenchService($db))->getTables()[0]);
		self::assertSame([
			'name' => 'empty', 'engine' => '', 'rows' => 0,
			'data_length' => 0, 'index_length' => 0, 'collation' => '', 'comment' => '',
		], (new DatabaseWorkbenchService($db))->getTables()[1]);
	}

	public function testOperationsFailWhenConnectionIsUnavailable(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->available = false;
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('The configured database connection is not available.');
		(new DatabaseWorkbenchService($db))->getTables();
	}

	public function testEmptyAndUnknownTableNamesAreRejected(): void {
		foreach (['  ', 'missing'] as $table) {
			$db = WorkbenchFakeDatabase::withPeople();
			try {
				(new DatabaseWorkbenchService($db))->getStructure($table);
				self::fail('Expected a rejected table name.');
			} catch (DatabaseWorkbenchException $e) {
				self::assertSame(
					trim($table) === '' ? 'Table name is required.' : 'Unknown table: missing',
					$e->getMessage()
				);
			}
		}
	}

	public function testStructureReadsColumnIndexAndCreateSqlIncludingInvalidUtf8(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi['SHOW FULL COLUMNS FROM `people`'][1]['Comment'] = "\xFF";
		$db->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [
			['Column_name' => '', 'Seq_in_index' => '9'],
			['Column_name' => 'second', 'Seq_in_index' => '2'],
			['Column_name' => 'id', 'Seq_in_index' => '1'],
		];
		$structure = (new DatabaseWorkbenchService($db))->getStructure(' people ');

		self::assertSame('people', $structure['table']);
		self::assertSame(['id', 'second'], $structure['primary_key']);
		self::assertSame('[binary 1 bytes]', $structure['columns'][1]['Comment']);
		self::assertSame('CREATE TABLE `people` (`id` int, `name` varchar(100))', $structure['create_sql']);
		self::assertSame('PRIMARY', $structure['indexes'][0]['Key_name']);
	}

	public function testStructureThrowsIfShowCreateTableHasNoStatement(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->single['SHOW CREATE TABLE `people`'] = ['Other' => 'unknown'];
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('Unable to read the CREATE TABLE statement.');
		(new DatabaseWorkbenchService($db))->getStructure('people');
	}

	public function testIdentifierQuotingEscapesBackticksWithoutAcceptingUnknownTables(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi['SHOW TABLE STATUS'][0]['Name'] = 'odd`table';
		$db->multi['SHOW FULL COLUMNS FROM `odd``table`'] = [['Field' => 'id']];
		$db->multi['SHOW INDEX FROM `odd``table`'] = [];
		$db->multi["SHOW INDEX FROM `odd``table` WHERE Key_name = 'PRIMARY'"] = [];
		$db->single['SHOW CREATE TABLE `odd``table`'] = ['Create Table' => 'CREATE TABLE `odd``table` (`id` int)'];

		self::assertSame('CREATE TABLE `odd``table` (`id` int)', (new DatabaseWorkbenchService($db))->getStructure('odd`table')['create_sql']);
		self::assertContains(['multi', 'SHOW FULL COLUMNS FROM `odd``table`'], $db->queries);
	}
}
