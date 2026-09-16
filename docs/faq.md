# DatabaseWorkbench FAQ

## What is DatabaseWorkbench?

DatabaseWorkbench is an embeddable BASE3 administration component for MySQL and MariaDB databases. It provides a browser based workbench for inspecting database metadata, browsing table contents, changing rows, executing SQL, performing selected destructive table operations, and exporting table data as SQL.

The visible display is a client side shell. Database operations are performed by a dedicated JSON endpoint through the configured BASE3 database service.

## Which BASE3 components does DatabaseWorkbench expose?

The main display is identified by:

```text
databaseworkbenchdisplay
```

The JSON endpoint is identified by:

```text
databaseworkbenchapi
```

The plugin also registers `IDatabaseWorkbenchService` and provides a replaceable default implementation of `IDatabaseWorkbenchCsrf`.

## Which databases are supported?

The implementation is written for MySQL and MariaDB semantics. It uses statements such as `SHOW TABLE STATUS`, `SHOW FULL COLUMNS`, `SHOW INDEX`, `SHOW CREATE TABLE`, `SELECT VERSION()`, and MySQL style identifier quoting.

The active database connection is supplied through `Base3\Database\Api\IDatabase`.

## Does DatabaseWorkbench create its own database tables?

No. The component has no migrations and creates no private application tables. It works with the database exposed by the configured `IDatabase` implementation.

## What information is shown in the overview?

The overview reports:

- the active database name
- the server version
- the database character set
- the database collation
- the number of visible tables
- table names, storage engines, approximate row counts, data sizes, index sizes, and collations

The values come directly from the connected database server.

## Can I browse table contents?

Yes. DatabaseWorkbench can read table rows with pagination and sorting.

The default page size is 50 rows. The server enforces a maximum page size of 200 rows.

Sorting is restricted to columns that actually exist in the selected table. If no valid sort column is supplied, the first primary key column is preferred, followed by the first available column.

## Can I insert rows?

Yes. The row editor can insert values into existing table columns.

Generated columns are ignored. An auto increment column is omitted from the insert when its submitted value is empty. Columns that allow `NULL` can explicitly be submitted as `NULL`.

## Can I edit or delete rows?

Yes, but row updates and row deletes require a primary key.

DatabaseWorkbench constructs the row selector from the actual primary key columns of the table. Updates and deletes are limited to one matching row.

Tables without a primary key can still be inspected and browsed, but the component does not provide per-row update or delete operations for them.

## Can DatabaseWorkbench truncate or drop tables?

Yes. The workbench exposes both `TRUNCATE TABLE` and `DROP TABLE` actions.

The browser interface asks for confirmation before sending these requests, but the confirmation dialog is only a user interface safeguard. The server endpoint itself performs the requested action after the CSRF token is validated.

Access to DatabaseWorkbench therefore needs to be restricted at the surrounding application or deployment boundary.

## Does DatabaseWorkbench include an SQL console?

Yes. The SQL console submits the entered SQL statement to the configured database connection.

Statements beginning with `SELECT`, `SHOW`, `DESCRIBE`, `DESC`, `EXPLAIN`, and supported `WITH ... SELECT` queries are treated as result queries. Other SQL is passed to the database as a mutation statement.

DatabaseWorkbench does not implement a separate allowlist of writable SQL commands. The effective capability is determined by the privileges of the configured database account and by what the active `IDatabase` implementation accepts.

## How many rows can the SQL console return?

The component returns at most 500 rows through the SQL console response. If a larger result is returned by the database abstraction, the response is truncated to the first 500 rows and marked as truncated.

This limit applies to the workbench response. It is not a database query limit added to the SQL statement itself.

## How does table export work?

The export action builds an SQL document containing:

- a generated timestamp
- `DROP TABLE IF EXISTS`
- the current `CREATE TABLE` statement
- `INSERT` statements for exported rows

The server returns the generated SQL content through the JSON endpoint. The browser creates an in-memory `Blob` and starts a local file download.

DatabaseWorkbench does not write the export to a server-side export directory.

## Is the export complete for large tables?

Not necessarily. Table exports are capped at 5,000 rows.

If the table contains more rows, the generated file contains a comment stating that the export was truncated. The response also contains the total row count, exported row count, and truncation flag.

DatabaseWorkbench is therefore not a replacement for a full database backup process.

## Does the export include sensitive table contents?

It can. The export contains the values of the exported database rows. If the selected table contains personal data, credentials, secrets, business information, or other sensitive information, those values can be included in the downloaded SQL file.

The downloaded file is then outside the control of DatabaseWorkbench and must be protected according to the sensitivity of its contents.

## How are binary values handled in the browser?

When a database value is a string that is not valid UTF-8, DatabaseWorkbench replaces the displayed value with a marker such as:

```text
[binary 123 bytes]
```

The normal row browser and SQL result display therefore do not attempt to send arbitrary invalid UTF-8 byte sequences as JSON text.

