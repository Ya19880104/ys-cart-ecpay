# Subscription product-SQL harness: B1 offline and B2 mysqli slice

The offline modes validate an allocation and exact product sources, or capture
product-generated DDL, without connecting. B2 adds a separately admitted real
mysqli execution path for **P1, P3–P10, P11a–h and P12a–b only**. Its implementation and offline
regressions do not prove SQL acceptance: each case still requires a reviewed clean
source, a fresh root allocation, and actual server/worker/readback receipts.
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
`offline-design` and `sql-execution` are recognized; offline mode never promotes
either into execution. All tuple fields must equal the explicit environment values.
No password or selection token belongs in arguments, allocation or evidence. A
SQL run needs a fresh root-owned allocation, session-only credentials, reviewed
connection code and a freshly pinned product pair.

Success is one JSON result and exit 0; rejection is a typed code and exit 2.
Both offline modes report zero connection attempts and SQL statements, `sql_execution=NOT RUN`
and `native_wpdb=PREREQUISITE UNSATISFIED`. Existing phases are never reused.
Captured DDL is retained in the fresh phase with its SHA-256 receipt.

## B2 execution entry

PHP **8.5 is primary**, with PHP 8.2 as the affected compatibility gate. Explicitly
load `mysqli` in the parent using `-n -d extension_dir=<runtime-ext> -d extension=mysqli`.
Use the same CLI with `--driver=adapter --mode=execute --phase=<unique-phase>
--evidence-root=<existing-local-directory>`. `YS_ECPAY_SQL_RUN` points to the
nonsecret B1 run envelope: exact keys `version,phase,cases,allocations,source_heads,
runtime_sha256,driver`, version 1, driver `adapter`. Cases are an ordered unique
subset of `P1,P3,P4,P5,P6,P7,P8,P9,P10,P11a,P11b,P11c,P11d,P11e,P11f,P11g,P11h,P12a,P12b`; every case has a distinct
prefix and its own seven-key `sql-execution` allocation. Every other SQL case is
rejected before a phase or worker is created.

Supply `YS_TEST_MYSQL_DSN`, `YS_TEST_MYSQL_DB`, `YS_TEST_MYSQL_USER`,
`YS_TEST_MYSQL_PASSWORD`, and the three source-root environment variables only in
the current process/session. The parent checks the common literal tuple and gives
each private child its allocated prefix. No credential defaults, password arguments,
packet dumps, or retained secret configuration exist. The worker rechecks allocation
expiry, case, exact tuple, runtime and clean source immediately before connecting.
Caller connector callbacks and supplied/capture handles cannot acquire admission.
Only P7/A receives a second handle created by the same canonical connector inside
the owned CLI child; both complete allocation/context receipts must match.

The parent creates no database connection. Ordinary workers initially own one physical mysqli
session; only P8–P10 A replaces its connection once. P7 uses one child with two
independent physical handles and two role receipts, as detailed below. B remains in autocommit and reads after A's completion. A first verifies
the MySQL 8.4 server/session and absence of all six literal names in the assigned
existing disposable database, then executes the actual five TableMaker CREATEs and
the options fixture. Both workers verify all captured columns and indexes plus
SHOW CREATE and InnoDB metadata. Unsupported DDL shapes fail closed. No CREATE
DATABASE, ALTER, repair, DROP, reuse, or automatic cleanup is performed. Collision
and failed runs retain their artifacts and tables; a later run needs fresh prefixes.

Real raw statement results are retained, including MySQL defaults, `option_id`,
COUNT column names and SELECT row counts. `evaluateMysql` independently validates
native schema/session traces, distinct A/B connection IDs, finite dispatches,
precise sent/actual/presented contracts and B's complete six-table readback. P1 requires the
committed profile/selection pair plus actual renewal projection; P11 requires the
original finite rejection and unchanged full rows. Re-labeling capture evidence
cannot satisfy this evaluator. JSON reports only the cases actually accepted,
lists unrun cases, and keeps WordPress native/reconnect/contention gates unproven.

