# DatabaseWorkbench Privacy and Data Processing

This document describes the data processing behavior implemented by the DatabaseWorkbench component. It is technical documentation and is not a complete legal privacy notice for a deployment.

DatabaseWorkbench is a database administration interface. The privacy impact of using it depends primarily on the database made available through the configured BASE3 `IDatabase` service and on who is permitted to access the workbench.

## 1. Component scope

DatabaseWorkbench provides an administrative browser interface and JSON endpoint for MySQL and MariaDB databases. It can:

- inspect database and server metadata
- enumerate tables
- inspect columns, indexes, primary keys, and `CREATE TABLE` definitions
- browse table rows
- insert rows
- update rows by primary key
- delete rows by primary key
- truncate tables
- drop tables
- execute administrator supplied SQL
- generate SQL exports containing table structure and row data

The component does not define the business meaning of the database contents. Any table exposed by the configured database connection can contain personal, confidential, security relevant, or otherwise protected information.

## 2. Data controller and deployment responsibility

DatabaseWorkbench does not determine the legal role of the organization operating it. The organization deploying the component must determine the applicable controller, processor, legal basis, access rules, retention rules, and security controls for the database being administered.

The deployment must also determine whether use of a general database administration interface is appropriate for the data stored in the connected database.

## 3. Data processed by the component

Depending on the selected database, table, and operation, DatabaseWorkbench can process the following categories of data.

### 3.1 Database metadata

The component reads and returns metadata including:

- active database name
- database server version
- database character set and collation
- table names
- table engines
- approximate table row counts
- data and index sizes
- table comments
- column names and definitions
- index definitions
- primary key definitions
- full `CREATE TABLE` statements

Even metadata can be sensitive. Table and column names can disclose internal system design, business domains, tenant structures, security functions, or the presence of particular categories of data.

### 3.2 Table row data

The browse function returns row values from the selected table. The component does not classify or redact those values.

Depending on the database, row data can include:

- names and identifiers
- email addresses and contact data
- account information
- roles and permissions
- application content
- authentication or security related records
- operational logs
- financial or contractual information
- special categories of personal data
- secrets or credentials stored by other software

DatabaseWorkbench cannot determine which of these categories are present.

### 3.3 Data submitted for row mutations

Insert and update actions send field values from the browser to the JSON endpoint. Delete and update actions also transmit the primary key values that identify the row.

The component uses these values immediately to construct and execute the requested database operation. It does not maintain a separate mutation history.

### 3.4 SQL console input and results

The SQL console sends the administrator supplied SQL text to the JSON endpoint.

For result queries, returned database rows are sent back to the browser. For other statements, the response includes the affected row count and insert ID where available.

SQL text itself can contain personal data, credentials, identifiers, literals, or other sensitive information. It should therefore be treated as potentially sensitive request content.

### 3.5 SQL exports

The export function generates SQL text containing:

- the selected table name
- the current `CREATE TABLE` statement
- up to 5,000 table rows encoded as `INSERT` statements
- the export generation timestamp

If exported rows contain personal or confidential data, the downloaded SQL file contains that information in directly reusable form.

## 4. Processing flow

The normal data flow is:

```text
Browser
  -> DatabaseWorkbench display
  -> same-origin JSON request
  -> DatabaseWorkbench API
  -> DatabaseWorkbench service
  -> configured BASE3 IDatabase
  -> MySQL or MariaDB
  -> JSON response
  -> browser rendering or local SQL download
```

The component itself does not include an external analytics or telemetry service.

The surrounding installation can still place external infrastructure in this path, for example a remote database server, reverse proxy, CDN, centralized logging system, or externally hosted assets. Such infrastructure is outside the component and must be documented by the deployment.

## 5. Browser side processing

The supplied JavaScript keeps its current UI state in memory. This includes:

- the table list
- selected table name
- current page
- page size
- selected sort column and direction
- the currently loaded browse result
- the currently loaded structure result

The client code does not use Local Storage, Session Storage, IndexedDB, or its own cookies.

Database values are rendered into DOM elements through text content rather than being inserted as arbitrary HTML by the component's table rendering helpers.

## 6. Session and CSRF data

The default `SessionDatabaseWorkbenchCsrf` implementation requires an active PHP session.

It stores one CSRF value in the session under:

```text
databaseworkbench.csrf
```

The token is generated from 32 random bytes and hex encoded. It is reused during the active session unless the value is missing or invalid.

The display embeds this token in its client side runtime configuration. Every JSON request sends it in the request body. The endpoint verifies it against the current session value with a timing safe comparison.

The token is a security value and should be protected like other session-bound anti-CSRF material.

