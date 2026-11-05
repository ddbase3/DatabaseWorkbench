<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Service;

use DatabaseWorkbench\Exception\DatabaseWorkbenchException;
use DatabaseWorkbench\Service\DatabaseWorkbenchService;
use DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseWorkbenchService::class)]
final class DatabaseWorkbenchServiceMutationTest extends TestCase {

	public function testInsertIgnoresUnknownGeneratedAndEmptyAutoincrementColumns(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->affected = 1;
		$db->inserted = '42';
		$result = (new DatabaseWorkbenchService($db))->insert('people', [
			'id' => '', 'name' => "O'Reilly", 'computed' => 'ignored', 'not_in_table' => 'invalid', 7 => 'numeric key',
		]);

		self::assertSame(['affected_rows' => 1, 'insert_id' => '42'], $result);
		self::assertContains(['non', "INSERT INTO `people` (`name`) VALUES ('O\\'Reilly')"], $db->queries);
	}

	public function testInsertQuotesNullableBooleanNumericAndEscapedValues(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi['SHOW FULL COLUMNS FROM `people`'][] = ['Field' => 'enabled', 'Extra' => ''];
		$db->multi['SHOW FULL COLUMNS FROM `people`'][] = ['Field' => 'ratio', 'Extra' => ''];
		$db->multi['SHOW FULL COLUMNS FROM `people`'][] = ['Field' => 'details', 'Extra' => ''];
		(new DatabaseWorkbenchService($db))->insert('people', [
			'id' => 9, 'name' => 'ignored', 'enabled' => true, 'ratio' => 1.25, 'details' => null,
		], ['name']);

		self::assertContains(['non', 'INSERT INTO `people` (`id`, `name`, `enabled`, `ratio`, `details`) VALUES (9, NULL, 1, 1.25, NULL)'], $db->queries);
	}

	public function testInsertWithNoInsertableValuesIsRejected(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('No insertable values were provided.');
		(new DatabaseWorkbenchService($db))->insert('people', ['id' => '', 'computed' => 8, 'absent' => 1]);
	}

	public function testUpdateUsesValidatedCompositePrimaryKeyWithNullCondition(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [
			['Seq_in_index' => 2, 'Column_name' => 'id'],
			['Seq_in_index' => 1, 'Column_name' => 'version'],
		];
		$db->multi['SHOW FULL COLUMNS FROM `people`'][] = ['Field' => 'version', 'Extra' => ''];
		$db->affected = 1;

		self::assertSame(['affected_rows' => 1], (new DatabaseWorkbenchService($db))->update(
			'people', ['id' => 4, 'version' => null],
			['name' => "O'Reilly", 'id' => 5, 'computed' => 20, 'absent' => 20], ['name']
		));
		self::assertContains([
			'non', 'UPDATE `people` SET `name` = NULL, `id` = 5 WHERE `version` IS NULL AND `id` = 4 LIMIT 1',
		], $db->queries);
	}

	public function testUpdateQuotesRegularValuesAndSkipsMissingOrGeneratedColumns(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		(new DatabaseWorkbenchService($db))->update('people', ['id' => 1], [
			'bad' => 3, 'computed' => 8, 'name' => 'valid', 'id' => false,
		]);
		self::assertContains(['non', "UPDATE `people` SET `name` = 'valid', `id` = 0 WHERE `id` = 1 LIMIT 1"], $db->queries);
	}

	public function testUpdateWithoutColumnsDoesNotExecuteMutation(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('No update values were provided.');
		(new DatabaseWorkbenchService($db))->update('people', ['id' => 2], ['computed' => 9, 'other' => 'no']);
	}

	public function testDeleteNeedsExistingCompletePrimaryKey(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [];
		try {
			(new DatabaseWorkbenchService($db))->delete('people', ['id' => 2]);
			self::fail('Expected a primary-key requirement.');
		} catch (DatabaseWorkbenchException $e) {
			self::assertSame('This operation requires a primary key.', $e->getMessage());
		}

		$db->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [
			['Seq_in_index' => 1, 'Column_name' => 'id'],
		];
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('Missing primary key value: id');
		(new DatabaseWorkbenchService($db))->delete('people', []);
	}

	public function testDeleteUsesBoundedPrimaryKeyMutation(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->affected = 1;
		self::assertSame(['affected_rows' => 1], (new DatabaseWorkbenchService($db))->delete('people', ['id' => null]));
		self::assertContains(['non', 'DELETE FROM `people` WHERE `id` IS NULL LIMIT 1'], $db->queries);

		$db->queries = [];
		(new DatabaseWorkbenchService($db))->delete('people', ['id' => 12]);
		self::assertContains(['non', 'DELETE FROM `people` WHERE `id` = 12 LIMIT 1'], $db->queries);
	}

	public function testTruncateAndDropRequireKnownTables(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$service = new DatabaseWorkbenchService($db);
		self::assertSame(['table' => 'people'], $service->truncate('people'));
		self::assertSame(['table' => 'people'], $service->drop('people'));
		self::assertContains(['non', 'TRUNCATE TABLE `people`'], $db->queries);
		self::assertContains(['non', 'DROP TABLE `people`'], $db->queries);
	}

	public function testAllMutationPathsPropagateDatabaseErrors(): void {
		foreach (['insert', 'update', 'delete', 'truncate', 'drop'] as $action) {
			$db = WorkbenchFakeDatabase::withPeople();
			$db->error = true;
			$db->errorCode = 1064;
			$db->errorText = 'syntax error';
			$service = new DatabaseWorkbenchService($db);
			try {
				match ($action) {
					'insert' => $service->insert('people', ['name' => 'a']),
					'update' => $service->update('people', ['id' => 1], ['name' => 'a']),
					'delete' => $service->delete('people', ['id' => 1]),
					'truncate' => $service->truncate('people'),
					'drop' => $service->drop('people'),
				};
				self::fail('Expected database error in ' . $action);
			} catch (DatabaseWorkbenchException $e) {
				self::assertSame('Database error 1064: syntax error', $e->getMessage(), $action);
			}
		}
	}
}
