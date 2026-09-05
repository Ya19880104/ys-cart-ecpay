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

The allocation is a local JSON design receipt with `kind`, `host`, integer `port`,
`database`, `user`, `prefix` and integer future Unix `expires_at`. Only
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
read-only to `18c609a1b94cca28e57c2ce4f225f25662555ccd`. Both roots must be clean.
ECPay product bytes are pinned to `445adc76c4dc6653abdd228b529bad636eef5d42`;
test-only descendants are admitted, with each loaded class's raw file blob checked
against that revision and its Reflection path checked against the real file.
The resulting receipts name the actual candidate HEAD/tree and all loaded paths,
Git blobs and SHA-256 values. Helper files themselves are test code, not product
authority; pin their commit and hashes when preserving a run.

`SubscriptionProductSqlFixture` loads nine real product classes. Its `dbDelta`
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

The current regressions invoke the real coordinator's early authority rejection,
real subscription lock/CAS generation and real durable store consume generation.
They do not claim that a successful whole coordinator transaction ran. No fake
coordinator/model/provider class replaces the loaded product classes.

The barrier uses create-exclusive stage markers, fixed nonsecret fields and
bounded monotonic deadlines. Offline timeout/signal tests are not real lock-wait
proof. Actual contention must later be observed, not inferred from a sleep.

## Verification and remaining work

`v041_subscription_product_sql_offline_guards.php` runs isolated CLI children and
retains their complete stdout/stderr/result receipts. `v042_subscription_product_sql_capture_behavior.php`
checks product-generated DDL/SQL, connector refusal and IPC behavior. The latter's
test-only `YS_ECPAY_SQL_HARNESS_HELPER_ROOT` admits isolated helper mutations; it
does not change product roots or disable product blob checks.

Still absent / NOT RUN: scenario seed/readback helpers, successful coordinator
request/config/catalog wiring, P1-P12 workers and session fault injection, actual
MySQL schema/engine/SQL acceptance, historical-source split-state replay control,
native WordPress `class-wpdb.php`/WordPress version pins and reconnect acceptance.
There is no automatic DDL or cleanup path. ECPay remains subscription logistics /
member selection only (`supports_token=false`), not recurring-payment capability.