DatabaseWorkbench does not define the PHP session identifier cookie, session storage backend, session lifetime, or session invalidation policy. Those are responsibilities of the surrounding runtime.

## 7. Authentication and authorization

The current DatabaseWorkbench implementation does not contain a user identity check, role check, or permission check.

This is a critical deployment property. Anyone who can reach both the display and the API and can satisfy the session and CSRF boundary can request the supported operations. CSRF protection prevents a different class of attack and is not authorization.

The surrounding application or infrastructure must restrict access to trusted database administrators or another explicitly authorized group.

The authorization boundary should protect both:

```text
databaseworkbenchdisplay
databaseworkbenchapi
```

Protecting only the visible display is insufficient because the API endpoint performs the database operations.

## 8. Database privileges

DatabaseWorkbench acts with the privileges of the configured database connection.

The component does not reduce those privileges on a per-user basis. It does not create a restricted secondary connection for read-only operations and does not apply a separate SQL policy to the SQL console.

The database account should therefore follow least privilege. Its permissions define the maximum technical impact of workbench access.

If the configured account can read personal data, alter records, change schemas, or delete tables, DatabaseWorkbench can potentially exercise those capabilities.

## 9. Structured database operations

For table-specific actions, DatabaseWorkbench applies several structural checks:

- the table must exist in the table list returned by the active database
- sort columns must be real columns of the selected table
- insert and update fields are limited to real columns
- generated columns are not written by the structured editor
- row update and delete operations require the table's primary key
- update and delete statements include `LIMIT 1`

Row values are escaped through the configured `IDatabase` implementation before being embedded in the generated SQL.

These measures are part of the structured workbench behavior. They do not apply as a policy layer to SQL entered manually in the SQL console.

## 10. SQL console

The SQL console is an administrative capability. The submitted SQL is passed to the configured database service after basic classification of whether the statement is expected to return rows.

The component does not implement a separate statement allowlist or a read-only SQL parser.

Result responses are limited to 500 rows after the database abstraction returns the result. This protects the browser response size, but it is not a privacy filter and does not prevent a query from selecting sensitive columns.

Administrators should avoid placing unnecessary personal data, credentials, or secrets directly into SQL text, especially in environments where HTTP request bodies or application requests are logged by infrastructure outside DatabaseWorkbench.

## 11. Browse limits and data minimization

The structured browse view uses pagination. The page size is limited to 200 rows per request.

This bounds the amount of table data returned by a single browse action, but it does not enforce purpose based data minimization. An authorized user can navigate through additional pages and can use the SQL console for broader queries.

Any stronger data minimization requirement must be enforced by database views, database permissions, a narrower administration tool, or another access control boundary.

## 12. Binary data

When a string returned for browser display is not valid UTF-8, DatabaseWorkbench replaces it with a marker containing the byte length rather than returning the raw invalid byte sequence as JSON text.

This behavior reduces accidental binary transfer through the normal JSON display path. It is not a content classification mechanism.

The SQL export path operates on the database values used to build SQL literals and can therefore contain the actual exported data rather than the display marker.

## 13. Export processing

Table export is generated in server memory and returned through the JSON endpoint as text. The browser then creates an in-memory `Blob`, generates a temporary object URL, starts a file download, and revokes the object URL.

DatabaseWorkbench does not create a persistent server-side export file.

The resulting download is controlled by the browser and operating system after it is created. The component cannot enforce retention, encryption, access control, backup exclusion, or deletion for downloaded files.

Deployments should define rules for handling database exports, especially when they can contain personal data or credentials.

## 14. Export limit

The export routine includes at most 5,000 rows from a table.

If more rows exist, the generated SQL file is marked as truncated. This limit reduces the amount returned through this interactive feature, but it must not be interpreted as a privacy safeguard. Five thousand rows can still contain a substantial amount of sensitive information.

## 15. Network communication

The supplied browser client sends JSON requests using `fetch()` to the configured DatabaseWorkbench endpoint with `credentials: same-origin`.

The component does not implement direct browser calls to third-party APIs.

Static CSS and JavaScript URLs are generated through the BASE3 asset resolver. Whether those URLs are local or served through other deployment infrastructure depends on the active resolver and hosting model.

The database connection itself can also point to another host. DatabaseWorkbench does not decide whether database traffic remains local, crosses an internal network, or reaches externally operated infrastructure.

## 16. Logging and audit trails

DatabaseWorkbench does not inject a logger and does not implement its own audit log.

It does not itself record:

- which user opened the workbench
- which tables were viewed
- which rows were read
- which SQL was executed
- which rows were changed or deleted
- which tables were truncated or dropped
- which exports were generated

