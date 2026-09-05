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
only descendants whose entire changed-path set is in the twelve-path explicit
harness allowlist are admitted (unknown tests and package paths also reject).
Each loaded class's raw file blob is checked
against that revision and its Reflection path checked against the real file.
The resulting receipts name the actual candidate HEAD/tree and all loaded paths,
Git blobs and SHA-256 values. A separate receipt names all six actual helper files,
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
labels are required descriptor inputs, not verified server receipts. P2's response
slot describes winning A; competitor response/session/wait provenance still needs
the future controller. P11 currently describes a stale-admission unit, not all
admission variants; P12 describes binding-recheck rejection, not its standalone
actual-store CAS subcase. No descriptor launches a process or executes a scenario.

The barrier uses create-exclusive stage markers, fixed nonsecret fields and
bounded monotonic deadlines. Offline timeout/signal tests are not real lock-wait
proof. Actual contention must later be observed, not inferred from a sleep.

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

Still absent / NOT RUN: real SQL seed writers, full successful coordinator commit,
P1-P12 worker processes/controller and session fault injection, actual
MySQL schema/engine/SQL acceptance, historical-source split-state replay control,
native WordPress `class-wpdb.php`/WordPress version pins and reconnect acceptance.
Live driver observation provenance, complete admission/standalone-store subcases,
real renewal projection and fault log event manifests also remain future work.
There is no automatic DDL or cleanup path. ECPay remains subscription logistics /
member selection only (`supports_token=false`), not recurring-payment capability.
