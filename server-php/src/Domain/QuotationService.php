<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Quotations and their revisions.
 *
 * A quotation is entirely ours: nobody else records what we offered, at what
 * price, valid until when. The items on it are Inventory's and the customer is
 * Contacts'/Books' — we hold their ids and the commercial terms we agreed.
 *
 * REVISIONS ARE NEW ROWS. Editing an accepted quotation in place would destroy
 * the version the customer agreed to, which is the one document that matters
 * when there is an argument about it later.
 */
final class QuotationService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> the created quotation with its lines
     */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'quotation.create');

        $customerAccountId = (int) ($input['customer_account_id'] ?? 0);
        if ($customerAccountId <= 0) {
            Http::validationFailed('Choose a customer for this quotation.', ['field' => 'customer_account_id']);
        }

        $lines = $this->normaliseLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A quotation needs at least one line.', ['field' => 'lines']);
        }

        $settings = $this->settings();
        $validityDays = (int) $settings['quotation_validity_days'];

        return Db::transaction(function () use ($input, $customerAccountId, $lines, $validityDays, $settings) {
            $quotationNo = NumberSeries::next($this->ctx, 'quotation');
            $totals = self::totals($lines);

            $breaches = $this->assessBreaches($lines, $input, $settings);
            $status = $breaches === [] ? 'DRAFT' : 'APPROVAL_PENDING';

            $quotationId = (int) Db::insert('sales_quotations', [
                'cmp_id'                 => $this->ctx->cmpId,
                'fy_id'                  => $this->ctx->fyId,
                'bo_id'                  => $this->ctx->boId,
                'quotation_no'           => $quotationNo,
                'quotation_date'         => self::date($input['quotation_date'] ?? null),
                'revision_no'            => 0,
                'customer_account_id'    => $customerAccountId,
                'contact_id'             => self::text($input['contact_id'] ?? null),
                'crm_opportunity_id'     => self::text($input['crm_opportunity_id'] ?? null),
                'customer_name_snapshot' => self::text($input['customer_name'] ?? null),
                'salesperson_id'         => self::id($input['salesperson_id'] ?? null),
                'territory_id'           => self::id($input['territory_id'] ?? null),
                'channel_id'             => self::id($input['channel_id'] ?? null),
                'price_book_id'          => self::id($input['price_book_id'] ?? null),
                'status'                 => $status,
                'valid_until'            => self::text($input['valid_until'] ?? null)
                    ?? (new \DateTimeImmutable(self::date($input['quotation_date'] ?? null)))->modify('+' . $validityDays . ' days')->format('Y-m-d'),
                'currency_code'          => self::text($input['currency_code'] ?? null) ?? 'INR',
                'exchange_rate'          => (float) ($input['exchange_rate'] ?? 1),
                'subtotal_amount'        => $totals['subtotal'],
                'discount_amount'        => $totals['discount'],
                'estimated_tax_amount'   => $totals['tax'],
                'total_amount'           => $totals['total'],
                'payment_terms'          => self::text($input['payment_terms'] ?? null),
                'delivery_terms'         => self::text($input['delivery_terms'] ?? null),
                'incoterm'               => self::text($input['incoterm'] ?? null),
                'lead_time_days'         => self::id($input['lead_time_days'] ?? null),
                'notes'                  => self::text($input['notes'] ?? null),
                'terms_text'             => self::text($input['terms_text'] ?? null),
                'customer_po_ref'        => self::text($input['customer_po_ref'] ?? null),
                'created_by'             => $this->auth->uuid,
            ], 'quotation_id');

            $this->writeLines($quotationId, $lines);
            $this->raiseApprovals($quotationId, 'quotation', $breaches);

            Audit::record($this->ctx, $this->auth, 'quotation.created', 'quotation', $quotationId, null, [
                'quotation_no' => $quotationNo,
                'total_amount' => $totals['total'],
                'status'       => $status,
            ]);

            return $this->find($quotationId);
        });
    }

    /**
     * A new revision of an existing quotation.
     *
     * The previous revision keeps its row and its status, and the new one points
     * back at it. Nothing is overwritten.
     *
     * @param array<string, mixed> $input
     */
    public function revise(int $quotationId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'quotation.create');

        $original = $this->find($quotationId);
        if ($original === []) {
            Http::notFound('That quotation does not exist.');
        }
        if (in_array($original['status'], ['CONVERTED', 'CANCELLED'], true)) {
            Http::conflict('A ' . strtolower($original['status']) . ' quotation cannot be revised.');
        }

        $lines = $this->normaliseLines($input['lines'] ?? $original['lines']);
        if ($lines === []) {
            Http::validationFailed('A quotation needs at least one line.', ['field' => 'lines']);
        }

        $settings = $this->settings();

        return Db::transaction(function () use ($original, $quotationId, $lines, $input, $settings) {
            $totals = self::totals($lines);
            $breaches = $this->assessBreaches($lines, $input + $original, $settings);

            $newId = (int) Db::insert('sales_quotations', [
                'cmp_id'                 => $this->ctx->cmpId,
                'fy_id'                  => $this->ctx->fyId,
                'bo_id'                  => $this->ctx->boId,
                'quotation_no'           => $original['quotation_no'],
                'quotation_date'         => self::date($input['quotation_date'] ?? null),
                'revision_no'            => (int) $original['revision_no'] + 1,
                'supersedes_id'          => $quotationId,
                'customer_account_id'    => (int) $original['customer_account_id'],
                'contact_id'             => $original['contact_id'],
                'crm_opportunity_id'     => $original['crm_opportunity_id'],
                'customer_name_snapshot' => $original['customer_name_snapshot'],
                'salesperson_id'         => $original['salesperson_id'],
                'territory_id'           => $original['territory_id'],
                'channel_id'             => $original['channel_id'],
                'price_book_id'          => $original['price_book_id'],
                'status'                 => $breaches === [] ? 'DRAFT' : 'APPROVAL_PENDING',
                'valid_until'            => self::text($input['valid_until'] ?? null) ?? $original['valid_until'],
                'currency_code'          => $original['currency_code'],
                'exchange_rate'          => (float) $original['exchange_rate'],
                'subtotal_amount'        => $totals['subtotal'],
                'discount_amount'        => $totals['discount'],
                'estimated_tax_amount'   => $totals['tax'],
                'total_amount'           => $totals['total'],
                'payment_terms'          => self::text($input['payment_terms'] ?? null) ?? $original['payment_terms'],
                'delivery_terms'         => self::text($input['delivery_terms'] ?? null) ?? $original['delivery_terms'],
                'incoterm'               => $original['incoterm'],
                'lead_time_days'         => $original['lead_time_days'],
                'notes'                  => self::text($input['notes'] ?? null) ?? $original['notes'],
                'terms_text'             => $original['terms_text'],
                'customer_po_ref'        => self::text($input['customer_po_ref'] ?? null) ?? $original['customer_po_ref'],
                'created_by'             => $this->auth->uuid,
            ], 'quotation_id');

            $this->writeLines($newId, $lines);
            $this->raiseApprovals($newId, 'quotation', $breaches);

            Db::update('sales_quotations', ['status' => 'REVISION_REQUESTED', 'updated_at' => self::now()], ['quotation_id' => $quotationId]);

            Audit::record($this->ctx, $this->auth, 'quotation.revised', 'quotation', $newId, ['revision_no' => $original['revision_no']], ['revision_no' => (int) $original['revision_no'] + 1]);

            return $this->find($newId);
        });
    }

    /** Move a quotation through its lifecycle, refusing transitions that make no sense. */
    public function transition(int $quotationId, string $action, array $input = []): array
    {
        $quotation = $this->find($quotationId);
        if ($quotation === []) {
            Http::notFound('That quotation does not exist.');
        }

        [$permission, $from, $to] = match ($action) {
            'approve' => ['quotation.approve', ['APPROVAL_PENDING', 'DRAFT'], 'APPROVED'],
            'reject'  => ['quotation.approve', ['APPROVAL_PENDING'], 'REJECTED'],
            'send'    => ['quotation.send', ['DRAFT', 'APPROVED'], 'SENT'],
            'accept'  => ['quotation.create', ['SENT', 'APPROVED'], 'ACCEPTED'],
            'decline' => ['quotation.create', ['SENT', 'APPROVED'], 'REJECTED'],
            'cancel'  => ['quotation.create', ['DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'SENT'], 'CANCELLED'],
            default   => Http::validationFailed('Unknown action "' . $action . '".'),
        };

        Permissions::assert($this->ctx, $this->auth, $permission);

        if (!in_array($quotation['status'], $from, true)) {
            Http::conflict(sprintf(
                'A quotation that is %s cannot be %sed.',
                strtolower(str_replace('_', ' ', (string) $quotation['status'])),
                $action,
            ));
        }

        $changes = ['status' => $to, 'updated_at' => self::now()];
        if ($action === 'approve') {
            $changes['approved_by'] = $this->auth->uuid;
            $changes['approved_at'] = self::now();
            Db::run(
                "UPDATE sales_approval_requests SET status = 'APPROVED', decided_by = :by, decided_at = :at, decision_note = :note
                 WHERE cmp_id = :cmp AND entity_type = 'quotation' AND entity_id = :id AND status = 'PENDING'",
                ['by' => $this->auth->uuid, 'at' => self::now(), 'note' => self::text($input['note'] ?? null), 'cmp' => $this->ctx->cmpId, 'id' => $quotationId],
            );
        }
        if ($action === 'reject') {
            Db::run(
                "UPDATE sales_approval_requests SET status = 'REJECTED', decided_by = :by, decided_at = :at, decision_note = :note
                 WHERE cmp_id = :cmp AND entity_type = 'quotation' AND entity_id = :id AND status = 'PENDING'",
                ['by' => $this->auth->uuid, 'at' => self::now(), 'note' => self::text($input['note'] ?? null), 'cmp' => $this->ctx->cmpId, 'id' => $quotationId],
            );
        }
        if ($action === 'send') {
            $changes['sent_at'] = self::now();
        }
        if (in_array($action, ['accept', 'decline'], true)) {
            $changes['decided_at'] = self::now();
        }

        Db::update('sales_quotations', $changes, ['quotation_id' => $quotationId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record(
            $this->ctx,
            $this->auth,
            'quotation.' . $action,
            'quotation',
            $quotationId,
            ['status' => $quotation['status']],
            ['status' => $to],
            self::text($input['note'] ?? null) ?? '',
        );

        return $this->find($quotationId);
    }

    /** @return array<string, mixed> the quotation with its lines, or [] */
    public function find(int $quotationId): array
    {
        $row = Db::first(
            'SELECT * FROM sales_quotations WHERE quotation_id = :id AND cmp_id = :cmp',
            ['id' => $quotationId, 'cmp' => $this->ctx->cmpId],
        );
        if ($row === null) {
            return [];
        }

        $row['lines'] = Db::all(
            'SELECT * FROM sales_quotation_lines WHERE quotation_id = :id ORDER BY line_no',
            ['id' => $quotationId],
        );
        $row['approvals'] = Db::all(
            "SELECT * FROM sales_approval_requests
             WHERE cmp_id = :cmp AND entity_type = 'quotation' AND entity_id = :id
             ORDER BY approval_id",
            ['cmp' => $this->ctx->cmpId, 'id' => $quotationId],
        );

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $where = [$scope];

        if (!empty($filters['status'])) {
            $where[] = 'q.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['customer_account_id'])) {
            $where[] = 'q.customer_account_id = :customer';
            $params['customer'] = (int) $filters['customer_account_id'];
        }
        if (!empty($filters['salesperson_id'])) {
            $where[] = 'q.salesperson_id = :salesperson';
            $params['salesperson'] = (int) $filters['salesperson_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'q.quotation_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'q.quotation_date <= :to';
            $params['to'] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(q.quotation_no ILIKE :term OR q.customer_name_snapshot ILIKE :term OR q.customer_po_ref ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }
        // Only the latest revision, unless the caller asks for the history.
        if (empty($filters['include_superseded'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM sales_quotations s WHERE s.supersedes_id = q.quotation_id)';
        }

        $clause = implode(' AND ', $where);
        $sortable = ['quotation_date', 'quotation_no', 'total_amount', 'status', 'created_at'];
        $sortColumn = in_array($sort, $sortable, true) ? $sort : 'quotation_date';

        $rows = Db::all(
            "SELECT q.* FROM sales_quotations q WHERE {$clause}
             ORDER BY q.{$sortColumn} {$order}, q.quotation_id {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $params,
        );
        $total = (int) Db::scalar("SELECT COUNT(*) FROM sales_quotations q WHERE {$clause}", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    // -----------------------------------------------------------------------

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private function normaliseLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        $lineNo = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $isService = (bool) ($line['is_service'] ?? false);
            $itemId = self::id($line['item_id'] ?? null);
            if (!$isService && $itemId === null) {
                Http::validationFailed('Every stock line needs an item.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $quantity = round((float) ($line['quantity'] ?? 0), 4);
            $rate = round((float) ($line['rate'] ?? 0), 4);
            if ($quantity <= 0) {
                Http::validationFailed('Quantity must be more than zero.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $discountPc = round((float) ($line['discount_pc'] ?? 0), 3);
            $gross = round($quantity * $rate, 4);
            $discountAmount = isset($line['discount_amount'])
                ? round((float) $line['discount_amount'], 4)
                : round($gross * $discountPc / 100, 4);
            $net = round($gross - $discountAmount, 4);
            $taxPc = round((float) ($line['estimated_tax_pc'] ?? 0), 3);

            $lines[] = [
                'line_no'          => ++$lineNo,
                'item_id'          => $itemId,
                'unit_id'          => self::id($line['unit_id'] ?? null),
                'warehouse_id'     => self::id($line['warehouse_id'] ?? null),
                'is_service'       => $isService,
                'description'      => self::text($line['description'] ?? null),
                'quantity'         => $quantity,
                'rate'             => $rate,
                'discount_pc'      => $discountPc,
                'discount_amount'  => $discountAmount,
                'tax_cat_id'       => self::id($line['tax_cat_id'] ?? null),
                'estimated_tax_pc' => $taxPc,
                'line_amount'      => $net,
                'estimated_tax'    => round($net * $taxPc / 100, 4),
                'bom_id'           => self::id($line['bom_id'] ?? null),
                'is_optional'      => (bool) ($line['is_optional'] ?? false),
                'margin_check_cost' => null,
                'margin_check_at'  => null,
            ];
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $quotationId, array $lines): void
    {
        foreach ($lines as $line) {
            Db::insert('sales_quotation_lines', [
                'quotation_id'      => $quotationId,
                'cmp_id'            => $this->ctx->cmpId,
                'line_no'           => $line['line_no'],
                'item_id'           => $line['item_id'],
                'unit_id'           => $line['unit_id'],
                'warehouse_id'      => $line['warehouse_id'],
                'is_service'        => $line['is_service'],
                'description'       => $line['description'],
                'quantity'          => $line['quantity'],
                'rate'              => $line['rate'],
                'discount_pc'       => $line['discount_pc'],
                'discount_amount'   => $line['discount_amount'],
                'tax_cat_id'        => $line['tax_cat_id'],
                'estimated_tax_pc'  => $line['estimated_tax_pc'],
                'line_amount'       => $line['line_amount'],
                'margin_check_cost' => $line['margin_check_cost'],
                'margin_check_at'   => $line['margin_check_at'],
                'bom_id'            => $line['bom_id'],
                'is_optional'       => $line['is_optional'],
            ], 'line_id');
        }
    }

    /**
     * Which lines breach policy, for approval routing.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{kind:string, limit:float, actual:float, message:string, line_no:int}>
     */
    private function assessBreaches(array $lines, array $input, array $settings): array
    {
        $pricing = new PricingService($this->ctx, $this->auth);
        $maxDiscount = $this->salespersonDiscountLimit(self::id($input['salesperson_id'] ?? null), (float) $settings['require_quotation_approval_above_pc']);

        $breaches = [];
        foreach ($lines as $line) {
            if ($line['item_id'] === null) {
                // A service line has no inventory cost to judge a margin against;
                // only the discount limit applies.
                if ($line['discount_pc'] > $maxDiscount) {
                    $breaches[] = [
                        'kind' => 'discount', 'limit' => $maxDiscount, 'actual' => (float) $line['discount_pc'],
                        'message' => sprintf('Discount of %.2f%% is above the %.2f%% limit.', $line['discount_pc'], $maxDiscount),
                        'line_no' => $line['line_no'],
                    ];
                }
                continue;
            }

            $resolved = $pricing->resolveRate(
                (int) $line['item_id'],
                (float) $line['quantity'],
                self::id($input['customer_account_id'] ?? null),
                self::id($input['channel_id'] ?? null),
                self::id($input['territory_id'] ?? null),
                self::id($input['price_book_id'] ?? null),
            );

            $check = $pricing->checkLine(
                (int) $line['item_id'],
                (float) $line['rate'],
                (float) $line['discount_pc'],
                $resolved['min_margin_pc'],
                $maxDiscount,
            );

            foreach ($check['breaches'] as $breach) {
                $breaches[] = $breach + ['line_no' => $line['line_no']];
            }
        }

        return $breaches;
    }

    private function salespersonDiscountLimit(?int $salespersonId, float $fallback): float
    {
        if ($salespersonId === null) {
            return $fallback;
        }
        $limit = Db::scalar(
            'SELECT max_discount_pc FROM sales_people WHERE salesperson_id = :id AND cmp_id = :cmp',
            ['id' => $salespersonId, 'cmp' => $this->ctx->cmpId],
        );

        return $limit === null ? $fallback : (float) $limit;
    }

    /** @param list<array<string, mixed>> $breaches */
    private function raiseApprovals(int $entityId, string $entityType, array $breaches): void
    {
        foreach ($breaches as $breach) {
            Db::insert('sales_approval_requests', [
                'cmp_id'          => $this->ctx->cmpId,
                'fy_id'           => $this->ctx->fyId,
                'entity_type'     => $entityType,
                'entity_id'       => $entityId,
                'reason_kind'     => $breach['kind'],
                'reason_detail'   => $breach['message'],
                'threshold_value' => $breach['limit'],
                'actual_value'    => $breach['actual'],
                'requested_by'    => $this->auth->uuid,
            ], 'approval_id');
        }
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $row = Db::first('SELECT * FROM sales_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
        if ($row !== null) {
            return $row;
        }

        // First use of the company. Defaults are written so the settings screen
        // has something to show and so every later read is a plain lookup.
        Db::insert('sales_settings', ['cmp_id' => $this->ctx->cmpId], 'cmp_id');

        return Db::first('SELECT * FROM sales_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]) ?? [
            'quotation_validity_days' => 15,
            'require_quotation_approval_above_pc' => 10,
            'credit_control_mode' => 'warn',
            'reserve_on_confirm' => true,
            'default_invoice_basis' => 'delivered',
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    public static function totals(array $lines): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            if (!empty($line['is_optional'])) {
                continue; // An optional line is an offer, not part of the price.
            }
            $subtotal += (float) $line['quantity'] * (float) $line['rate'];
            $discount += (float) $line['discount_amount'];
            $tax += (float) ($line['estimated_tax'] ?? 0);
        }
        $net = $subtotal - $discount;

        return [
            'subtotal' => round($subtotal, 4),
            'discount' => round($discount, 4),
            'tax'      => round($tax, 4),
            'total'    => round($net + $tax, 4),
        ];
    }

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

    private static function date(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return trim($value);
        }

        return gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
