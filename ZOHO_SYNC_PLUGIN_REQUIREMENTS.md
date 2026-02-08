# Zoho Sync Plugin Requirements (Moodle)

Version: 1.0  
Date: 2026-02-06  
Target plugin: `local_zohosync` (separate repository)

## 1. Purpose

Build a dedicated Moodle plugin that synchronizes student data from Zoho CRM/Zoho Forms into Moodle so multiple plugins (including Job Portal) can consume the same trusted, site-wide student metadata.

The plugin must:
- Keep Zoho as source of truth for CRM-owned fields.
- Publish site-wide fields via Moodle custom profile fields.
- Keep sensitive/private integration metadata in plugin-owned tables.
- Provide reliable sync (webhook + scheduled reconcile), observability, and controlled access.

## 2. Goals and Non-Goals

### 2.1 Goals
- Sync selected student attributes from Zoho to Moodle users.
- Support near-real-time updates and periodic full reconciliation.
- Provide deterministic user matching rules.
- Support field mapping UI (Zoho field -> Moodle destination).
- Provide robust logs, retries, conflict handling, and admin tooling.
- Expose stable helper APIs for consuming plugins.

### 2.2 Non-Goals (Phase 1)
- Bi-directional updates from Moodle to Zoho.
- Sync of attachments/documents from Zoho.
- Complex workflow engine or lead lifecycle automation.
- Replacing Moodle enrollment logic.

## 3. High-Level Architecture

- Plugin type: `local`
- Plugin name: `local_zohosync`
- Data sources:
  - Zoho CRM module API (primary)
  - Optional Zoho Forms webhook payloads
- Ingestion modes:
  - Pull mode (scheduled cron full/incremental sync)
  - Push mode (webhook endpoint for event-driven updates)
- Destinations:
  - Moodle custom profile fields (`user_info_data`) for site-wide consumption
  - Plugin local tables for sync metadata, logs, and sensitive data

### 3.1 Proposed Components
- `settings.php`: admin configuration (OAuth, mapping, sync behavior)
- `webhook.php`: secure ingest endpoint
- `classes/service/zoho_client.php`: Zoho API wrapper
- `classes/service/matcher.php`: user matching logic
- `classes/service/mapper.php`: mapping and transformation logic
- `classes/service/sync_service.php`: orchestration
- `classes/task/incremental_sync_task.php`: scheduled incremental sync
- `classes/task/full_reconcile_task.php`: nightly full sync
- `classes/task/retry_failed_task.php`: retry failed records
- `cli/sync_full.php`: on-demand full sync
- `cli/sync_incremental.php`: on-demand incremental sync
- `cli/retry_failed.php`: manual retry
- `cli/test_connection.php`: diagnostics

## 4. Data Ownership Model

- Zoho-owned fields:
  - course, batch, joining date, fee status, academic details, etc.
  - Moodle must treat these as read-only snapshots.
- Moodle-owned fields:
  - resume upload, job applications, placement stages (Job Portal plugin).

Rule: if field is marked "Zoho-owned", manual edits in Moodle must either be blocked or overwritten by next sync.

## 5. Functional Requirements

## 5.1 Configuration and Setup

FR-001: Admin can enable/disable plugin globally.  
FR-002: Admin can configure Zoho region (`com`, `in`, `eu`, `au`, `jp`).  
FR-003: Admin can configure OAuth credentials:
- Client ID
- Client Secret
- Refresh Token
- Accounts base URL (derived from region by default)

FR-004: Admin can configure source module name (for example `Leads`, `Contacts`, or custom module).  
FR-005: Admin can configure batch size and rate-limit settings.  
FR-006: Admin can configure webhook secret/token and endpoint behavior.  
FR-007: Admin can configure sync schedule parameters:
- incremental interval
- full reconcile time
- retry policy

## 5.2 Identity Resolution

FR-010: Matching strategy supports priority order:
1. `zoho_student_id` exact match (preferred)
2. Email exact match (case-insensitive)
3. Optional phone match (disabled by default)

FR-011: On ambiguous match (multiple Moodle users), record is marked `conflict` and no write occurs.  
FR-012: On no match, behavior depends on setting:
- `create_user_if_missing = false` (default): skip and log
- `create_user_if_missing = true`: create user with minimum required fields

FR-013: Matching rules are configurable and auditable.

## 5.3 Field Mapping

FR-020: Admin can map Zoho fields to:
- Moodle custom profile fields
- Plugin local columns (for sensitive or internal-only attributes)

