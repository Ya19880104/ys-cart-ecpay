# Subscription product-SQL harness: offline checkpoint only

This is not the two-session SQL acceptance runner. The CLI can validate an offline
allocation and exact product sources, or capture product-generated DDL. Every
`execute` request is hard-refused before any connector can be invoked, even when
an allocation says `sql-execution`. It never creates, seeds, alters or drops tables.
The old `live_subscription_selection_two_connections.php` is a separate runner;
its handwritten CAS is not evidence for this product-path checkpoint.

## Entry and configuration

Run `live_subscription_product_pair.php` with each of these explicit arguments:

- `--driver=adapter` (`wordpress` returns prerequisite-unsatisfied).
- `--mode=preflight` or `--mode=capture-ddl`.
- `--phase=<unique-lowercase-phase>`.
- `--evidence-root=<existing-local-directory>`.

Required environment names: `YS_ECPAY_SQL_ALLOCATION`, `YS_TEST_MYSQL_DSN`,
`YS_TEST_MYSQL_DB`, `YS_TEST_MYSQL_USER`, `YS_ECPAY_SQL_PREFIX`, `YS_CORE_ROOT`,
`YS_ECPAY_ROOT`, `YS_AFFILIATE_ROOT`. There are no host/database/user/root defaults.
The DSN must be a literal loopback IPv4 address and port. Prefixes must match
`ecps_` plus twelve lowercase hexadecimal characters plus `_`.

The allocation is a local JSON design receipt with exactly `kind`, `host`, integer
`port`, `database`, `user`, `prefix` and integer future Unix `expires_at`. All other
fields, including secret-like fields, are rejected; other five fields must be
nonempty strings, never coerced scalars. Only
`offline-design` and `sql-execution` are recognized; neither grants SQL authority
in this checkpoint. All tuple fields must equal the explicit environment values.
No password or selection token belongs in arguments, allocation or evidence. A
future SQL implementation needs a fresh root-owned allocation, session-only
credentials, reviewed connection code and a freshly pinned product pair. Merely
editing this receipt does not authorize that implementation or execution.

Success is one JSON result and exit 0; rejection is a typed code and exit 2.
Both report zero connection attempts and SQL statements, `sql_execution=NOT RUN`
and `native_wpdb=PREREQUISITE UNSATISFIED`. Existing phases are never reused.
Captured DDL is retained in the fresh phase with its SHA-256 receipt.

## Product custody and permitted fixture boundaries

Core is pinned to `47b07b523445492c163b26ba7047c19d64911c0b`; Affiliate is pinned
read-only to `18c609a1b94cca28e57c2ce4f225f25662555ccd`. All three roots must be clean.
ECPay product bytes are pinned to `445adc76c4dc6653abdd228b529bad636eef5d42`;
only descendants whose entire changed-path set is in the twenty-path explicit
harness allowlist are admitted (unknown tests and package paths also reject).
Each loaded class's raw file blob is checked
against that revision and its Reflection path checked against the real file.
The resulting receipts name the actual candidate HEAD/tree and all loaded paths,
Git blobs and SHA-256 values. A separate receipt names all eleven actual helper files,
HEAD blobs and raw SHA-256 values. `CANONICAL` requires a clean helper worktree and
all raw files equal its HEAD; dirty precommit controls are `UNFROZEN AUTHORING`.
Helpers are test code, not product authority. A helper override requires a named
`mutation-*` phase and produces `NAMED MUTATION`, never canonical evidence.

`SubscriptionProductSqlFixture` loads fifteen real product classes (eleven Core,
four ECPay), including genuine YSProduct and all invoked normalization dependencies.
Its `dbDelta`
boundary only records DDL from five actual `YSTableMaker` methods (products,
subscriptions, orders, order items, order-created outbox); it cannot reach a
session. The sixth DDL is an explicitly hand-authored logical options fixture,
not a claim about a pinned native WordPress schema.