P3 suppresses only its first post-consume pre-COMMIT session verification, then
requires actual rollback and the original pair. P4 sends COMMIT successfully and
presents a controlled acknowledgement failure: B must prove the committed pair,
including after the protective rollback. This is not packet-loss evidence. P5
suppresses COMMIT transport, then requires actual rollback and the original pair.
P6 suppresses the pre-COMMIT verification and rollback, and requires the product
to poison and close A exactly once before any further A SQL. Only P6/A has a null
final readback; B must remain open and prove the complete original six-table state.
Every fault is bound to its case, role, statement kind and one-shot dispatch slot;
unexpected faults, actual/presented differences, logs or stderr fail the case.

## Product custody and permitted fixture boundaries

Core is pinned to `47b07b523445492c163b26ba7047c19d64911c0b`; Affiliate is pinned
read-only to `18c609a1b94cca28e57c2ce4f225f25662555ccd`. All three roots must be clean.
ECPay product bytes are pinned to `445adc76c4dc6653abdd228b529bad636eef5d42`;
only descendants whose entire changed-path set is in the twenty-one-path explicit
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
adapter used by the separately admitted connector; it does not itself connect and
is not native wpdb. Its limited
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
The B2 `runMysql` path is separately admitted; native WordPress still refuses.

The B1 `SubscriptionSqlWorker::run` path uses explicitly supplied capture sessions.
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
are separately labeled from product writes. The capture path performs no CREATE;
neither path performs cleanup.

`metadataPlan` enumerates exactly twenty read statements: server/session settings,
the six literal table engines and six sets of SHOW CREATE / FULL COLUMNS / INDEX.
`captureMetadata` binds the exact plan, captures raw rows and SQL hashes with observer
origin, and returns `UNVALIDATED CAPTURE METADATA` / `schema_acceptance=UNSATISFIED`.
It is a raw capture contract only, not a MySQL metadata normalizer or schema validator.
The separate B2 path supplies MySQL8.4 version/settings admission, before-CREATE
collision handling and full product-column/index/default/engine comparison.
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

P1/P11a–h have retained MySQL 8.4.11 acceptance at ECPay `ff255d6069f9904446079695e92c9654c126511a`,
paired with the fixed Core/Affiliate anchors. P3–P6 have separate retained MySQL 8.4.11
acceptance at ECPay `bd4f7189ac83032049b35a7dfb1fd6cfcf5a303c` with those same anchors.
P8–P10 have separate retained MySQL 8.4.11 acceptance at ECPay
`2338306ccd5ca6e9c4d698f5dfe16f6bd9ffbaef` with those same anchors.
The current scope literal is `MYSQLI P1-P12 SLICE`; only the returned
case list identifies the cases actually executed. Each P8–P10 A uses one fresh canonical
second connection at its named consume/preverify/COMMIT seam. The same wrapper retains
both connector admissions, one checked old close, the original schema CID and a fixed
new-server read. Old A, new A and observer B must have three distinct physical IDs;
A has two connector attempts and B one. The pending product SQL stays byte-identical,
including its old fence; every dispatched result must equal its presentation. No replacement
transaction/owner initialization or generic replay is added.

P12a–b now admit the existing two-worker schedules and a finite controller release.
While the owned children run, the parent verifies setup/A/B seam scope and distinct
A/B identities, then publishes one create-exclusive release bound to the complete
B seam proof. Its `MYSQLI INTERFERENCE RELEASE` marker references B's ID, never a
parent connection. The independent evaluator requires exact release keys/scope,
prior digest and referenced identity; legacy capture controls keep their explicit
`IPC CAPTURE CONTROL ONLY` scope. Missing, foreign or duplicate evidence fails.
B's controlled update stays in autocommit with exact old-byte predicate, affected1
and immediate readback. P12a retains claim-time principal rejection after profile
CAS; P12b tests the actual Store BINARY CAS against retained old bytes. Neither
commits A: the original profile and only B's declared selection delta survive rollback.
Both retain full six-table equality, exact SQL sent/results/counts and empty logs.
P12a–b have separate retained MySQL 8.4.11 acceptance at ECPay
`7ab883972ed870728885245656b69f7b0cfc792c` with the same fixed pair: two cases,
four workers/connections, parent zero, exact interference deltas and rollback
readback, followed by normal owned-server shutdown. IPC/capture controls and
release markers alone never prove SQL execution.