FR-021: Mapping supports transforms:
- text trim
- case normalize
- date format normalize
- enum map (Zoho values -> Moodle canonical values)
- numeric cleanup

FR-022: Mapping supports "required" flag:
- If required field missing/invalid, sync record fails with explicit reason.

FR-023: Mapping supports visibility classification:
- `public_to_student`
- `manager_only`
- `admin_only`

FR-024: Mapping must support direction:
- `zoho_to_moodle`
- `moodle_to_zoho`

Note: `moodle_to_zoho` direction is designed in Phase 1 but activated in Phase 2 rollout.

FR-025: Admin can choose table/field targets only from a validated destination registry (not free-text SQL targets).

FR-026: Mapping supports write mode per field:
- `replace` (default)
- `merge_if_empty` (fill only when destination empty)
- `append` (for selected note-like text destinations only)

FR-027: Mapping supports value-level filters (optional):
- apply only when source condition matches (for example `status = active`)
- skip when source value is null/blank

FR-028: Mapping editor provides dry-run preview for a sample payload:
- resolved match user
- transformed values
- destination table/field writes
- validation errors before activation

FR-029: Plugin stores mapping version history so changes are auditable and reversible.

## 5.3.1 Mapping Engine Design (Detailed)

The mapping engine should be rule-driven and strongly validated.

Core principle:
- Admin can decide destination table/field, but only from approved, typed destinations exposed in a registry.

Supported source objects:
- `zoho.record.<field_api_name>`
- `moodle.profile.<field_shortname>`
- `moodle.jobportal.<field_key>` (for write-back flows)

Supported destination objects:
- `profile.<shortname>` (Moodle custom profile field)
- `table.<tablename>.<column>` (whitelisted plugin table column)
- `zoho.<module>.<field_api_name>` (for Moodle -> Zoho write-back)

Destination registry requirements:
- Each destination entry includes:
  - `destobject`
  - `datatype` (`text`, `int`, `decimal`, `date`, `datetime`, `enum`, `bool`)
  - `allowed_directions`
  - `visibility`
  - `is_sensitive`
  - `is_writable`
- Registry is shipped with safe defaults and can be extended by admin only via controlled UI.

Validation pipeline when saving mapping:
1. Ensure direction is allowed by destination registry.
2. Ensure source and destination data types are compatible (or transform exists).
3. Ensure no conflicting active mappings target same destination with incompatible priorities.
4. Ensure required identifier mappings are present.
5. Ensure capability constraints for sensitive destinations.

Recommended mapping precedence:
1. Exact field mapping with highest priority number.
2. Conditional mappings.
3. Default/fallback mapping.

Conflict behavior:
- If multiple active mappings produce different values for same destination field in same run:
  - choose highest priority mapping
  - log conflict event in run item log with both candidate values.

Example mappings:
- `zoho.record.Course_Name` -> `profile.training_course` (`replace`)
- `zoho.record.Batch_Name` -> `profile.training_batch` (`replace`)
- `zoho.record.Fee_Status` -> `table.local_zohosync_snapshot.sensitive_fee_status` (`replace`, `manager_only`)
- `moodle.jobportal.shortliststatus` -> `zoho.Candidates.Candidate_Status` (`replace`)

## 5.4 Sync Execution

FR-030: Incremental sync reads changed records since last successful cursor/timestamp.  
FR-031: Full reconcile sync iterates complete source dataset and reconciles drift.  
FR-032: Webhook processing supports single-record immediate upsert.  
FR-033: Per-record transaction handling:
- apply mapping
- resolve user
- write destination fields
- write audit log

FR-034: Sync is idempotent:
- repeated same payload does not create duplicate updates or duplicate logs.

FR-035: Failures are isolated per record; one bad record must not stop entire batch.  
FR-036: Failed records go to retry queue with capped retries and exponential backoff.

## 5.5 Data Exposure

FR-040: Expose helper APIs for other plugins:
- get latest snapshot for user
- get last sync status for user
- get field visibility metadata

FR-041: Site-wide fields are readable via standard Moodle profile APIs where applicable (custom profile fields).  
FR-042: Sensitive fields must not be exposed to users without capability.

## 5.6 Operational UI

FR-050: Admin dashboard page includes:
- Last successful sync time
- Last failed sync time
- Records processed today
- Failures by reason
- Conflict count

FR-051: Sync logs table with filters:
- date range
- status
- source module
- user/email/zoho id
- error reason

