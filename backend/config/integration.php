<?php

declare(strict_types=1);

/**
 * Which directions of an agency integration are open.
 *
 * KiddieTrac became the source of record for NEW families and children on 2026-08-19:
 * records created here are pulled INTO the connected platform, not the other way round.
 * What that change did not do is close the old door — the connected side could still
 * push a family, a child or a parent login it had created, so both systems could still
 * invent the same household independently and each believed it was the origin.
 *
 * `inbound_create` shuts that door. It governs CREATION ONLY:
 *
 *   false (default) — an inbound family/child/guardian that KiddieTrac has never seen is
 *                     declined and recorded. Records that already exist keep receiving
 *                     updates, so a name, a date of birth or a withdrawal raised on the
 *                     other side still lands.
 *   true            — the previous behaviour, for a backfill or a new tenant onboarding.
 *
 * Set INTEGRATION_INBOUND_CREATE=true in .env to reopen it. It is a switch rather than
 * deleted code on purpose: turning a data feed back on should not require a deploy, and
 * the next agency to onboard will need it open for exactly one backfill.
 *
 * Deliberately NOT covered — none of these create a household, and stopping them would
 * break features that depend on the other system remaining the source:
 *   · centres/providers   · invoices   · payroll   · waitlist leads
 *   · withdrawals, deactivations and restores of records that already exist
 */
return [
    'inbound_create' => env('INTEGRATION_INBOUND_CREATE', false),
];