P7 now has a finite one-child/two-handle execution path. The controller launches
only A; that CLI creates canonical A and B, runs the existing A(a,b) schedule and
then B(b,null) using the same B object. Its single `MYSQLI TWO-HANDLE WORKER`
stdout envelope has role A, connection_attempts=2 and exactly two `receipts` refs,
A and B. The two role receipts share the actual child stderr stream; each retains
its own application log. The parent reopens both refs and validates phase/case/role
and topology, while continuing to report one process. Ordinary single-receipt
envelopes remain unchanged.

The swap occurs once after A profile CAS and immediately before the actual Plugin
claim; the captured transaction object/fence remains A. B retains every read made
through the swapped global, including its initial full SHOW TABLE STATUS, four
selection reads and six-table snapshot before B role initialization. Its later
session/schema/final reads stay in the same trace; no trace reset, early synthetic
identity, replacement or third connection is added. Real Store rejection returns
claim_rejected; A consumes/commits nothing and rolls back on A. B performs no
writes or transaction commands and remains usable. Both role readbacks must equal
the complete original six-table baseline. All SQL is sent, actual equals presented,
and both application logs are empty. CLI completion closes both owned handles;
any connect/role/persist/close failure remains UNPROVEN and retains each available
handle trace independently.

P7 has retained MySQL 8.4.11 acceptance at ECPay
`817947df182fc1a25280ed5bbb84a519e405d590` with the same fixed pair: one child,
two canonical handles and distinct IDs, complete B trace, A rollback and exact
six-table baseline equality, followed by normal owned-server shutdown.

P2 now admits only its existing two-child contention schedule. The controller
waits for A's `wait-observed` receipt, freshly binds setup/A/B/wait phase, roles,
scopes and IDs, and requires one exact data_lock_waits join row for the allocated
database, subscriptions table and PRIMARY key 41. A B pre-dispatch arrival or a
timeout never grants release. The shared finite oracle independently rebuilds the
observer SQL hash; the final verdict additionally requires that exact SQL to have
been dispatched on A with the same successful raw row. One create-exclusive
`MYSQLI CONTENTION RELEASE` binds the complete wait proof and A's observer ID;
the parent owns no connection. Its exact keys, scope, prior digest and ID are
revalidated by the evidence collector. Capture controls keep their prior scope.

A must complete one profile CAS, one token consume and an ordinary acknowledged
COMMIT after its named pause. B's actual locked read then sees the committed
authority, returns `subscription_authority_changed` 409 and rolls back without
CAS, consume or COMMIT. B's full native SHOW TABLE STATUS row is retained and its
literal options name/InnoDB engine checked. Both six-table readbacks must equal
the exact generation-4/consumed-4 durable state. Rehashed false waits, wrong
identities/scope/predicates/SQL, premature or duplicate releases, altered COMMIT
presentation and a stale_generation response cannot become acceptance.

P2 is SQL NOT RUN pending a fresh clean-source root allocation and independent
server receipts. The local IPC, admission and oracle controls prove no SQL.
Genuine native WordPress reconnect remains NOT RUN with its separate prerequisites;
the mysqli slice label never supplies native wpdb or other unexecuted case proof.
Still NOT IMPLEMENTED: historical dbeb split-state loader/control, materialized-period/YSOrder
guard, full provider lifecycle/catalog and actual renewal order/charge coverage.
Native `class-wpdb.php` path/version/hash prerequisites remain UNSATISFIED.
Only the separately allocated B2 slice may execute its six CREATEs; there is no
automatic cleanup path. ECPay remains subscription logistics /
member selection only (`supports_token=false`), not recurring-payment capability.
