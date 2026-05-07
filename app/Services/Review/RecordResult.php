<?php

namespace App\Services\Review;

/**
 * GH #39 — outcome of `PayeeRuleRecorder::record()`. The three terminal
 * states are mutually exclusive: a single record() call produces exactly
 * one of these.
 */
enum RecordResult: string
{
    /** A fresh `payee_rules` row was inserted. */
    case Created = 'created';

    /**
     * A rule already covered the (bank_slug, counterparty_name) pair;
     * the recorder did NOT overwrite it. The caller's interactive
     * decision still ran through `TransactionActions`, but it leaves
     * the existing rule intact — only the override path may mutate
     * an existing rule.
     */
    case AlreadyExists = 'already_exists';

    /**
     * A guard tripped — either the resolution level is too uncertain
     * (≥ 4), the counterparty name is blank, or the name appears on
     * the denylist (bank-internal or operator-name). The transaction
     * itself was still decided manually; only the rule-side write was
     * suppressed.
     */
    case SkippedByGuard = 'skipped_by_guard';
}
