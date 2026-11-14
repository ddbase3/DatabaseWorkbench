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

namespace Base3\DatabaseWorkbench\Api;

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