This absence should be considered explicitly for environments that require accountability for privileged database access.

Other layers can still record activity, including:

- web server access logs
- reverse proxy logs
- PHP or application error logs
- database general or audit logs
- network security systems
- infrastructure monitoring

Their configuration, retention, and access permissions are outside this component.

## 17. Error responses

The JSON endpoint catches errors and returns the exception message in the JSON response.

When the database reports an error, the service creates an exception message containing the database error number and database error text. The supplied browser interface translates database errors to a generic localized message for normal display, but an authorized client can inspect the raw JSON response.

Database error messages can reveal schema details, object names, constraints, or other technical information. This is another reason to keep the endpoint within a trusted administrative boundary.

## 18. Caching

The JSON endpoint sets:

```text
Cache-Control: no-store
```

when response headers can still be sent. This instructs HTTP caches not to store the JSON response.

This does not control screenshots, copied data, browser developer tools, downloaded exports, database logs, proxy behavior that ignores headers, or other storage outside the component.

## 19. Persistence performed by DatabaseWorkbench

DatabaseWorkbench has no component-owned database tables, migrations, state store entries, settings store datasets, file repository, or persistent server-side export directory.

Its only component-specific server-side state in the default implementation is the CSRF token stored in the active PHP session.

The component directly changes the connected database when an administrator requests a mutation. Those database changes are domain data changes, not private DatabaseWorkbench persistence.

## 20. Retention and deletion

DatabaseWorkbench defines no retention period for data in the connected database because it does not own that data.

It also defines no automatic retention or deletion process for:

- application database records
- database logs
- HTTP access logs
- infrastructure logs
- downloaded SQL exports
- PHP sessions

The relevant system owners must define those policies.

Deleting or changing a database row through DatabaseWorkbench changes the live database according to the executed SQL. Whether replicas, backups, logs, caches, or downstream systems retain older copies is outside this component.

## 21. Third-party processing

There is no hardcoded third-party processor in DatabaseWorkbench.

Third-party or remote processing can nevertheless result from deployment choices such as:

- a managed MySQL or MariaDB service
- remote application hosting
- a reverse proxy operated by another provider
- centralized logging or monitoring
- CDN or remote asset delivery configured by the host system

These services must be assessed and documented by the installation using them.

## 22. Security and privacy recommendations for deployment

A production installation should, at minimum:

- protect both display and API endpoint with explicit authorization
- permit access only to trusted administrators
- use a least-privilege database account
- avoid exposing the endpoint directly to the public internet unless the surrounding security model explicitly requires it
- use encrypted transport where database or HTTP traffic crosses untrusted networks
- configure secure PHP session handling
- decide whether privileged database actions require an audit trail
- assess whether raw database administration is appropriate for personal or special-category data
- define handling and deletion rules for downloaded SQL exports
- review reverse proxy, web server, PHP, and database logging for sensitive request or result data
- ensure backups and replicas are covered by the data retention policy

## 23. Installation-specific privacy record

For a concrete deployment, document at least the following:

| Topic | Installation-specific information |
|---|---|
| Authorized users | Who may access DatabaseWorkbench |
| Authorization boundary | How `databaseworkbenchdisplay` and `databaseworkbenchapi` are protected |
| Database | Which database or schema is exposed |
| Database host | Local, internal, managed, or externally hosted |
| Database account | Account used and granted privileges |
| Data categories | Personal, confidential, security relevant, or other data present in accessible tables |
| Session backend | Where PHP sessions are stored and how long they remain valid |
| HTTP transport | TLS and network boundaries |
| Database transport | Encryption and network boundaries |
| Web and proxy logs | Whether request metadata or bodies are recorded |
| Database logging | Whether queries or administrative actions are logged |
| Audit policy | Whether privileged actions must be attributable to a user |
| Export handling | Storage, protection, transfer, and deletion rules for downloaded SQL files |
| Backups and replicas | Retention and deletion implications |
| External infrastructure | Any provider that receives requests, logs, assets, or database traffic |

## 24. Summary

DatabaseWorkbench is intentionally a powerful database administration component. It does not create a separate personal-data store, but it can expose and modify any data reachable through the configured database account. Its privacy and security properties therefore depend heavily on the surrounding authorization boundary, the database privileges, the sensitivity of the connected data, infrastructure logging, session security, and the handling of downloaded exports.

The component's built-in CSRF protection, pagination, response limits, and export limit reduce specific technical risks. They do not replace authorization, least privilege, audit requirements, or a deployment-specific data protection assessment.
