<?php
declare(strict_types=1);

/**
 * Minimal wpdb adapter for the Core YSPaymentDetailStore regression harnesses.
 *
 * It models the observable storage contract shared by Core v2.57+:
 * projected reads, exact raw payment_detail CAS preimages, authoritative
 * readback, and both the legacy and canonical-text predicate shapes. It does
 * not implement any ECPay behavior; the four consuming tests still execute
 * the real provider and Core production classes.
 */
abstract class PaymentDetailWpdbAdapter
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** Current raw JSON column; null = SQL NULL, false = order row missing. */
    public string|null|false $value = null;

    /** Additional projected scalar columns keyed by their SQL column name. */
    public array $columns = [];

    /** @var list<string> */
    public array $queries = [];

    public string $read_error = '';
    public string $write_error = '';
    public string $fail_write_containing = '';
    public mixed $force_update_result = null;
    public mixed $concurrent_writer = null;
    public mixed $before_write = null;
    public int $reads = 0;
    public int $updates = 0;

    /** Most recent raw CAS preimage decoded from an UPDATE predicate. */
    public ?string $last_payment_detail_preimage = null;

    /** `legacy` or `canonical_text`, matching the predicate Core emitted. */
    public ?string $last_payment_detail_predicate = null;

    public function prepare(string $sql, ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1) ?? $sql;
        }

        return $sql;
    }

    public function get_row(string $sql): ?object
    {
        ++$this->reads;
        if ('' !== $this->read_error) {
            $this->last_error = $this->read_error;
            return null;
        }
        if (false === $this->value) {
            return null;
        }

        $projection = ['payment_detail'];
        if (preg_match('/SELECT\s+(.+?)\s+FROM\s+/is', $sql, $match)) {
            $projection = array_values(array_filter(array_map(
                static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
                explode(',', $match[1])
            )));
        }

        $row = [];
        foreach ($projection as $column) {
            $row[$column] = 'payment_detail' === $column
                ? $this->value
                : ($this->columns[$column] ?? null);
        }

        // Return the just-read snapshot, then let the concurrent writer mutate
        // storage before the caller's UPDATE reaches the CAS predicate.
        if (null !== $this->concurrent_writer) {
            ($this->concurrent_writer)($this);
        }

        return (object) $row;
    }

    public function query(string $sql): int|false
    {
        ++$this->updates;
        $this->queries[] = $sql;

        if (null !== $this->before_write) {
            ($this->before_write)($this, $sql);
        }
        if ('' !== $this->write_error) {
            $this->last_error = $this->write_error;
            return false;
        }
        if ('' !== $this->fail_write_containing && str_contains($sql, $this->fail_write_containing)) {
            $this->last_error = 'simulated failure';
            return false;
        }
        if (null !== $this->force_update_result) {
            return $this->force_update_result;
        }

        if (str_contains($sql, 'payment_detail IS NULL')) {
            $this->last_payment_detail_preimage = null;
            $this->last_payment_detail_predicate = 'is_null';
            if (null !== $this->value) {
                return 0;
            }
        } else {
            $pattern = "/AND\\s+(CAST\\(\\s*payment_detail\\s+AS\\s+CHAR\\s*\\)|payment_detail)\\s*=\\s*'((?:''|[^'])*)'/is";
            if (!preg_match($pattern, $sql, $match)) {
                return 0;
            }

            $this->last_payment_detail_predicate = str_starts_with(strtoupper($match[1]), 'CAST')
                ? 'canonical_text'
                : 'legacy';
            $this->last_payment_detail_preimage = str_replace("''", "'", $match[2]);
            if ($this->last_payment_detail_preimage !== (string) $this->value) {
                return 0;
            }
        }

        if (!preg_match("/SET\\s+payment_detail\\s*=\\s*'((?:''|[^'])*)'\\s*,\\s*updated_at\\s*=/is", $sql, $set)) {
            return 0;
        }

        $this->value = str_replace("''", "'", $set[1]);
        return 1;
    }
}
