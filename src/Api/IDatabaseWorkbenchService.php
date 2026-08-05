<?php declare(strict_types=1);

namespace DatabaseWorkbench\Api;

interface IDatabaseWorkbenchService {

	public function getOverview(): array;

	public function getTables(): array;

	public function getStructure(string $table): array;

	public function browse(string $table, int $page, int $pageSize, string $orderBy = '', string $orderDirection = 'asc'): array;

	public function insert(string $table, array $values, array $nullColumns = []): array;

	public function update(string $table, array $key, array $values, array $nullColumns = []): array;

	public function delete(string $table, array $key): array;

	public function truncate(string $table): array;

	public function drop(string $table): array;

	public function executeSql(string $sql): array;

	public function exportTable(string $table): array;
}
