<?php declare(strict_types=1);

namespace DatabaseWorkbench\Service;

use Base3\Database\Api\IDatabase;
use DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use DatabaseWorkbench\Exception\DatabaseWorkbenchException;

final class DatabaseWorkbenchService implements IDatabaseWorkbenchService {

	private const MAX_PAGE_SIZE = 200;
	private const MAX_SQL_ROWS = 500;
	private const MAX_EXPORT_ROWS = 5000;

	public function __construct(
		private readonly IDatabase $database
	) {}

	public function getOverview(): array {
		$this->connect();

		return [
			'database' => (string) ($this->database->scalarQuery('SELECT DATABASE()') ?? ''),
			'version' => (string) ($this->database->scalarQuery('SELECT VERSION()') ?? ''),
			'charset' => $this->readVariable('character_set_database'),
			'collation' => $this->readVariable('collation_database'),
			'tables' => count($this->getTables()),
		];
	}

	public function getTables(): array {
		$this->connect();
		$rows = $this->database->multiQuery('SHOW TABLE STATUS');

		return array_map(function(array $row): array {
			return [
				'name' => (string) ($row['Name'] ?? ''),
				'engine' => (string) ($row['Engine'] ?? ''),
				'rows' => (int) ($row['Rows'] ?? 0),
				'data_length' => (int) ($row['Data_length'] ?? 0),
				'index_length' => (int) ($row['Index_length'] ?? 0),
				'collation' => (string) ($row['Collation'] ?? ''),
				'comment' => (string) ($row['Comment'] ?? ''),
			];
		}, $rows);
	}

	public function getStructure(string $table): array {
		$table = $this->requireTable($table);
		$quotedTable = $this->quoteIdentifier($table);
		$columns = $this->database->multiQuery('SHOW FULL COLUMNS FROM ' . $quotedTable);
		$indexes = $this->database->multiQuery('SHOW INDEX FROM ' . $quotedTable);
		$createRow = $this->database->singleQuery('SHOW CREATE TABLE ' . $quotedTable) ?? [];

		return [
			'table' => $table,
			'columns' => array_map(fn(array $row): array => $this->normalizeRow($row), $columns),
			'indexes' => array_map(fn(array $row): array => $this->normalizeRow($row), $indexes),
			'primary_key' => $this->getPrimaryKeyColumns($table),
			'create_sql' => $this->extractCreateSql($createRow),
		];
	}

	public function browse(
		string $table,
		int $page,
		int $pageSize,
		string $orderBy = '',
		string $orderDirection = 'asc'
	): array {
		$table = $this->requireTable($table);
		$columns = $this->getColumns($table);
		$columnNames = array_map(fn(array $column): string => (string) $column['Field'], $columns);
		$primaryKey = $this->getPrimaryKeyColumns($table);
		$page = max(1, $page);
		$pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
		$offset = ($page - 1) * $pageSize;
		$orderDirection = strtolower($orderDirection) === 'desc' ? 'DESC' : 'ASC';

		if ($orderBy === '' || !in_array($orderBy, $columnNames, true)) {
			$orderBy = $primaryKey[0] ?? ($columnNames[0] ?? '');
		}

		$quotedTable = $this->quoteIdentifier($table);
		$total = (int) ($this->database->scalarQuery('SELECT COUNT(*) FROM ' . $quotedTable) ?? 0);
		$sql = 'SELECT * FROM ' . $quotedTable;
		if ($orderBy !== '') {
			$sql .= ' ORDER BY ' . $this->quoteIdentifier($orderBy) . ' ' . $orderDirection;
		}
		$sql .= ' LIMIT ' . $offset . ', ' . $pageSize;
		$rows = $this->database->multiQuery($sql);

		return [
			'table' => $table,
			'columns' => array_map(fn(array $row): array => $this->normalizeRow($row), $columns),
			'primary_key' => $primaryKey,
			'rows' => array_map(function(array $row) use ($primaryKey): array {
				$key = [];
				foreach ($primaryKey as $column) {
					$key[$column] = $this->normalizeValue($row[$column] ?? null);
				}

				return [
					'values' => $this->normalizeRow($row),
					'key' => $key,
				];
			}, $rows),
			'page' => $page,
			'page_size' => $pageSize,
			'total' => $total,
			'total_pages' => max(1, (int) ceil($total / $pageSize)),
			'order_by' => $orderBy,
			'order_direction' => strtolower($orderDirection),
		];
	}

