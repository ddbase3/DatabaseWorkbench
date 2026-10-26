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
- a current BASE3 user returned by `IUsermanager::getUser()` with a nonempty technical ID
- an active `Base3\Authorization\Api\IAuthorizationService`
- an active `Base3\Translation\Api\ITranslation` for localized feature information and API errors
- an active `Base3\LinkTarget\Api\ILinkTargetService`
- an active `Base3\Api\IAssetResolver`

The plugin registers a replaceable default for:

- `DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf`

A project plugin may deliberately replace this service if the host system has its own session or CSRF boundary.

## Installation

Extract the patch in the parent directory containing both the `DatabaseWorkbench` and `Base3IliasLab` modules (normally the BASE3 plugins directory):

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

Both `databaseworkbenchdisplay` and `databaseworkbenchapi` require a current user from `IUsermanager::getUser()` with a nonempty, nonzero technical ID **and** an explicit `ALLOW` decision from the current `IAuthorizationService` for `feature/databaseworkbenchfeature`. A denied, indeterminate or unavailable authorization decision blocks the workbench. The API rejects unauthorized requests with HTTP 403 before CSRF validation or any database operation.

`DatabaseWorkbench\Feature\DatabaseWorkbenchFeature` is discovered via `IClassMap` and declares an empty default audience. **No user, role or BASE3 group receives access by default.** Grant access explicitly through the host's BASE3 feature administration before using the workbench. In the Base3IliasLab settings navigation, the `databaseworkbenchdisplay` entry is shown only after an allowed feature decision and remains subject to the host's existing administration navigation restrictions.

Feature authorization and CSRF protection are separate safeguards. The authorization decision does not change the privileges of the configured database account.

Use a dedicated database account with only the privileges that administrators actually need. The SQL console can execute destructive SQL.

Row updates and deletes require a primary key. Tables without a primary key remain browseable, but per-row edit and delete controls are disabled.

The export function is capped at 5000 rows to keep an embedded AJAX display bounded. Large production exports should use a dedicated backup or export workflow.

## Documentation

- [Frequently Asked Questions](docs/faq.md)
- [Privacy and data processing](PRIVACY.md)
