<?php declare(strict_types=1);

namespace DatabaseWorkbench\Api;

interface IDatabaseWorkbenchCsrf {

	public function issueToken(): string;

	public function isValid(string $token): bool;

	public function assertValid(string $token): void;
}