FR-052: Manual actions:
- Run incremental now
- Run full reconcile now
- Retry selected failed records
- Reprocess one Zoho record ID

## 5.7 Notifications

FR-060: Optional admin notifications for:
- auth failure
- sync stuck/failing repeatedly
- conflict spike threshold exceeded

FR-061: Notifications can be email + Moodle notifications.

## 6. Non-Functional Requirements

NFR-001: Security
- Credentials stored encrypted using Moodle config conventions.
- No secrets in logs.
- Webhook requests verified before processing.

NFR-002: Performance
- Must process at least 10,000 records in nightly full sync within operational window.
- Batch processing memory-safe; no full dataset load into memory.

NFR-003: Reliability
- At-least-once processing with idempotent writes.
- Recoverable from process interruptions.

NFR-004: Observability
- Structured logs with correlation ID / run ID.
- Metrics for processed, updated, skipped, failed.

NFR-005: Maintainability
- Strict service-layer separation (client, mapper, matcher, sync orchestrator).
- Unit-testable transformation and matching logic.

NFR-006: Compatibility
- Compatible with supported Moodle versions used by organization (define exact version matrix during project setup).

## 7. Data Model (Proposed)

## 7.1 `local_zohosync_config_map`
Stores mapping definitions.

Suggested columns:
- `id`
- `mappingkey` (stable key, unique)
- `direction` (`zoho_to_moodle` | `moodle_to_zoho`)
- `sourceobject` (for example `zoho.record.Course_Name`)
- `destobject` (for example `profile.training_course`)
- `desttype` (`profilefield` | `localfield` | `zohofield`)
- `destkey` (profile shortname or local column key or Zoho API key)
- `transformrule` (json text)
- `conditionrule` (json text, optional)
- `writemode` (`replace` | `merge_if_empty` | `append`)
- `priority` (int, default 100)
- `isrequired` (0/1)
- `visibility` (`public_to_student` | `manager_only` | `admin_only`)
- `isactive` (0/1)
- `versionno`
- `createdby`
- `approvedby` (nullable)
- `timecreated`
- `timemodified`

Indexes:
- unique `mappingkey`
- index `direction`
- index `isactive`
- index `priority`

## 7.1A `local_zohosync_dest_registry`
Stores approved destination table/field entries available in mapping UI.

Suggested columns:
- `id`
- `destobject` (unique; for example `table.local_jobportal_profiles.resumestatus`)
- `datatype` (`text` | `int` | `decimal` | `date` | `datetime` | `enum` | `bool`)
- `allowdirection_in` (0/1)
- `allowdirection_out` (0/1)
- `visibility` (`public_to_student` | `manager_only` | `admin_only`)
- `issensitive` (0/1)
- `iswritable` (0/1)
- `isactive` (0/1)
- `notes` (nullable)
- `timecreated`
- `timemodified`

## 7.1B `local_zohosync_map_history`
Stores immutable mapping revisions for audit and rollback.

Suggested columns:
- `id`
- `mappingid`
- `versionno`
- `snapshotjson`
- `changedby`
- `changecomment`
- `timecreated`

## 7.2 `local_zohosync_student_map`
Stores source-to-user mapping state.

Suggested columns:
- `id`
- `zoho_record_id`
- `zoho_student_id`
- `userid`
- `matchedbymode` (`zohoid` | `email` | `phone` | `manual`)
- `matchconfidence` (int 0-100)
- `last_source_updated_at`
- `last_synced_at`
- `syncstatus` (`ok` | `failed` | `conflict` | `skipped`)
- `lasterrorcode`
- `lasterrormessage`
- `timecreated`
- `timemodified`

Indexes:
- unique `zoho_record_id`
- index `userid`
- index `zoho_student_id`
- index `syncstatus`

## 7.3 `local_zohosync_snapshot`
Stores latest synced values (for local/private fields and quick reads).

Suggested columns:
- `id`
- `userid`
- `zoho_record_id`
- `payloadhash` (for idempotency)
- `snapshotjson` (normalized canonical payload)
- `sensitivejson` (restricted fields)
- `source_updated_at`
- `synced_at`
- `timecreated`
- `timemodified`

## 7.4 `local_zohosync_run`
One row per sync run.

Suggested columns:
- `id`
- `runid` (uuid)
- `runtype` (`webhook` | `incremental` | `full` | `manual`)
- `startedat`
- `endedat`
- `status` (`running` | `success` | `partial` | `failed`)
- `totalrecords`
- `processed`
- `updated`
- `skipped`
- `failed`
- `conflicts`
- `summaryjson`

