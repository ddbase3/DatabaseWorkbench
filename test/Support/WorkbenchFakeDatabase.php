<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Support;

use Base3\Database\Api\IDatabase;

/** Query-recording implementation of the existing database contract for isolated unit tests. */
final class WorkbenchFakeDatabase implements IDatabase {

	public bool $available = true;
	public bool $error = false;
	public int $errorCode = 0;
	public string $errorText = '';
	public int $connectCalls = 0;
	public int $affected = 0;
	public int|string $inserted = 0;
	public array $queries = [];
	public array $multi = [];
	public array $single = [];
	public array $scalar = [];
	public array $lists = [];
	private array $lastMulti = [];
	private array $lastList = [];

	public static function withPeople(): self {
		$database = new self();
		$database->multi['SHOW TABLE STATUS'] = [[
			'Name' => 'people', 'Engine' => 'InnoDB', 'Rows' => '2',
			'Data_length' => '1024', 'Index_length' => '128',
			'Collation' => 'utf8mb4_unicode_ci', 'Comment' => 'persons',
		]];
		$database->multi['SHOW FULL COLUMNS FROM `people`'] = [
			['Field' => 'id', 'Type' => 'int', 'Extra' => 'auto_increment'],
			['Field' => 'name', 'Type' => 'varchar(100)', 'Extra' => ''],
			['Field' => 'computed', 'Type' => 'int', 'Extra' => 'VIRTUAL GENERATED'],
		];
		$database->multi["SHOW INDEX FROM `people` WHERE Key_name = 'PRIMARY'"] = [
			['Key_name' => 'PRIMARY', 'Seq_in_index' => '1', 'Column_name' => 'id'],
		];
		$database->multi['SHOW INDEX FROM `people`'] = [
			['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id'],
		];
		$database->single['SHOW CREATE TABLE `people`'] = [
			'Table' => 'people', 'Create Table' => 'CREATE TABLE `people` (`id` int, `name` varchar(100))',
		];
		return $database;
	}

	public function connect(): void {
		$this->connectCalls++;
	}

	public function connected(): bool {
		return $this->available;
	}

	public function disconnect(): void {
		$this->available = false;
	}

	public function beginTransaction(): void {
		$this->queries[] = ['begin', ''];
	}

	public function commit(): void {
		$this->queries[] = ['commit', ''];
	}

	public function rollback(): void {
		$this->queries[] = ['rollback', ''];
	}

	public function nonQuery(string $query): void {
		$this->queries[] = ['non', $query];
	}

	public function scalarQuery(string $query): mixed {
		$this->queries[] = ['scalar', $query];
		return $this->scalar[$query] ?? null;
	}

	public function singleQuery(string $query): ?array {
		$this->queries[] = ['single', $query];
		return $this->single[$query] ?? null;
	}

	public function &listQuery(string $query): array {
		$this->queries[] = ['list', $query];
		$this->lastList = $this->lists[$query] ?? [];
		return $this->lastList;
	}

	public function &multiQuery(string $query): array {
		$this->queries[] = ['multi', $query];
		$this->lastMulti = $this->multi[$query] ?? [];
		return $this->lastMulti;
	}

	public function affectedRows(): int {
		return $this->affected;
	}

	public function insertId(): int|string {
		return $this->inserted;
	}

	public function escape(string $str): string {
		return str_replace(["\\", "'"], ["\\\\", "\\'"], $str);
	}

	public function isError(): bool {
		return $this->error;
	}

	public function errorNumber(): int {
		return $this->errorCode;
	}

	public function errorMessage(): string {
		return $this->errorText;
	}
}