	public function insert(string $table, array $values, array $nullColumns = []): array {
		$table = $this->requireTable($table);
		$columns = $this->getColumns($table);
		$columnMap = $this->mapColumns($columns);
		$fields = [];
		$literals = [];

		foreach ($values as $column => $value) {
			if (!is_string($column) || !isset($columnMap[$column])) {
				continue;
			}
			if ($this->isGeneratedColumn($columnMap[$column])) {
				continue;
			}
			if ($this->isAutoIncrementColumn($columnMap[$column]) && $value === '') {
				continue;
			}

			$fields[] = $this->quoteIdentifier($column);
			$literals[] = in_array($column, $nullColumns, true)
				? 'NULL'
				: $this->quoteValue($value);
		}

		if ($fields === []) {
			throw new DatabaseWorkbenchException('No insertable values were provided.');
		}

		$this->database->nonQuery(
			'INSERT INTO ' . $this->quoteIdentifier($table)
			. ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $literals) . ')'
		);
		$this->assertNoDatabaseError();

		return [
			'affected_rows' => $this->database->affectedRows(),
			'insert_id' => $this->database->insertId(),
		];
	}

	public function update(string $table, array $key, array $values, array $nullColumns = []): array {
		$table = $this->requireTable($table);
		$columns = $this->getColumns($table);
		$columnMap = $this->mapColumns($columns);
		$assignments = [];

		foreach ($values as $column => $value) {
			if (!is_string($column) || !isset($columnMap[$column])) {
				continue;
			}
			if ($this->isGeneratedColumn($columnMap[$column])) {
				continue;
			}

			$assignments[] = $this->quoteIdentifier($column) . ' = '
				. (in_array($column, $nullColumns, true) ? 'NULL' : $this->quoteValue($value));
		}

		if ($assignments === []) {
			throw new DatabaseWorkbenchException('No update values were provided.');
		}

		$where = $this->buildPrimaryKeyWhere($table, $key);
		$this->database->nonQuery(
			'UPDATE ' . $this->quoteIdentifier($table)
			. ' SET ' . implode(', ', $assignments)
			. ' WHERE ' . $where
			. ' LIMIT 1'
		);
		$this->assertNoDatabaseError();

		return ['affected_rows' => $this->database->affectedRows()];
	}

	public function delete(string $table, array $key): array {
		$table = $this->requireTable($table);
		$where = $this->buildPrimaryKeyWhere($table, $key);
		$this->database->nonQuery(
			'DELETE FROM ' . $this->quoteIdentifier($table)
			. ' WHERE ' . $where
			. ' LIMIT 1'
		);
		$this->assertNoDatabaseError();

		return ['affected_rows' => $this->database->affectedRows()];
	}

	public function truncate(string $table): array {
		$table = $this->requireTable($table);
		$this->database->nonQuery('TRUNCATE TABLE ' . $this->quoteIdentifier($table));
		$this->assertNoDatabaseError();

		return ['table' => $table];
	}

	public function drop(string $table): array {
		$table = $this->requireTable($table);
		$this->database->nonQuery('DROP TABLE ' . $this->quoteIdentifier($table));
		$this->assertNoDatabaseError();

		return ['table' => $table];
	}

	public function executeSql(string $sql): array {
		$this->connect();
		$sql = trim($sql);
		if ($sql === '') {
			throw new DatabaseWorkbenchException('SQL must not be empty.');
		}

		$keyword = strtoupper((string) strtok(ltrim($sql), " \t\r\n("));
		$isResultQuery = in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)
			|| ($keyword === 'WITH' && preg_match('/\bSELECT\b/i', $sql) === 1);

		if ($isResultQuery) {
			$rows = $this->database->multiQuery($sql);
			$this->assertNoDatabaseError();
			$truncated = count($rows) > self::MAX_SQL_ROWS;
			if ($truncated) {
				$rows = array_slice($rows, 0, self::MAX_SQL_ROWS);
			}

			return [
				'type' => 'rows',
				'rows' => array_map(fn(array $row): array => $this->normalizeRow($row), $rows),
				'row_count' => count($rows),
				'truncated' => $truncated,
			];
		}

		$this->database->nonQuery($sql);
		$this->assertNoDatabaseError();

		return [
			'type' => 'mutation',
			'affected_rows' => $this->database->affectedRows(),
			'insert_id' => $this->database->insertId(),
		];
	}

	public function exportTable(string $table): array {
		$table = $this->requireTable($table);
		$quotedTable = $this->quoteIdentifier($table);
		$createRow = $this->database->singleQuery('SHOW CREATE TABLE ' . $quotedTable) ?? [];
		$createSql = $this->extractCreateSql($createRow);
		$total = (int) ($this->database->scalarQuery('SELECT COUNT(*) FROM ' . $quotedTable) ?? 0);
		$rows = $this->database->multiQuery(
			'SELECT * FROM ' . $quotedTable . ' LIMIT ' . self::MAX_EXPORT_ROWS
		);
		$columns = $rows === [] ? [] : array_keys($rows[0]);
		$lines = [
			'-- DatabaseWorkbench export',
			'-- Table: ' . $table,
			'-- Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
			'',
			'DROP TABLE IF EXISTS ' . $quotedTable . ';',
			$createSql . ';',
			'',
		];

		foreach ($rows as $row) {
			$values = [];
			foreach ($columns as $column) {
				$values[] = $row[$column] === null ? 'NULL' : $this->quoteValue($row[$column]);
			}
			$lines[] = 'INSERT INTO ' . $quotedTable
				. ' (' . implode(', ', array_map(fn(string $column): string => $this->quoteIdentifier($column), $columns)) . ')'
				. ' VALUES (' . implode(', ', $values) . ');';
		}

		if ($total > self::MAX_EXPORT_ROWS) {
			$lines[] = '';
			$lines[] = '-- Export truncated after ' . self::MAX_EXPORT_ROWS . ' rows of ' . $total . '.';
		}

		return [
			'filename' => $table . '-' . gmdate('Ymd-His') . '.sql',
			'content' => implode("\n", $lines) . "\n",
			'total_rows' => $total,
			'exported_rows' => count($rows),
			'truncated' => $total > self::MAX_EXPORT_ROWS,
		];
	}

	private function connect(): void {
		$this->database->connect();
		if (!$this->database->connected()) {
			throw new DatabaseWorkbenchException('The configured database connection is not available.');
		}
	}

	private function requireTable(string $table): string {
		$this->connect();
		$table = trim($table);
		if ($table === '') {
			throw new DatabaseWorkbenchException('Table name is required.');
		}

		$tables = array_map(fn(array $item): string => (string) $item['name'], $this->getTables());
		if (!in_array($table, $tables, true)) {
			throw new DatabaseWorkbenchException('Unknown table: ' . $table);
		}

		return $table;
	}

	private function getColumns(string $table): array {
		$rows = $this->database->multiQuery(
			'SHOW FULL COLUMNS FROM ' . $this->quoteIdentifier($table)
		);
		$this->assertNoDatabaseError();
		return $rows;
	}

	private function getPrimaryKeyColumns(string $table): array {
		$rows = $this->database->multiQuery(
			'SHOW INDEX FROM ' . $this->quoteIdentifier($table) . " WHERE Key_name = 'PRIMARY'"
		);
		usort($rows, fn(array $a, array $b): int => (int) ($a['Seq_in_index'] ?? 0) <=> (int) ($b['Seq_in_index'] ?? 0));

		return array_values(array_filter(array_map(
			fn(array $row): string => (string) ($row['Column_name'] ?? ''),
			$rows
		)));
	}

	private function buildPrimaryKeyWhere(string $table, array $key): string {
		$primaryKey = $this->getPrimaryKeyColumns($table);
		if ($primaryKey === []) {
			throw new DatabaseWorkbenchException('This operation requires a primary key.');
		}

		$conditions = [];
		foreach ($primaryKey as $column) {
			if (!array_key_exists($column, $key)) {
				throw new DatabaseWorkbenchException('Missing primary key value: ' . $column);
			}
			$value = $key[$column];
			$conditions[] = $this->quoteIdentifier($column)
				. ($value === null ? ' IS NULL' : ' = ' . $this->quoteValue($value));
		}

		return implode(' AND ', $conditions);
	}

	private function mapColumns(array $columns): array {
		$map = [];
		foreach ($columns as $column) {
			$name = (string) ($column['Field'] ?? '');
			if ($name !== '') {
				$map[$name] = $column;
			}
		}
		return $map;
	}

	private function isAutoIncrementColumn(array $column): bool {
		return stripos((string) ($column['Extra'] ?? ''), 'auto_increment') !== false;
	}

	private function isGeneratedColumn(array $column): bool {
		$extra = strtolower((string) ($column['Extra'] ?? ''));
		return str_contains($extra, 'generated');
	}

	private function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	private function quoteValue(mixed $value): string {
		if ($value === null) {
			return 'NULL';
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		return "'" . $this->database->escape((string) $value) . "'";
	}

	private function readVariable(string $name): string {
		$row = $this->database->singleQuery(
			"SHOW VARIABLES LIKE '" . $this->database->escape($name) . "'"
		);
		return (string) ($row['Value'] ?? '');
	}

	private function extractCreateSql(array $row): string {
		foreach ($row as $key => $value) {
			if (stripos((string) $key, 'Create ') === 0) {
				return (string) $value;
			}
		}

		throw new DatabaseWorkbenchException('Unable to read the CREATE TABLE statement.');
	}

	private function normalizeRow(array $row): array {
		$result = [];
		foreach ($row as $key => $value) {
			$result[(string) $key] = $this->normalizeValue($value);
		}
		return $result;
	}

	private function normalizeValue(mixed $value): mixed {
		if (!is_string($value)) {
			return $value;
		}
		if (preg_match('//u', $value) === 1) {
			return $value;
		}

		return '[binary ' . strlen($value) . ' bytes]';
	}

	private function assertNoDatabaseError(): void {
		if (!$this->database->isError()) {
			return;
		}

		throw new DatabaseWorkbenchException(
			'Database error ' . $this->database->errorNumber() . ': ' . $this->database->errorMessage()
		);
	}
}
