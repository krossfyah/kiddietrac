<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What to call an invoice that has not come due yet.
 *
 * Anthony, 2026-09-04: "for invoices that are not due yet and show as open mark these
 * as SCHEDULED - full sweep to find all areas to make this change".
 *
 * On this platform 159 of the 166 open invoices are not due until later in the month.
 * Shown as "Open" they read as money somebody is late paying, which made an outstanding
 * list of 166 items out of a problem that is actually 7 items long. "Scheduled" says the
 * true thing: it is on the calendar and nobody has missed anything.
 *
 * DERIVED, NEVER STORED. Nothing about the invoice changes when its due date arrives —
 * only its relation to today — so a stored status would need a nightly job to flip
 * scheduled back to open. That is one more scheduled task to drift silently, and this
 * codebase has already been bitten by exactly that: the late-fee cron named a command
 * that did not exist and never ran once. A stored value would also need clearing
 * everywhere an invoice is paid or voided. Derived at the moment of display it cannot go
 * stale and cannot be forgotten.
 *
 * The mirror of this lives in kt-polish.js as KT.invoiceStatus, for the screens that
 * render a status without asking the server. The two must agree; both are documented
 * with the same rules.
 */
final class InvoiceStatus
{
    /**
     * Statuses that read as "owed right now" and can therefore be future-dated instead.
     *
     * Deliberately excluded:
     *   partial — real money has already been received against it, and that fact
     *             outranks the calendar.
     *   draft   — not issued at all, which is a different thing from not yet due and
     *             must not be hidden behind a word that sounds deliberate.
     *   overdue — past its due date by definition. If one ever says otherwise the data
     *             is wrong, and quietly relabelling it would hide that.
     */
    private const OPEN_NOW = ['open', 'sent', 'unpaid', 'issued', 'outstanding'];

    /** True when this invoice is issued, unpaid, and not due until after today. */
    public static function isScheduled(?string $status, mixed $dueAt, ?string $today = null): bool
    {
        $s = strtolower(trim((string) $status));
        if (! in_array($s, self::OPEN_NOW, true)) {
            return false;
        }

        $due = self::day($dueAt);
        if ($due === null) {
            return false;
        }

        /* A due date is a CALENDAR DAY, not an instant. Compared as text against the
           agency's own today — converting a wall-clock date through a timezone is the
           bug that makes it name the day before. */
        return $due > ($today ?? AgencyTime::today());
    }

    /** The status to show: the stored one, or 'scheduled' when it is not due yet. */
    public static function display(?string $status, mixed $dueAt, ?string $today = null): string
    {
        return self::isScheduled($status, $dueAt, $today)
            ? 'scheduled'
            : strtolower(trim((string) $status));
    }

    /**
     * The status as a person reads it.
     *
     * A zero balance is 'Paid' whatever the status column says — a caller that knows
     * the balance passes it, and that answer wins, because money received is not a
     * matter of interpretation.
     */
    public static function label(?string $status, mixed $dueAt, ?float $balanceDue = null, ?string $today = null): string
    {
        if ($balanceDue !== null && $balanceDue <= 0.0) {
            return 'Paid';
        }

        return ucfirst(self::display($status, $dueAt, $today));
    }

    /** YYYY-MM-DD from a date, a datetime, or a Carbon — or null if unusable. */
    private static function day(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = substr(trim((string) $v), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }
}
