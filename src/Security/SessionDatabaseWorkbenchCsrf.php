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

namespace Base3\DatabaseWorkbench\Security;

use Base3\CoreFoundation\Session\Api\ISession;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Exception\DatabaseWorkbenchCsrfException;

final class SessionDatabaseWorkbenchCsrf implements IDatabaseWorkbenchCsrf {

	private const SESSION_KEY = 'databaseworkbench.csrf';

	public function __construct(private readonly ISession $session) {}

	public function issueToken(): string {
		$this->ensureStarted();

		$token = $this->session->get(self::SESSION_KEY);
		if (!is_string($token) || strlen($token) < 32) {
			$token = bin2hex(random_bytes(32));
			$this->session->set(self::SESSION_KEY, $token);
		}

		return $token;
	}

	public function isValid(string $token): bool {
		if ($token === '') return false;
		$this->ensureStarted();

		$expected = $this->session->get(self::SESSION_KEY);
		return is_string($expected) && hash_equals($expected, $token);
	}

	public function assertValid(string $token): void {
		if ($this->isValid($token)) return;
		throw new DatabaseWorkbenchCsrfException('The request token is invalid or the session has expired.');
	}

	private function ensureStarted(): void {
		if ($this->session->started() || $this->session->start()) return;
		throw new DatabaseWorkbenchCsrfException('DatabaseWorkbench could not start the BASE3 session.');
	}
}