## 7.5 `local_zohosync_run_item`
Per-record processing log.

Suggested columns:
- `id`
- `runid` (FK to run table)
- `zoho_record_id`
- `userid` (nullable)
- `action` (`insert` | `update` | `skip` | `conflict`)
- `status` (`success` | `failed`)
- `errorcode`
- `errormessage`
- `attemptcount`
- `nextretryat`
- `timecreated`

Indexes:
- index `runid`
- index `status`
- index `nextretryat`
- index `zoho_record_id`

## 7.6 `local_zohosync_outbox` (Optional for Moodle -> Zoho)
Stores pending write-back events for reliable outbound sync.

Suggested columns:
- `id`
- `eventkey` (unique idempotency key)
- `sourceplugin` (for example `local_jobportal`)
- `sourceentity` (for example `application`)
- `sourceentityid`
- `zoho_record_id`
- `module`
- `payloadjson`
- `payloadhash`
- `status` (`pending` | `sent` | `failed` | `deadletter`)
- `attemptcount`
- `nextretryat`
- `lasterrorcode`
- `lasterrormessage`
- `timecreated`
- `timemodified`

Indexes:
- unique `eventkey`
- index `status`
- index `nextretryat`
- index `zoho_record_id`

## 8. Moodle Profile Field Strategy

Create custom profile fields for site-wide reuse, for example:
- `zoho_student_id`
- `training_course`
- `training_batch`
- `joining_date`
- `academic_score`
- `fee_status` (or safer derived status like `fee_cleared`)

Guidelines:
- Use stable shortnames.
- Prefer `menu` fields for enums where possible.
- Avoid storing raw money amounts in public profile fields.
- Keep sensitive values in plugin table + capability-gated access.

## 9. Zoho API and Auth Requirements

## 9.1 OAuth
- Use refresh-token flow to obtain access token.
- Token refresh must auto-retry once on `401`.
- Store token expiry metadata to avoid unnecessary refresh calls.

## 9.2 Rate Limiting
- Respect Zoho API limits.
- Configurable throttling and backoff.
- Automatic retry on transient API errors (`429`, `5xx`) with jitter.

## 9.3 Region Handling
- Endpoint domains must be region-specific.
- Region mismatch must be surfaced as clear admin diagnostic.

## 10. Webhook Requirements

WH-001: Provide HTTPS endpoint path in plugin (for example `/local/zohosync/webhook.php`).  
WH-002: Verify secret/token header or signature before processing.  
WH-003: Reject unsigned/invalid requests with `401/403`.  
WH-004: Return quick ACK and process payload safely.  
WH-005: De-duplicate repeated event deliveries using event ID or payload hash.  
WH-006: Log raw payload metadata only (not secrets, avoid excessive PII).

## 11. Sync Rules and Conflict Policy

Rule set:
- Source update timestamp older than local synced timestamp: skip.
- Required mapped field missing: fail record.
- Ambiguous match: mark `conflict`, no write.
- Invalid transform (date/enum/number): fail record with reason.

Conflict handling:
- Admin UI list of unresolved conflicts.
- Manual resolve action: map Zoho record to Moodle user.
- Reprocess after resolve.

## 12. Security and Privacy Requirements

- Capabilities:
  - `local/zohosync:manageconfig`
  - `local/zohosync:runsync`
  - `local/zohosync:viewlogs`
  - `local/zohosync:viewsensitive`
  - `local/zohosync:resolveconflicts`
- Sensitive fields hidden unless `viewsensitive`.
- Audit all manual actions (config changes, manual sync, conflict resolution).
- Comply with Moodle privacy API:
  - metadata provider
  - export/delete user data handlers where applicable

## 13. Integration Contract for Consumer Plugins

Expose stable PHP services:
- `local_zohosync\api::get_snapshot_for_user(int $userid): array`
- `local_zohosync\api::get_visible_field(int $userid, string $fieldkey, context $context): mixed`
- `local_zohosync\api::get_sync_status_for_user(int $userid): array`

Consumer guidance:
- Prefer profile fields for common attributes.
- Use API for sensitive or computed fields.
- Do not directly read internal plugin tables from other plugins.

## 14. Admin UX Requirements

