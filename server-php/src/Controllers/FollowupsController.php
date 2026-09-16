<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\InsightService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Follow-ups: the calls we made, the reminders we sent, the dates customers gave us.
 *
 * WHY THIS IS SALES' AND NOT BOOKS'. The balance being chased is Books'. The
 * conversation about it happened to a salesperson, and nobody else was on the
 * call. A promise to pay on the 20th is not an accounting entry — it is a fact
 * about a conversation, and it stays true whether or not the money arrives.
 *
 * WHAT IS RECORDED IS WHAT HAPPENED. There is no "reminder sent" that a dialog
 * can set by opening: drafting is a separate endpoint that writes nothing, and
 * the record is only written when the caller says the contact actually took
 * place. A status nothing can evidence is the same as no status at all.
 *
 * DRAFTED TEXT IS A SUGGESTION, NOT AN ACTION. `draft` returns wording for a
 * person to read, change and send through whatever they already use. This
 * product does not have an outbound mail server and does not pretend to.
 */
final class FollowupsController extends Controller
{
    private const KINDS = ['quotation', 'collection', 'reorder'];
    private const CHANNELS = ['call', 'email', 'whatsapp', 'meeting', 'portal', 'note'];

    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        $params = Http::listParams(['contacted_on', 'promised_on'], 'contacted_on');
        [$scope, $bindings] = $ctx->scopeClause('f');

        $where = [$scope];
        if (($customer = Http::intParam('customer_account_id')) !== null && $customer > 0) {
            $where[] = 'f.customer_account_id = :customer';
            $bindings['customer'] = $customer;
        }
        if (($quotationId = Http::intParam('quotation_id')) !== null && $quotationId > 0) {
            $where[] = 'f.quotation_id = :quotation';
            $bindings['quotation'] = $quotationId;
        }
        if (($kind = Http::param('followup_kind')) !== null && $kind !== '') {
            $where[] = 'f.followup_kind = :kind';
            $bindings['kind'] = $kind;
        }
        if (Http::param('open_promises') === '1') {
            $where[] = "f.outcome = 'open' AND f.promised_on IS NOT NULL";
        }

        $clause = implode(' AND ', $where);

        $rows = Db::all(
            "SELECT f.*, q.quotation_no, o.order_no
               FROM sales_followups f
               LEFT JOIN sales_quotations q ON q.quotation_id = f.quotation_id
               LEFT JOIN sales_orders o     ON o.order_id     = f.order_id
              WHERE {$clause}
              ORDER BY f.{$params['sort']} {$params['order']} NULLS LAST, f.followup_id DESC
              LIMIT {$params['limit']} OFFSET {$params['offset']}",
            $bindings,
        );

        $total = (int) Db::scalar("SELECT COUNT(*) FROM sales_followups f WHERE {$clause}", $bindings);

