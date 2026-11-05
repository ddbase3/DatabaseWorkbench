<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Service;

use DatabaseWorkbench\Exception\DatabaseWorkbenchException;
use DatabaseWorkbench\Service\DatabaseWorkbenchService;
use DatabaseWorkbench\Test\Support\WorkbenchFakeDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseWorkbenchService::class)]
final class DatabaseWorkbenchServiceBrowseTest extends TestCase {

	public function testBrowseDefaultsToPrimaryKeyAndNormalizesRows(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->scalar['SELECT COUNT(*) FROM `people`'] = '5';
		$db->multi['SELECT * FROM `people` ORDER BY `id` ASC LIMIT 0, 2'] = [
			['id' => '3', 'name' => "\xFF", 'computed' => null],
			['id' => '4', 'name' => 'Peter', 'computed' => null],
		];
		$data = (new DatabaseWorkbenchService($db))->browse('people', 1, 2, 'not-a-column', 'nonsense');

		self::assertSame('people', $data['table']);
		self::assertSame(['id'], $data['primary_key']);
		self::assertSame('id', $data['order_by']);
		self::assertSame('asc', $data['order_direction']);
		self::assertSame(1, $data['page']);
		self::assertSame(2, $data['page_size']);
		self::assertSame(5, $data['total']);
		self::assertSame(3, $data['total_pages']);
		self::assertSame(['id' => '3'], $data['rows'][0]['key']);
		self::assertSame('[binary 1 bytes]', $data['rows'][0]['values']['name']);
		self::assertSame('Peter', $data['rows'][1]['values']['name']);
	}

	public function testBrowseRespectsChosenSortDirectionAndBounds(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->scalar['SELECT COUNT(*) FROM `people`'] = 201;
		$db->multi['SELECT * FROM `people` ORDER BY `name` DESC LIMIT 200, 200'] = [];
		$data = (new DatabaseWorkbenchService($db))->browse(' people ', 2, 1000, 'name', 'DeSc');
		self::assertSame([2, 200, 'name', 'desc', 2], [
			$data['page'], $data['page_size'], $data['order_by'], $data['order_direction'], $data['total_pages'],
		]);
		self::assertContains(['multi', 'SELECT * FROM `people` ORDER BY `name` DESC LIMIT 200, 200'], $db->queries);
	}

	public function testBrowseClampsNonpositivePageAndPageSize(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$data = (new DatabaseWorkbenchService($db))->browse('people', -10, 0);
		self::assertSame([1, 1, 0, 1], [$data['page'], $data['page_size'], $data['total'], $data['total_pages']]);
		self::assertContains(['multi', 'SELECT * FROM `people` ORDER BY `id` ASC LIMIT 0, 1'], $db->queries);
	}

	public function testBrowseFallsBackToFirstColumnWithoutPrimaryKey(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [];
		$db->multi['SELECT * FROM `people` ORDER BY `id` ASC LIMIT 0, 50'] = [['id' => 8, 'name' => 'Test']];
		$data = (new DatabaseWorkbenchService($db))->browse('people', 1, 50);
		self::assertSame([], $data['primary_key']);
		self::assertSame('id', $data['order_by']);
		self::assertSame([], $data['rows'][0]['key']);
	}

	public function testBrowseWithoutColumnsDoesNotProduceAnOrderClause(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->multi['SHOW FULL COLUMNS FROM `people`'] = [];
		$db->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [];
		$data = (new DatabaseWorkbenchService($db))->browse('people', 1, 50, 'name');
		self::assertSame('', $data['order_by']);
		self::assertSame([], $data['columns']);
		self::assertContains(['multi', 'SELECT * FROM `people` LIMIT 0, 50'], $db->queries);
	}

	public function testBrowseRejectsFailedColumnMetadataQueries(): void {
		$db = WorkbenchFakeDatabase::withPeople();
		$db->error = true;
		$db->errorCode = 1146;
		$db->errorText = 'missing table';
		$this->expectException(DatabaseWorkbenchException::class);
		$this->expectExceptionMessage('Database error 1146: missing table');
		(new DatabaseWorkbenchService($db))->browse('people', 1, 50);
	}
}