This normalization concerns display and JSON responses. The SQL export is generated from the original database row values used by the export routine.

## Does DatabaseWorkbench have its own authentication or authorization system?

No.

The current component intentionally contains no user or permission check. A user who can reach the display and its JSON endpoint can use the database operations made available by the plugin, subject to the privileges of the configured database account.

Authentication and authorization must be enforced by the surrounding application, route protection, reverse proxy, network boundary, or another appropriate access control layer.

## Is CSRF protection the same as authorization?

No. DatabaseWorkbench uses a session-backed CSRF token to protect requests from cross-site request forgery. This does not decide whether a user is allowed to administer the database.

A valid session and CSRF token must not be treated as a substitute for an authorization decision.

## How does CSRF protection work?

The default CSRF implementation stores one random token in the active PHP session under:

```text
databaseworkbench.csrf
```

The token is generated with `random_bytes(32)`, encoded as hexadecimal text, and reused for the active session unless it is missing or invalid.

The display embeds the token in its runtime configuration. Every JSON request includes it in the request body. The endpoint compares the submitted value with the session value using `hash_equals()`.

## Does DatabaseWorkbench require a PHP session?

The default CSRF implementation does. The session must already be active when the display is rendered.

If no active session exists, the display returns an error message instead of the workbench interface.

A host project may replace `IDatabaseWorkbenchCsrf` if it deliberately provides a different CSRF boundary.

## How are requests sent to the API?

The browser uses `fetch()` with:

```text
POST
Content-Type: application/json
credentials: same-origin
```

Each request contains an action name, the CSRF token, and the action-specific data such as table name, paging parameters, row values, primary key values, or SQL text.

The endpoint can also read regular POST data when no JSON body is present.

## Does DatabaseWorkbench use browser local storage or session storage?

No. The supplied client script does not use Local Storage, Session Storage, IndexedDB, or its own cookies.

Current UI state, such as the selected table, page number, sort order, and loaded rows, is held in JavaScript memory for the lifetime of the page.

The CSRF value is backed by the PHP session on the server side.

## Does DatabaseWorkbench send data to external services?

The component itself does not contain integrations with analytics, cloud APIs, remote databases, or other third-party services.

Database operations use the configured `IDatabase` service. Browser requests target the endpoint generated for `databaseworkbenchapi`. Static asset URLs are obtained through the BASE3 asset resolver.

The actual deployment can still introduce infrastructure outside this component, for example a remote database host, proxy, CDN, centralized logging service, or externally served assets. Those choices belong to the surrounding installation.

## Does DatabaseWorkbench keep an audit log?

No audit log is implemented by this component.

DatabaseWorkbench itself does not record which user viewed a table, executed SQL, changed a row, truncated a table, dropped a table, or generated an export.

If an audit trail is required, it must be provided at another appropriate architecture boundary, such as the database, application, reverse proxy, or a dedicated audit service.

## Does the component store database credentials?

No. DatabaseWorkbench receives an already configured `IDatabase` service. It does not contain its own connection configuration screen or credential store.

Credential storage and connection configuration belong to the active database implementation and the surrounding application configuration.

## How are table and column names validated?

A requested table must match an actual table returned by `SHOW TABLE STATUS` before it is used by table-specific operations.

For structured insert and update actions, submitted column names are compared with the real columns of that table. Sort columns are also limited to real table columns.

Identifiers are quoted before SQL is built, and submitted scalar row values are escaped through the configured `IDatabase` service.

The SQL console is intentionally different. SQL entered there is an administrator supplied statement and is passed to the database as SQL.

## Are database errors exposed to the client?

The JSON endpoint catches thrown errors and returns the exception message in its JSON error field.

For database errors, the service exception contains the database error number and message. The supplied browser interface maps such errors to a generic localized message for display, but the original JSON response can still be inspected by a client with access to the endpoint.

For that reason, the endpoint should be treated as an administrative interface and should not be exposed to untrusted users.

## What privileges should the database account have?

Use a dedicated database account with only the privileges that the intended administrators actually need.

DatabaseWorkbench cannot compensate for an overprivileged database account. If the connection can alter schemas, delete data, or access sensitive tables, the workbench can potentially exercise those capabilities through its structured operations or SQL console.

## Does DatabaseWorkbench modify BASE3 configuration?

No. The component registers its own services during plugin initialization, but it does not provide a settings editor and does not persist its own configuration records.

The optional display settings `title` and `page_size` are supplied when the display is configured for rendering.

## Which privacy information should an installation document separately?

An installation using DatabaseWorkbench should document at least:

- who is allowed to access the display and API endpoint
- which database and schemas are reachable
- which database account and privileges are used
- whether the database is local or remote
- what categories of personal or confidential data can be viewed or changed
- whether database, web server, proxy, or application audit logs record workbench activity
- how downloaded SQL exports must be stored and deleted
- session lifetime and session protection
- any infrastructure that processes requests outside the local application

See [PRIVACY.md](../PRIVACY.md) for the component-specific data processing notes.
