<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;

/**
 * Live reads and writes against books.aicountly.com.
 *
 * Books is the ONLY authority for the financial transaction: the voucher, the
 * ledger, GST, the receivable, the receipt and its bill-by-bill allocation,
 * e-Invoice and e-Way Bill. This product initiates those documents and keeps
 * the UUID Books hands back — never a second copy of the voucher, never a
 * receivable balance of its own, never a tax it calculated itself.
 *
 * Every number a screen shows for "invoice value", "outstanding" or "credit
 * limit" is fetched here on the request that renders it.
 */
final class BooksClient extends ApiClient
{
    /**
     * Voucher types, as `books_voucher_types.vch_type_id`.
     *
     * These are stable platform ids (PrintConfigurationService names the same
     * values), but they are still only a FALLBACK: voucherTypes() reads the live
     * list, so a deployment that numbers them differently is followed rather
     * than overwritten.
     */
    public const VCH_SALES       = 18;
    public const VCH_PURCHASE    = 11;
    public const VCH_CREDIT_NOTE = 2;
    public const VCH_DEBIT_NOTE  = 3;
    public const VCH_RECEIPT     = 13;
    public const VCH_PAYMENT     = 9;
    public const VCH_CONTRA      = 1;
    public const VCH_JOURNAL     = 5;

    private string $authorization = '';
    private string $serviceKey = '';
    private string $actorUuid = '';

    public function service(): string
    {
        return 'books';
    }

    protected function productionBase(): string
    {
        return 'https://books.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://books.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BOOKS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;
        $this->serviceKey = '';

        return $this;
    }

    public function withService(string $actorUuid): self
    {
        $this->serviceKey = Env::get('BOOKS_SERVICE_KEY');
        $this->actorUuid = $actorUuid;
        $this->authorization = '';

        return $this;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        if ($this->serviceKey !== '') {
            return ['X-Service-Key' => $this->serviceKey, 'X-Actor-Uuid' => $this->actorUuid];
        }

        return ['Authorization' => $this->authorization];
    }

    private function call(string $method, string $path, ?array $body = null, bool $required = false, array $extraHeaders = []): array
    {
        return $this->request($method, $path, $body, $this->authHeaders() + $extraHeaders, $required);
    }

    // -----------------------------------------------------------------------
    // Parties and ledgers — Books owns the accounting identity
    // -----------------------------------------------------------------------

