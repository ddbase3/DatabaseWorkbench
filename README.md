# DatabaseWorkbench

DatabaseWorkbench is an embeddable BASE3 display for MySQL/MariaDB administration. The HTML display is only the shell; table discovery, browsing, structure inspection, row mutations, SQL execution, destructive table operations, and exports run through a dedicated JSON endpoint.

## Display

```text
databaseworkbenchdisplay
```

The AJAX endpoint is:

```text
databaseworkbenchapi
```

## Included functions

- database and server overview
- table list with status information
- paginated row browser with sortable columns
- insert, edit, and delete for tables with primary keys
- column, index, and CREATE TABLE inspection
- SQL console for read and write statements
- table truncate and drop actions
- SQL export with structure and up to 5000 rows
- CSRF protection through the BASE3 `ISession` contract

## Requirements

- a configured `Base3\Database\Api\IDatabase` implementation for MySQL or MariaDB
- an available `Base3\Session\Api\ISession` implementation
- a current BASE3 user returned by `IUsermanager::getUser()` with a nonempty technical ID (no role or permission check)
- an active `Base3\LinkTarget\Api\ILinkTargetService`
- an active `Base3\Api\IAssetResolver`

The plugin registers a replaceable default for:

- `DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf`

A project plugin may deliberately replace this service if the host system has its own session or CSRF boundary.

## Installation

Extract the patch at the BASE3 project root:

```bash
unzip -o DatabaseWorkbench-patch.zip
```

Regenerate the class map if the current installation does not do this automatically.

## Embedding

Resolve `databaseworkbenchdisplay` through the normal BASE3 display/class-map mechanism and render it as embedded output.

Optional display data:

```php
$display->setData([
	'title' => 'Database Administration',
	'page_size' => 50,
]);
```

## Security notes

This version requires a current user returned by `IUsermanager::getUser()` with a nonempty, nonzero technical ID at both the display and the JSON endpoint. It intentionally performs no role or permission check. Every user passing this minimal identity check can execute all supported database operations with the privileges of the configured database account.

Restrict both the display and the endpoint to trusted users at the deployment boundary until authorization is added. The minimal current-user check and CSRF protection are not permission checks.

Use a dedicated database account with only the privileges that administrators actually need. The SQL console can execute destructive SQL.

Row updates and deletes require a primary key. Tables without a primary key remain browseable, but per-row edit and delete controls are disabled.

The export function is capped at 5000 rows to keep an embedded AJAX display bounded. Large production exports should use a dedicated backup or export workflow.

## Documentation

- [Frequently Asked Questions](docs/faq.md)
- [Privacy and data processing](PRIVACY.md)