`SubscriptionSqlSession::forCapture` records statements and returns the recorder's
declared rows/affected count. That is only a statement-origin proof, never SQL,
locking, storage-engine or commit correctness. `fromMysqli` is a supplied-handle
adapter for later separately authorized work; it does not connect, is not native
wpdb, and has not been exercised against a server in this checkpoint. Its limited
prepare contract accepts unquoted `%s`, `%d`, `%f`, `%i`, `%%` and exact arity;
unsupported forms and noncanonical integers fail closed.

The regressions invoke real coordinator early rejection, normal coordinator
request resolution, subscription lock/CAS generation and durable store consume
generation. Normal request/config/catalog fixtures route resolution into real
Plugin/StoreSelector, load real YSProduct rows, preserve billing/invoice/gateway/
parcel/items, and produce the complete expected CVS profile in the actual CAS.
The recorder returns zero affected rows: the normal coordinator returns stale
generation and rolls back, with zero claim and zero COMMIT. This proves normal
request traversal and statement origin, not a successful whole transaction.

`SubscriptionSqlRequestBoundary` explicitly supplies WordPress request functions,
fixed shipping registry/method/catalog, settings, operability and maintenance-lease
fixtures. These are not native WordPress, registry/lifecycle/restriction-engine or
provider integration proof. Coordinator, model, profile service, selector and
durable-store product bodies are never replaced.

`seedPlan(prefix, now, token)` returns deterministic product/subscription/options
rows plus real normalized generation-three authority; planned product columns are
checked against actual captured DDL. It does not issue a token via a live writer or
send any seed to SQL. `request(token)` supplies the normal owned fixture request.
`readPair(boundary, id, token)` exposes raw profile/selection bytes, hashes, six
profile-authority fields and consumed tuple, rejecting missing/malformed authority.
All tokens in receipts are represented by digest only.

`SubscriptionSqlScenario` supplies P1–P12 worker descriptors and pure observation
oracles. Old-pair comparison is byte-exact; new-pair comparison restricts the full
typed JSON value to the literal allowed transition, preserving object/list identity
and all other fields. Counts, diagnostics and sentinel equality are checked. These
are synthetic oracle unit tests, not independently observed SQL facts. Evidence
labels are required descriptor inputs, not verified server receipts. These legacy
unit descriptors are not used to accept B1 worker evidence: their P2 slot describes
only winning A, P11 a stale-admission unit, and P12 binding recheck only. The B1
collector below separately validates both role envelopes and all explicit subcases.
No descriptor itself launches a process or executes a scenario.

The barrier uses create-exclusive stage markers, fixed nonsecret fields and
bounded monotonic deadlines. Offline timeout/signal tests are not real lock-wait
proof. Actual contention must later be observed, not inferred from a sleep.

## B1 supplied capture workers and independent evidence

`SubscriptionSqlController` admits exact run/source/runtime/environment tuples,
requires distinct per-case prefixes, and supervises only its owned IPC children.
Its `runOffline` launches the `ipc-worker` echo lane, not SQL work. Private worker
packets travel in stdin; neither token nor password belongs in argv or receipts.
Both `execute` and native WordPress still refuse unconditionally.

`SubscriptionSqlWorker::run` accepts only explicitly supplied capture sessions.
The v046/v047 control parent launches independent PHP A/B children, invokes real
coordinator/model/selector/store methods, and supplies finite complete SQL result
controls. Its capture files simulate committed/interfered rows, not MySQL storage.
All twenty P1-P12 subcases now have actual product call schedules and independent
offline receipt oracles. P7 is one worker with two independent supplied handles,
not two-process contention. P11h is narrowly the actual legacy claim missing-fence
rejection. P12b invokes the actual store CAS with retained old bytes, not a replaced
coordinator or handwritten product CAS. P1 calls the real normal renewal projection
with global explicitly B; the half-credential projection form remains a negative.