    /** Ledger accounts. `nature` narrows to debtors / creditors where the API supports it. */
    public function accounts(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'masters/accounts' . self::query($filters + $ctx->asQuery()));
    }

    public function account(Context $ctx, int $accountId): array
    {
        return $this->call('GET', 'masters/accounts/' . $accountId . self::query($ctx->asQuery()));
    }

    /** @param array<string, mixed> $payload */
    public function createAccount(Context $ctx, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'masters/accounts', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function taxCategories(Context $ctx): array
    {
        return $this->call('GET', 'masters/tax-categories' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    public function voucherTypes(Context $ctx): array
    {
        return $this->call('GET', 'registers/types' . self::query($ctx->asQuery()));
    }

    public function companySettings(Context $ctx): array
    {
        return $this->call('GET', 'company/settings' . self::query($ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Vouchers — Books creates and owns them. We send a request and keep the id.
    // -----------------------------------------------------------------------

    /**
     * Create a draft voucher.
     *
     * The idempotency key is ours and is kept for the life of the command: a
     * retry after a timeout must reach the SAME key, so Books replays the
     * original answer instead of writing a second invoice. That is the whole
     * defence against a double-posted sale, and it is why the key is stored in
     * our integration-command row before the call is made rather than generated
     * at the call site.
     *
     * @param array<string, mixed> $payload
     */
    public function createVoucherDraft(Context $ctx, int $vchTypeId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'vouchers/drafts', [
            'vch_type_id' => $vchTypeId,
            'payload'     => $payload,
        ] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function postVoucherDraft(Context $ctx, int $draftId, string $idempotencyKey): array
    {
        return $this->call('POST', 'vouchers/drafts/' . $draftId . '/post', $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * Create and post in one call from our point of view.
     *
     * Returns the posted voucher, or the failure with the draft id so the
     * command can be retried at the post step without re-creating the draft.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool, status:int, body:?array, error:?string, draft_id?:int}
     */
    public function createAndPostVoucher(Context $ctx, int $vchTypeId, array $payload, string $idempotencyKey): array
    {
        $draft = $this->createVoucherDraft($ctx, $vchTypeId, $payload, $idempotencyKey . ':draft');
        if (!$draft['ok']) {
            return $draft;
        }

        $draftId = (int) ($draft['body']['data']['draft_id'] ?? $draft['body']['data']['id'] ?? $draft['body']['draft_id'] ?? 0);
        if ($draftId === 0) {
            return ['ok' => false, 'status' => $draft['status'], 'body' => $draft['body'], 'error' => 'books_draft_id_missing'];
        }

        $posted = $this->postVoucherDraft($ctx, $draftId, $idempotencyKey . ':post');
        $posted['draft_id'] = $draftId;

        return $posted;
    }

    public function voucher(Context $ctx, int $voucherId): array
    {
        return $this->call('GET', 'vouchers/' . $voucherId . self::query($ctx->asQuery()));
    }

    public function cancelVoucher(Context $ctx, int $voucherId, string $reason, string $idempotencyKey): array
    {
        return $this->call('POST', 'vouchers/' . $voucherId . '/cancel', ['reason' => $reason] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    /** Voucher register — the live list of invoices, receipts or bills behind any of our screens. */
    public function registers(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'registers' . self::query($filters + $ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Receivables, payables, credit control — read live, never stored
    // -----------------------------------------------------------------------

    /**
     * Bill-by-bill outstanding. This IS the receivables/payables report; there is
     * no billing_receivables table anywhere in this product and there never will
     * be, because a second copy of a balance is a second answer.
     */
    public function billByBill(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'reports/bill-by-bill' . self::query($filters + $ctx->asQuery()));
    }

    public function accountLedger(Context $ctx, int $accountId, array $filters = []): array
    {
        return $this->call('GET', 'reports/account-ledger' . self::query(['account_id' => $accountId] + $filters + $ctx->asQuery()));
    }

    public function accountSummary(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'reports/account-summary' . self::query($filters + $ctx->asQuery()));
    }

    public function dayBook(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'reports/day-book' . self::query($filters + $ctx->asQuery()));
    }

    public function salesDashboard(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'dashboard/sales' . self::query($filters + $ctx->asQuery()));
    }

    public function purchaseDashboard(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'dashboard/purchase' . self::query($filters + $ctx->asQuery()));
    }

    /** Invoices a receipt could be allocated against. Books decides what is still open. */
    public function allocatableInvoices(Context $ctx, int $receiptVoucherId): array
    {
        return $this->call('GET', 'receipt-vouchers/' . $receiptVoucherId . '/allocatable-invoices' . self::query($ctx->asQuery()));
    }

    public function allocatableBills(Context $ctx, int $paymentVoucherId): array
    {
        return $this->call('GET', 'payment-vouchers/' . $paymentVoucherId . '/allocatable-bills' . self::query($ctx->asQuery()));
    }

    public function saveReceiptSettlement(Context $ctx, int $receiptVoucherId, array $allocations, string $idempotencyKey): array
    {
        return $this->call('POST', 'receipt-vouchers/' . $receiptVoucherId . '/settlement', ['allocations' => $allocations] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function savePaymentSettlement(Context $ctx, int $paymentVoucherId, array $allocations, string $idempotencyKey): array
    {
        return $this->call('POST', 'payment-vouchers/' . $paymentVoucherId . '/settlement', ['allocations' => $allocations] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    // -----------------------------------------------------------------------
    // Statutory — Books owns the IRN and the e-Way Bill. We only ask.
    // -----------------------------------------------------------------------

    public function generateEInvoice(Context $ctx, int $voucherId, string $idempotencyKey): array
    {
        return $this->call('POST', 'gst/einvoice/generate', ['vch_txn_id' => $voucherId] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function eInvoiceStatus(Context $ctx, int $voucherId): array
    {
        return $this->call('GET', 'gst/einvoice/' . $voucherId . '/status' . self::query($ctx->asQuery()));
    }

    public function cancelEInvoice(Context $ctx, int $voucherId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'gst/einvoice/' . $voucherId . '/cancel', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function generateEWayBill(Context $ctx, int $voucherId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'gst/eway/generate', ['vch_txn_id' => $voucherId] + $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function eWayBillStatus(Context $ctx, int $voucherId): array
    {
        return $this->call('GET', 'gst/eway/' . $voucherId . '/status' . self::query($ctx->asQuery()));
    }

    public function updateEWayBill(Context $ctx, int $voucherId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'gst/eway/' . $voucherId . '/update', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function cancelEWayBill(Context $ctx, int $voucherId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'gst/eway/' . $voucherId . '/cancel', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    // -----------------------------------------------------------------------
    // Printing — reuse Books' own invoice PDF rather than re-rendering a second one
    // -----------------------------------------------------------------------

    public function salesInvoicePdfUrl(Context $ctx, int $voucherId): string
    {
        return $this->apiRoot() . '/sales-vouchers/' . $voucherId . '/invoice/pdf' . self::query($ctx->asQuery());
    }

    public function access(Context $ctx): array
    {
        return $this->call('GET', 'access/me' . self::query($ctx->asQuery()));
    }
}