        Http::list($rows, $total, $params['limit'], $params['offset']);
    }

    /**
     * Record a follow-up that has happened.
     *
     * Note the tense. This is not "schedule a reminder" and not "mark as
     * chased": it is the log of a contact the caller is asserting took place.
     */
    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.create');

        $body = Http::body();
        $kind = (string) ($body['followup_kind'] ?? 'quotation');
        if (!in_array($kind, self::KINDS, true)) {
            Http::validationFailed('Unknown follow-up kind.', ['field' => 'followup_kind']);
        }

        $channel = (string) ($body['channel'] ?? 'note');
        if (!in_array($channel, self::CHANNELS, true)) {
            Http::validationFailed('Unknown contact channel.', ['field' => 'channel']);
        }

        $customerAccountId = (int) ($body['customer_account_id'] ?? 0);
        $quotationId = self::id($body['quotation_id'] ?? null);

        if ($quotationId !== null) {
            $quotation = Db::first(
                'SELECT quotation_id, customer_account_id FROM sales_quotations WHERE quotation_id = :id AND cmp_id = :cmp',
                ['id' => $quotationId, 'cmp' => $ctx->cmpId],
            );
            if ($quotation === null) {
                Http::notFound('That quotation does not exist.');
            }
            $customerAccountId = $customerAccountId > 0 ? $customerAccountId : (int) $quotation['customer_account_id'];
        }

        if ($customerAccountId <= 0) {
            Http::validationFailed('A follow-up needs a customer.', ['field' => 'customer_account_id']);
        }

        $promisedAmount = isset($body['promised_amount']) && is_numeric($body['promised_amount'])
            ? round((float) $body['promised_amount'], 4)
            : null;
        $promisedOn = self::optionalDate($body['promised_on'] ?? null, 'promised_on');

        if ($promisedAmount !== null && $promisedOn === null) {
            Http::validationFailed('A promise to pay needs the date it was promised for.', ['field' => 'promised_on']);
        }

        $followupId = (int) Db::insert('sales_followups', [
            'cmp_id'              => $ctx->cmpId,
            'fy_id'               => $ctx->fyId,
            'bo_id'               => $ctx->boId,
            'followup_kind'       => $kind,
            'customer_account_id' => $customerAccountId,
            'quotation_id'        => $quotationId,
            'order_id'            => self::id($body['order_id'] ?? null),
            'channel'             => $channel,
            'contacted_on'        => self::optionalDate($body['contacted_on'] ?? null, 'contacted_on') ?? gmdate('Y-m-d'),
            'note'                => self::text($body['note'] ?? null),
            'promised_amount'     => $promisedAmount,
            'promised_on'         => $promisedOn,
            'outcome'             => $promisedAmount !== null ? 'open' : 'kept',
            'created_by'          => $auth->uuid,
        ], 'followup_id');

        Audit::record($ctx, $auth, 'followup.recorded', $kind === 'collection' ? 'customer' : 'quotation',
            $quotationId ?? $customerAccountId, null, [
                'channel' => $channel, 'followup_kind' => $kind, 'promised_on' => $promisedOn,
            ]);

        Http::data(Db::first('SELECT * FROM sales_followups WHERE followup_id = :id', ['id' => $followupId]) ?? [], 201);
    }

    /** Close a promise off: kept, broken, or withdrawn. */
    public static function resolve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.create');

        $outcome = (string) (Http::param('outcome') ?? '');
        if (!in_array($outcome, ['kept', 'broken', 'cancelled'], true)) {
            Http::validationFailed('An outcome is kept, broken or cancelled.', ['field' => 'outcome']);
        }

        $existing = Db::first(
            'SELECT * FROM sales_followups WHERE followup_id = :id AND cmp_id = :cmp',
            ['id' => (int) $id, 'cmp' => $ctx->cmpId],
        );
        if ($existing === null) {
            Http::notFound('That follow-up does not exist.');
        }

        Db::update('sales_followups', ['outcome' => $outcome, 'updated_at' => gmdate('Y-m-d H:i:s')], [
            'followup_id' => (int) $id, 'cmp_id' => $ctx->cmpId,
        ]);

        Audit::record($ctx, $auth, 'followup.resolved', 'customer', (int) $existing['customer_account_id'],
            ['outcome' => $existing['outcome']], ['outcome' => $outcome]);

        Http::data(Db::first('SELECT * FROM sales_followups WHERE followup_id = :id', ['id' => (int) $id]) ?? []);
    }

    /**
     * Wording for a follow-up, for a person to send.
     *
     * WRITES NOTHING. Opening a draft is not contacting a customer, and a
     * product that recorded it as one would report a full week of chasing that
     * never happened.
     *
     * Every figure in the draft comes from the record named in the request, read
     * here; nothing in it is supplied by the caller and echoed back, so a draft
     * cannot be used to put words in this product's mouth.
     */
    public static function draft(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        $kind = (string) (Http::param('followup_kind') ?? 'quotation');
        if (!in_array($kind, self::KINDS, true)) {
            Http::validationFailed('Unknown follow-up kind.', ['field' => 'followup_kind']);
        }

        if ($kind === 'quotation') {
            $quotationId = Http::intParam('quotation_id', 0) ?? 0;
            $quotation = Db::first(
                'SELECT quotation_no, revision_no, customer_name_snapshot, customer_account_id,
                        valid_until, total_amount, currency_code, sent_at
                   FROM sales_quotations WHERE quotation_id = :id AND cmp_id = :cmp',
                ['id' => $quotationId, 'cmp' => $ctx->cmpId],
            );
            if ($quotation === null) {
                Http::notFound('That quotation does not exist.');
            }

            $customer = $quotation['customer_name_snapshot'] ?: 'there';
            $validity = $quotation['valid_until']
                ? 'The quoted prices hold until ' . $quotation['valid_until'] . '.'
                : 'The quoted prices are still current.';

            Http::data([
                'kind'    => 'quotation',
                'origin'  => InsightService::aiConfigured() ? 'ai_available' : 'rule',
                'subject' => 'Following up on quotation ' . $quotation['quotation_no'],
                'body'    => "Dear {$customer},\n\n"
                    . "I wanted to check whether you have had a chance to look at quotation {$quotation['quotation_no']}"
                    . ($quotation['revision_no'] > 0 ? " (revision {$quotation['revision_no']})" : '') . ".\n\n"
                    . "{$validity} If anything in it needs adjusting — quantities, delivery timing or terms — "
                    . "tell me and I will revise it.\n\n"
                    . "Kind regards",
                'evidence' => [[
                    'kind' => 'quotation', 'id' => $quotationId,
                    'label' => (string) $quotation['quotation_no'],
                    'note' => 'valid until ' . ($quotation['valid_until'] ?? 'unspecified'),
                ]],
                'note' => 'A suggestion to read and change. Nothing has been sent, and nothing has been recorded — '
                    . 'log the follow-up once you have actually made contact.',
            ]);
        }

        // Collection and reorder drafts are about a customer rather than one of
        // our documents, and the balance in them belongs to Books. This product
        // deliberately does not put a figure it did not read into a draft.
        $customerAccountId = Http::intParam('customer_account_id', 0) ?? 0;
        if ($customerAccountId <= 0) {
            Http::validationFailed('Choose a customer.', ['field' => 'customer_account_id']);
        }

        $name = Db::scalar(
            'SELECT customer_name_snapshot FROM sales_orders
              WHERE cmp_id = :cmp AND customer_account_id = :acc AND customer_name_snapshot IS NOT NULL
              ORDER BY order_date DESC LIMIT 1',
            ['cmp' => $ctx->cmpId, 'acc' => $customerAccountId],
        );
        $customer = is_string($name) && $name !== '' ? $name : 'there';

        Http::data([
            'kind'    => $kind,
            'origin'  => InsightService::aiConfigured() ? 'ai_available' : 'rule',
            'subject' => $kind === 'collection'
                ? 'Outstanding invoices — statement of account'
                : 'Planning your next order',
            'body' => $kind === 'collection'
                ? "Dear {$customer},\n\n"
                    . "Our records show invoices on your account that are now past their due date. "
                    . "I have attached the statement so you can see which ones.\n\n"
                    . "If any of them are in query, tell me which and I will look into it. Otherwise, could you "
                    . "let me know when we can expect payment?\n\n"
                    . "Kind regards"
                : "Dear {$customer},\n\n"
                    . "It has been a little while since your last order and I wanted to check whether you are "
                    . "running low on anything.\n\n"
                    . "If it would help, I can put together a quotation on the same items and quantities as last "
                    . "time for you to adjust.\n\n"
                    . "Kind regards",
            'evidence' => [[
                'kind' => 'customer', 'id' => $customerAccountId, 'label' => $customer,
                'note' => 'balances are read live from Smart Books and are not copied into this draft',
            ]],
            'note' => 'A suggestion to read and change. Nothing has been sent, and nothing has been recorded — '
                . 'log the follow-up once you have actually made contact.',
        ]);
    }

    // -----------------------------------------------------------------------

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            Http::validationFailed('Give the date as YYYY-MM-DD.', ['field' => $field]);
        }

        return trim($value);
    }
}
