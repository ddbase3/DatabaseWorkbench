<?php declare(strict_types=1);

namespace DatabaseWorkbench\Content;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use Throwable;

final class DatabaseWorkbenchApi implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IDatabaseWorkbenchService $service,
		private readonly IDatabaseWorkbenchCsrf $csrf
	) {}

	public static function getName(): string {
		return 'databaseworkbenchapi';
	}

	public function getOutput(string $out = 'json', bool $final = false): string {
		try {
			$payload = $this->request->getJsonBody();
			if ($payload === []) {
				$payload = $this->request->allPost();
			}
			$this->csrf->assertValid((string) ($payload['csrf'] ?? ''));

			$action = trim((string) ($payload['action'] ?? ''));
			$data = $this->dispatch($action, $payload);
			return $this->json([
				'ok' => true,
				'data' => $data,
			]);
		}
		catch (Throwable $e) {
			return $this->json([
				'ok' => false,
				'error' => $e->getMessage(),
			]);
		}
	}

	private function dispatch(string $action, array $payload): array {
		return match ($action) {
			'overview' => $this->service->getOverview(),
			'tables' => ['tables' => $this->service->getTables()],
			'structure' => $this->service->getStructure($this->stringValue($payload, 'table')),
			'browse' => $this->service->browse(
				$this->stringValue($payload, 'table'),
				$this->intValue($payload, 'page', 1),
				$this->intValue($payload, 'page_size', 50),
				$this->stringValue($payload, 'order_by', ''),
				$this->stringValue($payload, 'order_direction', 'asc')
			),
			'insert' => $this->service->insert(
				$this->stringValue($payload, 'table'),
				$this->arrayValue($payload, 'values'),
				$this->stringArrayValue($payload, 'null_columns')
			),
			'update' => $this->service->update(
				$this->stringValue($payload, 'table'),
				$this->arrayValue($payload, 'key'),
				$this->arrayValue($payload, 'values'),
				$this->stringArrayValue($payload, 'null_columns')
			),
			'delete' => $this->service->delete(
				$this->stringValue($payload, 'table'),
				$this->arrayValue($payload, 'key')
			),
			'truncate' => $this->service->truncate($this->stringValue($payload, 'table')),
			'drop' => $this->service->drop($this->stringValue($payload, 'table')),
			'sql' => $this->service->executeSql($this->stringValue($payload, 'sql')),
			'export' => $this->service->exportTable($this->stringValue($payload, 'table')),
			default => throw new \InvalidArgumentException('Unknown DatabaseWorkbench action: ' . $action),
		};
	}

	private function stringValue(array $payload, string $key, string $default = ''): string {
		$value = $payload[$key] ?? $default;
		return is_scalar($value) ? (string) $value : $default;
	}

	private function intValue(array $payload, string $key, int $default): int {
		$value = $payload[$key] ?? $default;
		return is_numeric($value) ? (int) $value : $default;
	}

	private function arrayValue(array $payload, string $key): array {
		$value = $payload[$key] ?? [];
		return is_array($value) ? $value : [];
	}

	private function stringArrayValue(array $payload, string $key): array {
		return array_values(array_filter(
			$this->arrayValue($payload, $key),
			fn($value): bool => is_string($value)
		));
	}

	private function json(array $data): string {
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
		}

		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return $json === false ? '{"ok":false,"error":"Unable to encode response."}' : $json;
	}
}