The finite fault state distinguishes attempted/sent/affected, actual versus presented
results, poison-before-close and stable wrapper/changed transport. B1 replacement
handles and connection IDs are capture controls, never native reconnect proof.
P2 has a concrete read-only `data_lock_waits` join and bound A/B wait/lock statement
receipts. Its capture fixture cannot prove real contention. Sleep alone cannot
satisfy the oracle; B must return authority_changed, not a postcommit stale replay.

`SubscriptionSqlSchema::seed` dispatches real selector issuance through the supplied
capture boundary, requires six empty-table counts, preserves typed SQL NULL and
checks the complete issued record. Full table snapshots retain immutable rows,
sentinels, options bytes and zero orders/outbox. Setup and controlled interference
are separately labeled from product writes. There is no automatic CREATE or cleanup.

`metadataPlan` enumerates exactly twenty read statements: server/session settings,
the six literal table engines and six sets of SHOW CREATE / FULL COLUMNS / INDEX.
`captureMetadata` binds the exact plan, captures raw rows and SQL hashes with observer
origin, and returns `UNVALIDATED CAPTURE METADATA` / `schema_acceptance=UNSATISFIED`.
It is a raw capture contract only, not a MySQL metadata normalizer or schema validator.
Actual MySQL8.4 version/settings admission, before-CREATE collision handling, full
product-column/index/default/engine comparison and worker acceptance integration
remain a separately reviewed prerequisite before any future SQL execution unlock.
The metadata contract is intentionally not accepted by the scenario oracle as server
proof. No supplied capture row, even one labeled InnoDB, can complete that gate.

`SubscriptionSqlEvidence` reopens immutable phase-confined raw artifacts, validates
exact roles/source/runtime/session/fault keys, rehashes barriers and compares exact
product write SQL. It independently checks complete product responses, full typed
profile transition, sibling/row bytes, P2 competitor/wait provenance, P8 replay
bytes and P12 interference baselines. Expected application log messages and counts
are exact; stderr and unknown log bytes always fail. Rehashed tamper controls must
fail a normal verdict rather than relying on a stale hash or a child crash.

## Verification and remaining work

`v041_subscription_product_sql_offline_guards.php` runs isolated CLI children and
retains their complete stdout/stderr/result receipts. `v042_subscription_product_sql_capture_behavior.php`
checks product-generated DDL/SQL, connector refusal and IPC behavior. v043 uses
private retained local Git clones for clean/path custody plus allocation/helper
guards. v044 exercises normal product wiring, seed/readback and synthetic scenario
oracles, requiring a retained exactly empty application log. Test-only
`YS_ECPAY_SQL_HARNESS_HELPER_ROOT` requires `YS_ECPAY_SQL_MUTATION_PHASE=mutation-*`;
it does not change product roots or disable product blob checks. Clear both for a
normal run and set `YS_ECPAY_SQL_REQUIRE_CANONICAL_HELPERS=1` for final v044 custody.
v045 covers controller/IPC/barrier admission; v046 covers typed setup, true issuance,
finite faults and raw metadata capture; v047 runs all twenty capture schedules and
independent rehashed adversarial evidence controls. Source mutations use retained
named helper copies and require green controls, rc1 ordinary failures, and empty
parent/child stderr. These are source-only offline tests, not SQL acceptance.

Still NOT RUN: every true MySQL seed/commit/rollback/locking/reconnect scenario,
actual server/schema/engine acceptance and genuine native WordPress reconnect.
Still NOT IMPLEMENTED: reviewed SQL connector/unlock and integrated metadata/schema
acceptance, historical dbeb split-state loader/control, materialized-period/YSOrder
guard, full provider lifecycle/catalog and actual renewal order/charge coverage.
Native `class-wpdb.php` path/version/hash prerequisites remain UNSATISFIED.
There is no automatic DDL or cleanup path. ECPay remains subscription logistics /
member selection only (`supports_token=false`), not recurring-payment capability.