Pages:
1. `Settings`: auth, region, module, behavior
2. `Field Mapping`: source->destination mappings
3. `Run Dashboard`: latest run health and metrics
4. `Run History`: drill-down per run
5. `Conflicts`: unresolved identity/data conflicts
6. `Diagnostics`: connection test, API ping, auth status

UX expectations:
- Clear validation and inline error messages.
- Safe defaults.
- Confirmation prompts for destructive or high-impact actions.

## 15. CLI and Scheduled Tasks

CLI commands:
- `php local/zohosync/cli/sync_incremental.php [--limit=] [--dry-run]`
- `php local/zohosync/cli/sync_full.php [--from=YYYY-MM-DD] [--dry-run]`
- `php local/zohosync/cli/retry_failed.php [--max=]`
- `php local/zohosync/cli/test_connection.php`

Scheduled tasks:
- Incremental every N minutes (configurable)
- Full reconcile nightly at configured hour
- Failed retry every 15 min (configurable)
- Cleanup old logs daily

## 16. Error Handling Matrix (Minimum)

- Auth refresh failed -> mark run failed, notify admin.
- Rate limit exceeded -> retry with backoff.
- Network timeout -> retry with backoff.
- Invalid mapping config -> fail fast with configuration error.
- Record transform error -> fail item, continue batch.
- DB write exception -> fail item, continue batch.

## 17. Observability and Reporting

Metrics per run:
- total source records
- matched users
- updated users
- skipped unchanged
- failed records
- conflicts
- average processing time per record

Store run summary JSON for trend analysis.

## 18. Testing Requirements

## 18.1 Unit Tests
- Mapper transforms
- Enum conversions
- Matcher priority rules
- Idempotency checks

## 18.2 Integration Tests
- OAuth token refresh flow
- Incremental sync happy path
- Full reconcile with mixed records
- Conflict scenarios
- Retry queue processing

## 18.3 Security Tests
- Webhook signature/token validation
- Capability enforcement on logs/sensitive fields
- Secret redaction in logs

## 18.4 Performance Tests
- Synthetic dataset of at least 25k records
- Validate runtime and memory profile under configured batch size

## 19. Deployment and Rollout Plan

Phase 1:
- Plugin scaffolding, settings, OAuth client, basic pull sync, logs.

Phase 2:
- Field mapping UI, profile field writes, run dashboard.

Phase 3:
- Webhook ingest, retries, conflict resolver UI.

Phase 4:
- Hardening, diagnostics, privacy API, performance tuning.

Phase 5 (optional, after stabilization):
- Enable `moodle_to_zoho` mappings.
- Add outbox-based write-back tasking and loop-prevention safeguards.
- Roll out write-back per field group (application status first, then additional fields).

Pilot strategy:
- Start with read-only dry-run mode.
- Enable writes for limited cohort.
- Validate data correctness.
- Expand to all users.

## 20. Acceptance Criteria (MVP)

- Admin can configure Zoho auth and test connection successfully.
- Incremental sync updates mapped Moodle profile fields.
- Full reconcile runs and produces correct run summary.
- Conflicts are captured and visible in UI.
- Failed records retry successfully after transient errors.
- Sensitive fields are permission-protected.
- Consumer plugin can read synced fields reliably.

## 21. Open Decisions Required Before Build

1. Exact Zoho source module and final field API names.
2. Final identity key policy (`zoho_student_id` availability across all records).
3. Whether auto-create Moodle users is allowed.
4. Which fee-related values are safe for student visibility.
5. Required Moodle versions and PHP versions for support.
6. Expected max daily record volume and SLA.

## 22. Suggested Initial Field Map (Example)

- `Student_ID` -> `profile.zoho_student_id` (required)
- `Email` -> matcher fallback (required for fallback path)
- `Course_Name` -> `profile.training_course`
- `Batch_Name` -> `profile.training_batch`
- `Join_Date` -> `profile.joining_date`
- `Academic_Percentage` -> `profile.academic_score`
- `Fee_Status` -> `profile.fee_status` (or internal-only table + derived public flag)
- `Last_Modified_Time` -> `student_map.last_source_updated_at`

## 23. Repository Bootstrap Checklist

When creating new repo:
- Initialize as Moodle local plugin structure.
- Add CI:
  - PHP lint
  - PHPUnit
  - Moodle coding style checks
- Add docs:
  - `README.md`
  - `ARCHITECTURE.md`
  - `CONFIGURATION.md`
  - `OPERATIONS.md`
- Add sample env/config template for non-secret defaults.
