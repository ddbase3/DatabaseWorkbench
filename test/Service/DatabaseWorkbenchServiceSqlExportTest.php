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

namespace Base3\DatabaseWorkbench\Test\Service;

use Base3\DatabaseWorkbench\Exception\DatabaseWorkbenchException;
use Base3\DatabaseWorkbench\Service\DatabaseWorkbenchService;
use Base3\DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseWorkbenchService::class)]
final class DatabaseWorkbenchServiceSqlExportTest extends TestCase {

	public function testSelectShowDescribeDescExplainAndWithSelectReturnRows(): void {
		foreach ([
			'SELECT * FROM people', 'SHOW TABLES', 'DESCRIBE people', 'DESC people',
			'EXPLAIN SELECT * FROM people', 'WITH x AS (SELECT 1) SELECT * FROM x',
		] as $sql) {
			$db = new WorkbenchFakeDatabase();
			$db->multi[$sql] = [['name' => "\xFF", 'number' => 5]];
			$result = (new DatabaseWorkbenchService($db))->executeSql("  $sql  ");
			self::assertSame('rows', $result['type'], $sql);
			self::assertSame(1, $result['row_count'], $sql);
			self::assertFalse($result['truncated'], $sql);
			self::assertSame(['name' => '[binary 1 bytes]', 'number' => 5], $result['rows'][0], $sql);
			self::assertContains(['multi', $sql], $db->queries);
		}
	}

	public function testSelectTruncatesAtFiveHundredRows(): void {
		$db = new WorkbenchFakeDatabase();
		$db->multi['SELECT * FROM people'] = array_fill(0, 501, ['id' => 1]);
		$result = (new DatabaseWorkbenchService($db))->executeSql('SELECT * FROM people');
		self::assertSame('rows', $result['type']);
		self::assertSame(500, $result['row_count']);
		self::assertCount(500, $result['rows']);
		self::assertTrue($result['truncated']);
	}

	public function testMutationSqlAndNonselectWithStatementsExecuteNonquery(): void {
		foreach (['UPDATE people SET active=1', 'WITH x AS (VALUES (1)) UPDATE people SET active=1'] as $sql) {
			$db = new WorkbenchFakeDatabase();
			$db->affected = 3;
			$db->inserted = 22;
			self::assertSame([
				'type' => 'mutation', 'affected_rows' => 3, 'insert_id' => 22,
			], (new DatabaseWorkbenchService($db))->executeSql($sql));
			self::assertContains(['non', $sql], $db->queries);
		}
	}

	public function testInsertSelectFromJoinedTableExecutesAsMutation(): void {
		$sql = "INSERT INTO base3system_systag (entry_id, tag)\n"
			. "SELECT f.id, 'dancephotography_photo_checklogo'\n"
			. "FROM base3system_entryfile f\n"
			. "INNER JOIN base3system_systag t ON f.id = t.entry_id\n"
			. "WHERE t.tag = 'dancephotographyphoto';";
		$db = new WorkbenchFakeDatabase();
		$db->affected = 12;
		$result = (new DatabaseWorkbenchService($db))->executeSql($sql);

		self::assertSame(['type' => 'mutation', 'affected_rows' => 12, 'insert_id' => 0], $result);
		self::assertSame([['non', $sql]], $db->queries);
	}

	public function testInsertSelectReportsOriginalDatabaseError(): void {
		$sql = "INSERT INTO base3system_systag (entry_id, tag) SELECT id, 'new' FROM source_tags";
		$db = new WorkbenchFakeDatabase();
		$db->error = true;
		$db->errorCode = 1062;
		$db->errorText = "Duplicate entry '3-new' for key 'PRIMARY'";

		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage("Database error 1062: Duplicate entry '3-new' for key 'PRIMARY'");
		(new DatabaseWorkbenchService($db))->executeSql($sql);
	}

	public function testSqlRejectsEmptyStatementsBeforeQueryExecution(): void {
		$db = new WorkbenchFakeDatabase();
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('SQL must not be empty.');
		(new DatabaseWorkbenchService($db))->executeSql("  \t  ");
	}

	public function testSqlReportsBackendErrorsForBothResultAndMutationQueries(): void {
		foreach (['SELECT 1', 'UPDATE people SET active=1'] as $sql) {
			$db = new WorkbenchFakeDatabase();
			$db->error = true;
			$db->errorCode = 123;
			$db->errorText = 'read-only backend';
			try {
				(new DatabaseWorkbenchService($db))->executeSql($sql);
				self::fail('Expected backend failure for ' . $sql);
			} catch (DatabaseWorkbenchException $e) {
				self::assertSame('Database error 123: read-only backend', $e->getMessage());
			}
		}
	}

	public function testExportContainsCreateStatementAndTypedInsertValues(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->scalar['SELECT COUNT(*) FROM `people`'] = 2;
		$db->multi['SELECT * FROM `people` LIMIT 5000'] = [
			['id' => 1, 'name' => "O'Reilly", 'comment' => null, 'enabled' => true, 'ratio' => 1.5],
			['id' => 2, 'name' => 'other', 'comment' => null, 'enabled' => false, 'ratio' => 0],
		];
		$result = (new DatabaseWorkbenchService($db))->exportTable('people');
		self::assertMatchesRegularExpression('/^people-\d{8}-\d{6}\.sql$/', $result['filename']);
		self::assertSame(2, $result['total_rows']);
		self::assertSame(2, $result['exported_rows']);
		self::assertFalse($result['truncated']);
		self::assertStringContainsString('DROP TABLE IF EXISTS `people`;', $result['content']);
		self::assertStringContainsString('CREATE TABLE `people` (`id` int, `name` varchar(100));', $result['content']);
		self::assertStringContainsString("(1, 'O\\'Reilly', NULL, 1, 1.5);", $result['content']);
		self::assertStringContainsString("(2, 'other', NULL, 0, 0);", $result['content']);
		self::assertStringEndsWith("\n", $result['content']);
	}

	public function testExportReportsTruncationAndSupportsEmptyResultSets(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->scalar['SELECT COUNT(*) FROM `people`'] = 5001;
		$db->multi['SELECT * FROM `people` LIMIT 5000'] = [['id' => 1]];
		$result = (new DatabaseWorkbenchService($db))->exportTable('people');
		self::assertTrue($result['truncated']);
		self::assertSame(5001, $result['total_rows']);
		self::assertSame(1, $result['exported_rows']);
		self::assertStringContainsString('-- Export truncated after 5000 rows of 5001.', $result['content']);

		$db->scalar['SELECT COUNT(*) FROM `people`'] = null;
		$db->multi['SELECT * FROM `people` LIMIT 5000'] = [];
		$result = (new DatabaseWorkbenchService($db))->exportTable('people');
		self::assertSame(0, $result['total_rows']);
		self::assertSame(0, $result['exported_rows']);
		self::assertFalse($result['truncated']);
		self::assertStringNotContainsString('INSERT INTO', $result['content']);
	}

	public function testExportRequiresCreateSql(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->single['SHOW CREATE TABLE `people`'] = [];
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('Unable to read the CREATE TABLE statement.');
		(new DatabaseWorkbenchService($db))->exportTable('people');
	}
}
