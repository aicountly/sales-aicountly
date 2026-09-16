<?php

declare(strict_types=1);

/**
 * Integration tests for the Sales domain.
 *
 * They run against a REAL PostgreSQL database and a stub that stands in for
 * Books and Inventory, so what is being tested is the actual SQL, the actual
 * HTTP client and the actual idempotency behaviour — not mocks of them.
 *
 *   php server-php/tests/integration.php
 *
 * Environment: DB_* pointing at a throwaway database, BOOKS_API_BASE and
 * INVENTORY_API_BASE pointing at tests/stub.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Domain\CreditControlService;
use Aicountly\Api\Domain\ForecastService;
use Aicountly\Api\Domain\FulfilmentService;
use Aicountly\Api\Domain\InsightService;
use Aicountly\Api\Domain\InvoiceRequestService;
use Aicountly\Api\Domain\MetricsService;
use Aicountly\Api\Domain\OrderService;
use Aicountly\Api\Domain\QuotationService;
use Aicountly\Api\Domain\ReturnService;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertThrows(callable $fn, string $expectFragment, string $what): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($expectFragment !== '' && !str_contains($e->getMessage(), $expectFragment)) {
            throw new \RuntimeException($what . ': wrong error — ' . $e->getMessage());
        }

        return;
    }
    throw new \RuntimeException($what . ': expected a failure, none was thrown');
}

/** Http::json() exits; tests need it to throw instead so they can assert on it. */
final class HttpExit extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly array $payload)
    {
        parent::__construct((string) ($payload['message'] ?? 'HTTP ' . $status), $status);
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/**
 * Context has readonly properties, so each one is set exactly once here — a
 * second setValue on the same instance is a fatal error, not a test failure.
 */
function freshContext(int $cmpId = 77, int $fyId = 5, int $boId = 0): Context
{
    $r = new \ReflectionClass(Context::class);
    $ctx = $r->newInstanceWithoutConstructor();
    foreach (['cmpId' => $cmpId, 'fyId' => $fyId, 'boId' => $boId] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($ctx, $value);
    }

    return $ctx;
}

function ownerAuth(): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid'      => 'user-owner',
        'kind'      => 'user',
        'sourceApp' => 'sales',
        'sesKey'    => 'stub-ses-key',
        'session'   => ['name' => 'Owner'],
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    return $auth;
}

/** Somebody who has been given access to the company but does not own it. */
function memberAuth(string $uuid = 'user-member'): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid'      => $uuid,
        'kind'      => 'user',
        'sourceApp' => 'sales',
        'sesKey'    => 'stub-ses-key-member',
        'session'   => ['name' => 'Member'],
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    return $auth;
}

function resetDatabase(): void
{
    $tables = [
        'sales_return_lines', 'sales_return_requests', 'sales_invoice_requests',
        'sales_fulfilment_requests', 'sales_delivery_schedules', 'sales_order_lines',
        'sales_orders', 'sales_quotation_lines', 'sales_quotations',
        'sales_approval_requests', 'sales_integration_commands',
        'sales_price_book_rules', 'sales_price_books', 'sales_people',
        'sales_channels', 'sales_territories', 'sales_settings',
        'sales_commission_calculations', 'sales_commission_rules', 'sales_targets',
        'sales_permission_assignments', 'sales_permission_profiles', 'sales_followups',
        'sales_portal_acceptances', 'sales_user_preferences',
    ];
    // TRUNCATE, not DELETE: the audit table's row-level triggers refuse DELETE,
    // and deliberately do not fire on TRUNCATE so a suite can reset itself.
    Db::connect()->exec('TRUNCATE ' . implode(', ', $tables) . ', sales_audit_log RESTART IDENTITY CASCADE');
    // Grants are memoised per request; a suite is many requests in one process.
    Permissions::forget();
    @unlink(sys_get_temp_dir() . '/stub-idempotency.json');
    @unlink(sys_get_temp_dir() . '/stub-requests.jsonl');
    @unlink(sys_get_temp_dir() . '/stub-documents.json');
    stubRecover();
}

/** Make the stub fail for every path containing $pathFragment, until cleared. */
function stubFail(string $pathFragment, int $status): void
{
    file_put_contents(
        sys_get_temp_dir() . '/stub-control.json',
        json_encode(['path' => $pathFragment, 'status' => $status]),
    );
}

function stubRecover(): void
{
    @unlink(sys_get_temp_dir() . '/stub-control.json');
}

/** @return list<array<string, mixed>> every request the stub received */
function stubRequests(): array
{
    $log = sys_get_temp_dir() . '/stub-requests.jsonl';
    if (!is_file($log)) {
        return [];
    }
    $out = [];
    foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
        if ($line !== '') {
            $out[] = json_decode($line, true);
        }
    }

    return $out;
}

function quotationInput(array $overrides = []): array
{
    return $overrides + [
        'customer_account_id' => 501,
        'customer_name'       => 'Northern Distributors',
        'quotation_date'      => '2026-09-10',
        'lines' => [
            ['item_id' => 101, 'unit_id' => 1, 'quantity' => 100, 'rate' => 120, 'discount_pc' => 5, 'estimated_tax_pc' => 18],
            ['item_id' => 102, 'unit_id' => 1, 'quantity' => 50,  'rate' => 200, 'estimated_tax_pc' => 18],
        ],
    ];
}

function orderInput(array $overrides = []): array
{
    return $overrides + [
        'customer_account_id' => 501,
        'customer_name'       => 'Northern Distributors',
        'order_date'          => '2026-09-12',
        'warehouse_id'        => 3,
        'lines' => [
            ['item_id' => 101, 'unit_id' => 1, 'ordered_qty' => 100, 'rate' => 120, 'estimated_tax_pc' => 18, 'warehouse_id' => 3],
            ['item_id' => 102, 'unit_id' => 1, 'ordered_qty' => 50,  'rate' => 200, 'estimated_tax_pc' => 18, 'warehouse_id' => 3],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$ctx = freshContext();
$auth = ownerAuth();

// In production this comes off the Manage company row that Context::assertAllowed()
// already fetched. Seeding it is the ONE thing these tests stand in for, and it is
// seeded through the same door production writes to — not by handing Auth a fake
// session field, which is how a suite ends up asserting its own mock.
CompanyAccess::seed($ctx, $auth, CompanyAccess::OWNER);

echo "\nQuotations\n";

check('creates a quotation with a generated number and correct totals', function () use ($ctx, $auth) {
    resetDatabase();
    $q = (new QuotationService($ctx, $auth))->create(quotationInput());

    assertTrue(str_starts_with((string) $q['quotation_no'], 'QT/5/'), 'number carries the prefix and financial year');
    assertSame(2, count($q['lines']), 'line count');
    // 100 x 120 = 12000, less 5% = 11400 ; 50 x 200 = 10000. Subtotal 22000, discount 600.
    assertSame('22000.0000', (string) $q['subtotal_amount'], 'subtotal');
    assertSame('600.0000', (string) $q['discount_amount'], 'discount');
    // Tax 18% of 21400 = 3852. Total 25252.
    assertSame('3852.0000', (string) $q['estimated_tax_amount'], 'estimated tax');
    assertSame('25252.0000', (string) $q['total_amount'], 'total');
});

check('numbers run consecutively rather than colliding', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new QuotationService($ctx, $auth);
    $first = $service->create(quotationInput());
    $second = $service->create(quotationInput());

    assertSame('QT/5/0001', $first['quotation_no'], 'first number');
    assertSame('QT/5/0002', $second['quotation_no'], 'second number');
});

check('refuses a quotation with no lines', function () use ($ctx, $auth) {
    resetDatabase();
    assertThrows(
        static fn () => (new QuotationService($ctx, $auth))->create(quotationInput(['lines' => []])),
        'at least one line',
        'empty quotation',
    );
});

check('refuses a stock line with no item', function () use ($ctx, $auth) {
    resetDatabase();
    assertThrows(
        static fn () => (new QuotationService($ctx, $auth))->create(quotationInput([
            'lines' => [['quantity' => 1, 'rate' => 10]],
        ])),
        'needs an item',
        'itemless stock line',
    );
});

check('a revision is a new row and the original survives', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new QuotationService($ctx, $auth);
    $original = $service->create(quotationInput());

    $revised = $service->revise((int) $original['quotation_id'], [
        'lines' => [['item_id' => 101, 'unit_id' => 1, 'quantity' => 120, 'rate' => 115, 'estimated_tax_pc' => 18]],
    ]);

    assertSame(1, (int) $revised['revision_no'], 'revision number');
    assertSame($original['quotation_no'], $revised['quotation_no'], 'the number is kept across revisions');
    assertSame((int) $original['quotation_id'], (int) $revised['supersedes_id'], 'points back at what it replaces');

    $stillThere = $service->find((int) $original['quotation_id']);
    assertTrue($stillThere !== [], 'the original row still exists');
    assertSame(2, count($stillThere['lines']), 'the original keeps its own lines');
});

check('the list shows only the latest revision by default', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new QuotationService($ctx, $auth);
    $original = $service->create(quotationInput());
    $service->revise((int) $original['quotation_id'], ['lines' => [['item_id' => 101, 'quantity' => 1, 'rate' => 1]]]);

    $latest = $service->search([], 50, 0, 'quotation_date', 'DESC');
    assertSame(1, $latest['total'], 'one row when superseded are hidden');

    $all = $service->search(['include_superseded' => true], 50, 0, 'quotation_date', 'DESC');
    assertSame(2, $all['total'], 'both rows when history is asked for');
});

check('a discount past the limit routes the quotation to approval', function () use ($ctx, $auth) {
    resetDatabase();
    // Default settings require approval above 10%.
    $q = (new QuotationService($ctx, $auth))->create(quotationInput([
        'lines' => [['item_id' => 101, 'unit_id' => 1, 'quantity' => 10, 'rate' => 100, 'discount_pc' => 40]],
    ]));

    assertSame('APPROVAL_PENDING', $q['status'], 'status');
    assertTrue(count($q['approvals']) > 0, 'an approval request was raised');
    assertSame('discount', $q['approvals'][0]['reason_kind'], 'reason');
});

check('approving clears the pending approval and stamps the approver', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new QuotationService($ctx, $auth);
    $q = $service->create(quotationInput([
        'lines' => [['item_id' => 101, 'quantity' => 10, 'rate' => 100, 'discount_pc' => 40]],
    ]));

    $approved = $service->transition((int) $q['quotation_id'], 'approve', ['note' => 'Agreed with the customer']);

    assertSame('APPROVED', $approved['status'], 'status');
    assertSame('user-owner', $approved['approved_by'], 'approver recorded');
    assertSame('APPROVED', $approved['approvals'][0]['status'], 'the approval request was decided');
});

check('refuses a transition that makes no sense', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new QuotationService($ctx, $auth);
    $q = $service->create(quotationInput());
    $service->transition((int) $q['quotation_id'], 'cancel', []);

    assertThrows(
        static fn () => $service->transition((int) $q['quotation_id'], 'send', []),
        'cannot be',
        'sending a cancelled quotation',
    );
});

echo "\nCredit control\n";

check('reads the customer position live from Books and allows within limit', function () use ($ctx, $auth) {
    resetDatabase();
    $verdict = (new CreditControlService($ctx, $auth))->evaluate(501, 10000.0);

    assertSame(CreditControlService::WARN, $verdict['decision'], 'the stub bill is overdue, so warn');
    assertSame(120000.0, $verdict['outstanding'], 'outstanding came from Books');
    assertSame(500000.0, $verdict['credit_limit'], 'limit came from Books');
    assertTrue($verdict['overdue'] > 0, 'overdue detected');
});

check('stores no customer balance of its own', function () use ($ctx) {
    $tables = Db::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (column_name LIKE '%outstanding%' OR column_name LIKE '%receivable%'
                OR column_name LIKE '%credit_limit%' OR column_name LIKE '%balance%')",
    );
    assertSame(0, count($tables), 'no balance column exists anywhere in the Sales schema: ' . json_encode($tables));
});

echo "\nOrders and cross-service commands\n";

check('creates an order and confirms it, reserving through Inventory', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());

    assertSame('DRAFT', $order['status'], 'new orders start as drafts');

    $confirmed = $orders->confirm((int) $order['order_id'], ['override_credit' => true]);

    assertSame('RESERVED', $confirmed['status'], 'status after a successful reservation');
    assertSame('resv-1', $confirmed['lines'][0]['inventory_reservation_uuid'], 'the reservation reference was stored');
    assertSame('WARN', $confirmed['credit_decision'], 'the credit verdict was recorded');
    assertSame('user-owner', $confirmed['credit_override_by'], 'the override was attributed');

    $reserve = array_values(array_filter($confirmed['commands'], static fn ($c) => $c['command_type'] === OrderService::COMMAND_RESERVE));
    assertSame('COMPLETED', $reserve[0]['status'], 'the reservation command completed');
});

check('an unreachable Inventory leaves the order confirmed and the command retryable', function () use ($ctx, $auth) {
    resetDatabase();
    stubFail('reservations', 500);

    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $result = $orders->confirm((int) $order['order_id'], ['override_credit' => true]);

    stubRecover();

    // The commercial commitment survives somebody else's outage.
    assertSame('CONFIRMED', $result['status'], 'the order is still confirmed');
    assertTrue(isset($result['reservation_error']), 'the failure is reported, not swallowed');

    $reserve = array_values(array_filter($result['commands'], static fn ($c) => $c['command_type'] === OrderService::COMMAND_RESERVE));
    assertSame('FAILED', $reserve[0]['status'], 'FAILED, so it can be retried');
});

check('a business refusal from Inventory is BLOCKED, not FAILED', function () use ($ctx, $auth) {
    resetDatabase();
    stubFail('reservations', 422);

    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $result = $orders->confirm((int) $order['order_id'], ['override_credit' => true]);

    stubRecover();

    $reserve = array_values(array_filter($result['commands'], static fn ($c) => $c['command_type'] === OrderService::COMMAND_RESERVE));
    assertSame('BLOCKED', $reserve[0]['status'], 'a 422 means retrying will not help');
});

check('a retry reuses the original idempotency key', function () use ($ctx, $auth) {
    resetDatabase();
    stubFail('reservations', 500);

    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);

    $firstKey = Db::scalar(
        'SELECT idempotency_key FROM sales_integration_commands WHERE entity_id = :id AND command_type = :t',
        ['id' => (int) $order['order_id'], 't' => OrderService::COMMAND_RESERVE],
    );

    stubRecover();

    $orders->requestReservation((int) $order['order_id']);

    $secondKey = Db::scalar(
        'SELECT idempotency_key FROM sales_integration_commands WHERE entity_id = :id AND command_type = :t',
        ['id' => (int) $order['order_id'], 't' => OrderService::COMMAND_RESERVE],
    );

    assertSame($firstKey, $secondKey, 'the key survives the retry — this is what stops a double reservation');

    $keys = array_values(array_filter(array_map(
        static fn ($r) => $r['headers']['idempotency-key'] ?? null,
        array_filter(stubRequests(), static fn ($r) => str_contains($r['path'], 'reservations')),
    )));
    assertSame(1, count(array_unique($keys)), 'both attempts presented the same key');
});

echo "\nDispatch and invoicing\n";

check('dispatch asks Inventory and records progress from its answer', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);

    $after = (new FulfilmentService($ctx, $auth))->requestIssue((int) $order['order_id'], []);

    assertSame('FULFILLED', $after['status'], 'everything outstanding went out');
    assertSame('100.0000', (string) $after['lines'][0]['delivered_qty'], 'delivered quantity from Inventory');
    assertSame('ACCEPTED', $after['fulfilments'][0]['status'], 'the request was accepted');
    assertSame('invdoc-2', $after['fulfilments'][0]['inventory_document_uuid'], 'the document reference was kept');
});

check('a partial dispatch leaves the order partially fulfilled', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);
    $lineId = (int) $order['lines'][0]['line_id'];

    $after = (new FulfilmentService($ctx, $auth))->requestIssue((int) $order['order_id'], [
        'lines' => [['line_id' => $lineId, 'qty' => 40]],
    ]);

    assertSame('PARTIALLY_FULFILLED', $after['status'], 'status');
    assertSame('40.0000', (string) $after['lines'][0]['delivered_qty'], 'only what went out');
});

check('refuses to dispatch more than is outstanding', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);
    $lineId = (int) $order['lines'][0]['line_id'];

    assertThrows(
        static fn () => (new FulfilmentService($ctx, $auth))->requestIssue((int) $order['order_id'], [
            'lines' => [['line_id' => $lineId, 'qty' => 5000]],
        ]),
        'left to dispatch',
        'over-dispatch',
    );
});

check('invoicing on the delivered basis bills only what went out', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);
    $lineId = (int) $order['lines'][0]['line_id'];

    (new FulfilmentService($ctx, $auth))->requestIssue((int) $order['order_id'], [
        'lines' => [['line_id' => $lineId, 'qty' => 40]],
    ]);

    $after = (new InvoiceRequestService($ctx, $auth))->request((int) $order['order_id'], ['basis' => 'delivered']);

    assertSame('40.0000', (string) $after['lines'][0]['invoiced_qty'], 'only the delivered quantity');
    assertSame('0.0000', (string) $after['lines'][1]['invoiced_qty'], 'the undelivered line was not billed');
    assertSame('POSTED', $after['invoice_requests'][0]['status'], 'the request posted');
    assertTrue($after['invoice_requests'][0]['books_voucher_id'] !== null, 'the Books voucher id was kept');
});

check('refuses to invoice when nothing has been delivered', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);

    assertThrows(
        static fn () => (new InvoiceRequestService($ctx, $auth))->request((int) $order['order_id'], ['basis' => 'delivered']),
        'nothing to invoice',
        'invoicing an undelivered order',
    );
});

check('a lost Books response does not create a second invoice', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);
    (new FulfilmentService($ctx, $auth))->requestIssue((int) $order['order_id'], []);

    $invoices = new InvoiceRequestService($ctx, $auth);
    $first = $invoices->request((int) $order['order_id'], ['basis' => 'delivered']);
    $voucherId = $first['invoice_requests'][0]['books_voucher_id'];

    // The user presses Save again because the first answer never arrived. The
    // request is rebuilt, reaches the SAME key, and Books replays its original
    // answer instead of writing a second invoice.
    $requestId = (int) $first['invoice_requests'][0]['request_id'];
    assertThrows(
        static fn () => $invoices->retry($requestId),
        'already been raised',
        'retrying a posted request',
    );

    $posted = Db::scalar(
        "SELECT COUNT(*) FROM sales_invoice_requests WHERE order_id = :id AND status = 'POSTED'",
        ['id' => (int) $order['order_id']],
    );
    assertSame(1, (int) $posted, 'exactly one invoice exists');
    assertTrue($voucherId !== null, 'and it has a Books id');
});

check('cancelling an invoiced order is refused', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $orders->confirm((int) $order['order_id'], ['override_credit' => true]);
    (new FulfilmentService($ctx, $auth))->requestIssue((int) $order['order_id'], []);
    (new InvoiceRequestService($ctx, $auth))->request((int) $order['order_id'], ['basis' => 'delivered']);

    assertThrows(
        static fn () => $orders->cancel((int) $order['order_id'], ['reason' => 'Customer changed their mind']),
        'credit note',
        'cancelling after invoicing',
    );
});

check('cancelling requires a reason', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());

    assertThrows(
        static fn () => $orders->cancel((int) $order['order_id'], []),
        'why this order',
        'reasonless cancellation',
    );
});

echo "\nReturns\n";

check('a return runs approve, receive and credit, keeping only references', function () use ($ctx, $auth) {
    resetDatabase();
    $returns = new ReturnService($ctx, $auth);
    $rma = $returns->create([
        'customer_account_id' => 501,
        'return_date'         => '2026-09-15',
        'books_invoice_id'    => 4001,
        'books_invoice_uuid'  => 'vch-1',
        'lines' => [
            ['item_id' => 101, 'unit_id' => 1, 'warehouse_id' => 3, 'return_qty' => 10, 'rate' => 120, 'condition_code' => 'good'],
        ],
    ]);

    assertTrue(str_starts_with((string) $rma['rma_no'], 'RMA/5/'), 'RMA number');

    $returns->approve((int) $rma['return_id'], ['note' => 'Agreed']);
    $received = $returns->receive((int) $rma['return_id']);
    assertSame('RECEIVED', $received['status'], 'status after receipt');
    assertTrue($received['inventory_document_uuid'] !== null, 'Inventory document reference kept');

    $credited = $returns->requestCreditNote((int) $rma['return_id']);
    assertSame('CREDITED', $credited['status'], 'status after credit');
    assertTrue($credited['books_credit_note_uuid'] !== null, 'Books credit note reference kept');
});

check('receiving before approval is refused', function () use ($ctx, $auth) {
    resetDatabase();
    $returns = new ReturnService($ctx, $auth);
    $rma = $returns->create([
        'customer_account_id' => 501,
        'lines' => [['item_id' => 101, 'return_qty' => 1, 'rate' => 10]],
    ]);

    assertThrows(
        static fn () => $returns->receive((int) $rma['return_id']),
        'Approve the return',
        'premature receipt',
    );
});

echo "\nData ownership (release-blocking)\n";

check('no table mirrors an item, customer, warehouse or invoice', function () {
    $forbidden = Db::all(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = 'public'
           AND (table_name LIKE '%item%master%' OR table_name LIKE 'sales_items'
                OR table_name LIKE '%customer_master%' OR table_name LIKE 'sales_customers'
                OR table_name LIKE '%stock%' OR table_name LIKE '%ledger%'
                OR table_name LIKE '%mirror%' OR table_name LIKE '%_cache'
                OR table_name LIKE 'sales_invoices' OR table_name LIKE '%warehouse%'
                OR table_name LIKE '%receivable%' OR table_name LIKE '%voucher%')",
    );
    assertSame(0, count($forbidden), 'forbidden mirror tables exist: ' . json_encode($forbidden));
});

check('no column caches a remote name, stock figure or valuation', function () {
    $suspicious = Db::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (column_name IN ('item_name', 'item_sku', 'warehouse_name', 'stock_qty',
                                'available_qty', 'on_hand', 'valuation_rate', 'cost_rate',
                                'last_synced_at', 'synced_at')
                OR column_name LIKE 'sync%')",
    );
    assertSame(0, count($suspicious), 'cached remote fields exist: ' . json_encode($suspicious));
});

check('every remote reference is stored as an id or uuid only', function () {
    // The reference columns we DO keep, each pointing at a row another product
    // owns. If this list grows, it should grow with ids — never with bodies.
    $references = Db::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (column_name LIKE 'books_%' OR column_name LIKE 'inventory_%'
                OR column_name IN ('item_id', 'unit_id', 'warehouse_id', 'batch_id',
                                   'customer_account_id', 'tax_cat_id', 'item_grp_id', 'bom_id'))",
    );
    foreach ($references as $ref) {
        $name = $ref['column_name'];
        $isIdentifier = str_ends_with($name, '_id') || str_ends_with($name, '_uuid') || str_ends_with($name, '_no');
        assertTrue($isIdentifier, "{$ref['table_name']}.{$name} is a remote reference that is not an id/uuid/no");
    }
    assertTrue(count($references) > 0, 'the schema does hold remote references');
});

check('the audit log refuses UPDATE and DELETE', function () {
    Db::run(
        'INSERT INTO sales_audit_log (cmp_id, fy_id, actor_uuid, actor_kind, source_app, action, entity_type)
         VALUES (77, 5, :u, :k, :a, :act, :e)',
        ['u' => 'tester', 'k' => 'user', 'a' => 'sales', 'act' => 'test', 'e' => 'quotation'],
    );
    assertThrows(static fn () => Db::run("UPDATE sales_audit_log SET action = 'tampered'"), 'append-only', 'audit UPDATE');
    assertThrows(static fn () => Db::run('DELETE FROM sales_audit_log'), 'append-only', 'audit DELETE');
});

check('actions are actually written to the audit log', function () use ($ctx, $auth) {
    resetDatabase();
    $q = (new QuotationService($ctx, $auth))->create(quotationInput());
    $rows = Db::all(
        "SELECT action, entity_type FROM sales_audit_log WHERE entity_id = :id AND entity_type = 'quotation'",
        ['id' => (string) $q['quotation_id']],
    );
    assertSame('quotation.created', $rows[0]['action'] ?? null, 'the creation was audited');
});

echo "\nTenant isolation\n";

check('a query for another company returns nothing', function () use ($ctx, $auth) {
    resetDatabase();
    (new QuotationService($ctx, $auth))->create(quotationInput());

    $other = freshContext(999);
    $result = (new QuotationService($other, $auth))->search([], 50, 0, 'quotation_date', 'DESC');
    assertSame(0, $result['total'], 'company 999 sees none of company 77 rows');
});


// ---------------------------------------------------------------------------
// Dashboard metrics — the numbers five screens are built on
// ---------------------------------------------------------------------------

echo "\nDashboard metrics\n";

check('a superseded revision does not inflate the open pipeline', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $original = $quotations->create(quotationInput());
    $quotations->revise((int) $original['quotation_id'], []);

    $metrics = new MetricsService($ctx);
    $open = $metrics->openPipeline('2026-09-16');

    // Two rows exist, one offer does. Counting both was the old behaviour and
    // it doubled the pipeline every time somebody adjusted a price.
    assertSame(2, (int) Db::scalar('SELECT COUNT(*) FROM sales_quotations'), 'both revisions are stored');
    assertSame(1, $open['count'], 'only the latest revision is open');
});

check('a quotation past its validity leaves the open pipeline without a cron', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $quotation = $quotations->create(quotationInput(['valid_until' => '2026-09-14']));
    $quotations->transition((int) $quotation['quotation_id'], 'send', ['channel' => 'email', 'reference' => 'buyer@example.com']);

    $metrics = new MetricsService($ctx);
    assertSame(1, $metrics->openPipeline('2026-09-13')['count'], 'inside validity it is open');
    assertSame(0, $metrics->openPipeline('2026-09-15')['count'], 'past validity it is not');
    assertSame(1, $metrics->openPipeline('2026-09-15')['expired_count'], 'and it is reported as expired');
});

check('conversion is unavailable rather than 0% when nothing has been decided', function () use ($ctx, $auth) {
    resetDatabase();
    (new QuotationService($ctx, $auth))->create(quotationInput());

    $conversion = (new MetricsService($ctx))->quoteConversion('2026-09-01', '2026-09-30', '2026-09-16');
    assertSame(false, $conversion['available'], 'no decisions means no rate');
    assertSame(null, $conversion['rate_pc'], '0% would read as "we convert nothing"');
});

check('conversion counts accepted over decided, not over every quotation', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);

    $accepted = $quotations->create(quotationInput());
    $quotations->transition((int) $accepted['quotation_id'], 'send', ['channel' => 'email', 'reference' => 'a@example.com']);
    $quotations->transition((int) $accepted['quotation_id'], 'accept');

    $declined = $quotations->create(quotationInput());
    $quotations->transition((int) $declined['quotation_id'], 'send', ['channel' => 'email', 'reference' => 'b@example.com']);
    $quotations->transition((int) $declined['quotation_id'], 'decline');

    // A draft nobody has answered has not failed to convert.
    $quotations->create(quotationInput());

    $conversion = (new MetricsService($ctx))->quoteConversion('2026-09-01', '2026-09-30', '2026-09-16');
    assertSame(2, $conversion['decided'], 'the draft is not in the denominator');
    assertSame(50.0, $conversion['rate_pc'], 'one of two decided');
});

check('a target that was never set reads as not configured, never as zero', function () use ($ctx) {
    resetDatabase();
    $target = (new MetricsService($ctx))->companyTarget('2026-09-01', '2026-09-30');

    assertSame(false, $target['configured'], 'no target is configured');
    assertSame(null, $target['value'], 'and none is invented — a zero target makes every month a triumph');
});

check('order stage counts are milestones, not a funnel', function () use ($ctx, $auth) {
    resetDatabase();
    $order = (new OrderService($ctx, $auth))->create(orderInput());
    (new OrderService($ctx, $auth))->confirm((int) $order['order_id']);

    $stages = (new MetricsService($ctx))->orderStages();
    $byKey = [];
    foreach ($stages['stages'] as $stage) {
        $byKey[$stage['key']] = $stage['count'];
    }

    // Reserved must not subtract from confirmed: the same order is at both.
    assertSame(1, $byKey['confirmed'], 'the order is confirmed');
    assertSame(1, $byKey['reserved'], 'and reserved, and still counted as confirmed');
    assertTrue(str_contains($stages['basis'], 'not the stages of a funnel'), 'the basis says so');
});

check('on-time delivery is unavailable with nothing measurable', function () use ($ctx, $auth) {
    resetDatabase();
    (new OrderService($ctx, $auth))->create(orderInput());

    $onTime = (new MetricsService($ctx))->onTimeDelivery('2026-09-01', '2026-09-30');
    assertSame(false, $onTime['available'], 'nothing has completed');
    assertSame(null, $onTime['rate_pc'], 'so there is no rate');
});

check('the drilldown returns exactly what the KPI counted', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $quotations->create(quotationInput());
    $lapsed = $quotations->create(quotationInput(['valid_until' => '2026-09-01']));
    $quotations->transition((int) $lapsed['quotation_id'], 'send', ['channel' => 'email', 'reference' => 'c@example.com']);

    $card = (new MetricsService($ctx))->openPipeline('2026-09-16')['count'];
    $list = $quotations->search(['open' => true, 'as_of' => '2026-09-16'], 50, 0, 'quotation_date', 'DESC');

    assertSame($card, $list['total'], 'the card and the list it opens agree');
    assertSame(1, $card, 'and the lapsed one is in neither');
});

// ---------------------------------------------------------------------------
// Workflow rectifications
// ---------------------------------------------------------------------------

echo "\nWorkflow rectifications\n";

check('converting the same quotation twice returns one order, not two', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $quotation = $quotations->create(quotationInput());
    $quotationId = (int) $quotation['quotation_id'];
    $quotations->transition($quotationId, 'send', ['channel' => 'email', 'reference' => 'd@example.com']);
    $quotations->transition($quotationId, 'accept');

    $orders = new OrderService($ctx, $auth);
    $payload = $quotations->conversionPayload($quotationId);

    $first = $orders->create($payload);
    $second = $orders->create($payload);

    assertSame((int) $first['order_id'], (int) $second['order_id'], 'the second attempt gets the first order');
    assertSame(1, (int) Db::scalar('SELECT COUNT(*) FROM sales_orders'), 'and only one order exists');
});

check('a superseded quotation cannot become an order', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $original = $quotations->create(quotationInput());
    $originalId = (int) $original['quotation_id'];
    $payload = $quotations->conversionPayload($originalId);
    $quotations->revise($originalId, []);

    assertThrows(
        static fn () => (new OrderService($ctx, $auth))->create($payload),
        'revised',
        'converting a superseded revision',
    );
});

check('an optional line is not committed by a conversion', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $quotation = $quotations->create(quotationInput(['lines' => [
        ['item_id' => 101, 'unit_id' => 1, 'quantity' => 10, 'rate' => 100],
        ['item_id' => 102, 'unit_id' => 1, 'quantity' => 5, 'rate' => 200, 'is_optional' => true],
    ]]));

    $payload = $quotations->conversionPayload((int) $quotation['quotation_id']);
    assertSame(1, count($payload['lines']), 'the optional line is left off the order');
});

check('sending needs a channel and a reference, not just a click', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $quotation = $quotations->create(quotationInput());
    $id = (int) $quotation['quotation_id'];

    assertThrows(static fn () => $quotations->transition($id, 'send'), 'how the quotation was sent', 'send with no channel');
    assertThrows(
        static fn () => $quotations->transition($id, 'send', ['channel' => 'email']),
        'Record where it went',
        'send with no reference',
    );

    $sent = $quotations->transition($id, 'send', ['channel' => 'email', 'reference' => 'buyer@example.com']);
    assertSame('SENT', $sent['status'], 'a real send moves the status');
    assertSame('email', $sent['sent_channel'], 'and records how');
    assertSame('buyer@example.com', $sent['sent_reference'], 'and records where');
});

check('order status is decomposed into the questions it was conflating', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput());
    $confirmed = $orders->confirm((int) $order['order_id']);
    $facets = $confirmed['facets'];

    assertSame('confirmed', $facets['approval']['state'], 'approval is its own answer');
    assertSame('reserved', $facets['stock']['state'], 'stock is its own answer');
    assertSame('none', $facets['fulfilment']['state'], 'nothing has shipped');
    assertSame('none', $facets['invoice']['state'], 'and nothing is billed');
    // Whether the customer paid is Books' answer and this product must not guess.
    assertSame('ask_books', $facets['payment']['state'], 'payment is deferred to the accounts');
});

check('a superseded revision cannot be sent or accepted', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $original = $quotations->create(quotationInput());
    $originalId = (int) $original['quotation_id'];
    $quotations->revise($originalId, []);

    // Revising already moves the predecessor out of a sendable status. Put it
    // back to SENT by hand so the SUPERSEDED guard itself is what is tested,
    // rather than the status check that happens to run first.
    Db::run("UPDATE sales_quotations SET status = 'SENT' WHERE quotation_id = :id", ['id' => $originalId]);

    assertThrows(
        static fn () => $quotations->transition($originalId, 'send', ['channel' => 'email', 'reference' => 'x@example.com']),
        'superseded',
        'sending a superseded revision',
    );
    assertThrows(
        static fn () => $quotations->transition($originalId, 'accept'),
        'superseded',
        'accepting a superseded revision',
    );

    // Cancelling and expiring one are still allowed: tidying up history is not
    // the same as acting on an offer nobody is discussing.
    $cancelled = $quotations->transition($originalId, 'cancel');
    assertSame('CANCELLED', $cancelled['status'], 'a superseded revision can still be closed off');
});

// ---------------------------------------------------------------------------
// Forecast
// ---------------------------------------------------------------------------

echo "\nForecast\n";

check('the forecast does not count an invoiced order twice', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput(['committed_date' => '2026-09-20']));
    $orderId = (int) $order['order_id'];
    $orders->confirm($orderId);
    (new FulfilmentService($ctx, $auth))->requestIssue($orderId, []);
    (new InvoiceRequestService($ctx, $auth))->request($orderId, ['basis' => 'delivered']);

    $metrics = new MetricsService($ctx);
    $actual = [
        'status' => 'ready', 'value' => 100000.0, 'reason' => null,
        'basis' => 'test actual', 'source' => 'books',
    ];
    $projection = (new ForecastService($ctx, $metrics))
        ->project('2026-09-01', '2026-09-30', '2026-09-16', $actual);

    $backlog = null;
    foreach ($projection['components'] as $component) {
        if ($component['key'] === 'backlog') {
            $backlog = (float) $component['value'];
        }
    }

    // Fully delivered and fully invoiced: its value is already inside the
    // actual, so adding it again would report the same sale twice.
    assertSame(0.0, $backlog, 'a fully invoiced order contributes nothing more');
});

check('an uninvoiced order contributes only its uninvoiced part', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    $order = $orders->create(orderInput(['committed_date' => '2026-09-20']));
    $orders->confirm((int) $order['order_id']);

    $actual = ['status' => 'ready', 'value' => 0.0, 'reason' => null, 'basis' => 't', 'source' => 'books'];
    $projection = (new ForecastService($ctx, new MetricsService($ctx)))
        ->project('2026-09-01', '2026-09-30', '2026-09-16', $actual);

    $backlog = 0.0;
    foreach ($projection['components'] as $component) {
        if ($component['key'] === 'backlog') {
            $backlog = (float) $component['value'];
        }
    }

    // 100 x 120 + 50 x 200, nothing billed.
    assertSame(22000.0, $backlog, 'the whole order is still to bill');
});

check('the chart ends exactly where the projected KPI says', function () use ($ctx, $auth) {
    resetDatabase();
    (new OrderService($ctx, $auth))->create(orderInput(['committed_date' => '2026-09-20']));

    $metrics = new MetricsService($ctx);
    $forecast = new ForecastService($ctx, $metrics);
    $actual = ['status' => 'ready', 'value' => 50000.0, 'reason' => null, 'basis' => 't', 'source' => 'books'];
    $projection = $forecast->project('2026-09-01', '2026-09-30', '2026-09-16', $actual);
    $series = $forecast->series($metrics->dailyOrderTrend('2026-09-01', '2026-09-30', '2026-09-16'), $projection, '2026-09-30');

    $last = end($series);
    assertSame('2026-09-30', $last['date'], 'the line runs to the end of the period');
    assertSame($projection['projected']['mid'], $last['projected'], 'and lands on the number the card shows');
});

check('a projection with no measured conversion still stands on what is committed', function () use ($ctx, $auth) {
    resetDatabase();
    // A quotation nobody has decided: there is no rate to weight it with, and
    // inventing one would be inventing a forecast.
    (new QuotationService($ctx, $auth))->create(quotationInput());

    $actual = ['status' => 'ready', 'value' => 1000.0, 'reason' => null, 'basis' => 't', 'source' => 'books'];
    $projection = (new ForecastService($ctx, new MetricsService($ctx)))
        ->project('2026-09-01', '2026-09-30', '2026-09-16', $actual);

    assertSame(true, $projection['available'], 'the projection still works');
    assertSame(1000.0, $projection['projected']['mid'], 'and adds no pipeline it cannot weight');
    assertTrue(
        str_contains((string) $projection['reason'], 'not weighted'),
        'and says why the pipeline was left out',
    );
});

check('the forecast is unavailable when the actual cannot be read', function () use ($ctx) {
    resetDatabase();
    $actual = [
        'status' => 'unavailable', 'value' => null,
        'reason' => 'Smart Books could not be reached.', 'basis' => 't', 'source' => 'books',
    ];
    $projection = (new ForecastService($ctx, new MetricsService($ctx)))
        ->project('2026-09-01', '2026-09-30', '2026-09-16', $actual);

    assertSame(false, $projection['available'], 'no actual, no projection');
    assertSame(null, $projection['projected'], 'and no number invented in its place');
});

// ---------------------------------------------------------------------------
// Insights
// ---------------------------------------------------------------------------

echo "\nInsights\n";

check('every suggestion cites records the user can open', function () use ($ctx, $auth) {
    resetDatabase();
    $quotations = new QuotationService($ctx, $auth);
    $quotation = $quotations->create(quotationInput(['valid_until' => '2026-09-18']));
    $quotations->transition((int) $quotation['quotation_id'], 'send', ['channel' => 'email', 'reference' => 'e@example.com']);

    $insights = (new InsightService($ctx, new MetricsService($ctx)))->overview('2026-09-16', ['currency' => 'INR']);
    assertTrue(count($insights) > 0, 'there is something to suggest');
    foreach ($insights as $insight) {
        assertTrue($insight['evidence'] !== [], 'the suggestion names its evidence');
        assertSame('rule', $insight['origin'], 'and is produced by code, not a model');
    }
});

check('nothing to do produces no suggestions rather than an invented one', function () use ($ctx) {
    resetDatabase();
    $insights = (new InsightService($ctx, new MetricsService($ctx)))->overview('2026-09-16', ['currency' => 'INR']);
    assertSame(0, count($insights), 'an empty panel is a valid answer');
});

check('a reorder suggestion needs a real rhythm, not two orders', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new OrderService($ctx, $auth);
    foreach (['2026-07-01', '2026-08-01'] as $date) {
        $order = $orders->create(orderInput(['order_date' => $date]));
        $orders->confirm((int) $order['order_id']);
    }

    $candidates = (new MetricsService($ctx))->reorderCandidates('2026-09-16');
    assertSame(0, count($candidates), 'two orders is a line, not a pattern');
});

echo "\nPermissions\n";

check('reads ownership off a Manage company row, in the same order the browser does', function () {
    // These are the shapes /manage/companies actually answers with. The browser's
    // resolveAcsType() has been audited against them; this is the server agreeing.
    assertSame(CompanyAccess::OWNER, CompanyAccess::fromRow(['acs_type' => 1]), 'acs_type 1');
    assertSame(CompanyAccess::MEMBER, CompanyAccess::fromRow(['acs_type' => 0]), 'acs_type 0');
    assertSame(CompanyAccess::OWNER, CompanyAccess::fromRow(['acs_type' => '1']), 'acs_type as a string');
    assertSame(CompanyAccess::OWNER, CompanyAccess::fromRow(['ownership' => 'Owner']), 'ownership label');
    assertSame(CompanyAccess::MEMBER, CompanyAccess::fromRow(['ownership' => 'shared']), 'shared company');
    assertSame(CompanyAccess::OWNER, CompanyAccess::fromRow(['is_creator' => true]), 'creator');

    // acs_type wins over a contradicting label, because that is the precedence
    // Manage's own mapper uses.
    assertSame(CompanyAccess::MEMBER, CompanyAccess::fromRow(['acs_type' => 0, 'ownership' => 'owner']), 'acs_type wins');
});

check('a company Manage cannot describe is unknown, not owner', function () {
    // The bug this whole class exists for was an access check that answered
    // "not owner" for everyone. Its mirror image — answering "owner" when we do
    // not know — would be worse, so it is pinned here.
    assertSame(null, CompanyAccess::fromRow([]), 'empty row');
    assertSame(null, CompanyAccess::fromRow(['ownership' => 'something new']), 'unrecognised label');
    assertSame(null, CompanyAccess::fromRow(['acs_type' => 9]), 'out-of-range acs_type');
});

check('the company owner holds every permission without a profile being configured', function () use ($ctx, $auth) {
    resetDatabase();
    // No rows in sales_permission_profiles at all: this is a company on its first day.
    assertTrue(Permissions::allows($ctx, $auth, 'quotation.view'), 'owner can view quotations');
    assertTrue(Permissions::allows($ctx, $auth, 'access.manage'), 'owner can manage access');
    assertSame(count(Permissions::all()), count(Permissions::granted($ctx, $auth)), 'owner holds the whole catalog');
});

check('a member with no profile holds nothing', function () use ($ctx) {
    resetDatabase();
    $member = memberAuth();
    CompanyAccess::seed($ctx, $member, CompanyAccess::MEMBER);

    assertSame([], Permissions::granted($ctx, $member), 'no grants');
    assertTrue(!Permissions::allows($ctx, $member, 'quotation.view'), 'and cannot view quotations');
});

check('a member holds exactly what their profile grants', function () use ($ctx) {
    resetDatabase();
    $member = memberAuth();
    CompanyAccess::seed($ctx, $member, CompanyAccess::MEMBER);

    $profileId = Db::insert('sales_permission_profiles', [
        'cmp_id'       => $ctx->cmpId,
        'profile_name' => 'Sales executive',
        'permissions'  => json_encode(['quotation.view', 'quotation.create']),
    ], 'profile_id');
    Db::insert('sales_permission_assignments', [
        'cmp_id'     => $ctx->cmpId,
        'user_uuid'  => $member->uuid,
        'profile_id' => $profileId,
    ], 'assignment_id');
    Permissions::forget();

    assertTrue(Permissions::allows($ctx, $member, 'quotation.view'), 'granted permission');
    assertTrue(Permissions::allows($ctx, $member, 'quotation.create'), 'other granted permission');
    assertTrue(!Permissions::allows($ctx, $member, 'margin.view'), 'margin is not in the profile');
    assertTrue(!Permissions::allows($ctx, $member, 'order.cancel'), 'nor is cancelling an order');
});

check('a deactivated profile stops granting immediately', function () use ($ctx) {
    resetDatabase();
    $member = memberAuth();
    CompanyAccess::seed($ctx, $member, CompanyAccess::MEMBER);

    $profileId = Db::insert('sales_permission_profiles', [
        'cmp_id'       => $ctx->cmpId,
        'profile_name' => 'Temporary',
        'permissions'  => json_encode(['quotation.view']),
        'is_active'    => false,
    ], 'profile_id');
    Db::insert('sales_permission_assignments', [
        'cmp_id'     => $ctx->cmpId,
        'user_uuid'  => $member->uuid,
        'profile_id' => $profileId,
    ], 'assignment_id');
    Permissions::forget();

    assertTrue(!Permissions::allows($ctx, $member, 'quotation.view'), 'an inactive profile grants nothing');
});

check('a profile in another company does not grant anything here', function () use ($ctx) {
    resetDatabase();
    $member = memberAuth();
    CompanyAccess::seed($ctx, $member, CompanyAccess::MEMBER);

    $profileId = Db::insert('sales_permission_profiles', [
        'cmp_id'       => $ctx->cmpId + 1,
        'profile_name' => 'Elsewhere',
        'permissions'  => json_encode(['quotation.view', 'margin.view']),
    ], 'profile_id');
    Db::insert('sales_permission_assignments', [
        'cmp_id'     => $ctx->cmpId + 1,
        'user_uuid'  => $member->uuid,
        'profile_id' => $profileId,
    ], 'assignment_id');
    Permissions::forget();

    assertSame([], Permissions::granted($ctx, $member), 'grants do not cross a company boundary');
});

check('a company not yet resolved is looked up rather than guessed', function () use ($ctx) {
    resetDatabase();
    CompanyAccess::forget();
    $owner = memberAuth('user-owner');

    // Nothing seeded: `companyinfo` did not carry the field, so CompanyAccess
    // asks Manage's company list — the payload the switcher reads. The stub
    // answers acs_type 1, and that is where ownership is supposed to come from.
    assertSame(CompanyAccess::OWNER, CompanyAccess::accessType($ctx, $owner), 'resolved from the company list');
    assertTrue(Permissions::allows($ctx, $owner, 'access.manage'), 'and grants accordingly');
});

check('when Manage cannot answer, nobody is an owner', function () use ($ctx) {
    resetDatabase();
    CompanyAccess::forget();
    Permissions::forget();
    $stranger = memberAuth('user-unreachable');

    // This is the direction that matters. An access check that fails OPEN is
    // not an access check, and "Manage had a bad minute" must never read as
    // "this person owns the company".
    stubFail('companies', 500);
    try {
        assertSame(null, CompanyAccess::accessType($ctx, $stranger), 'unknown, not owner');
        assertTrue(!Permissions::allows($ctx, $stranger, 'quotation.view'), 'and grants nothing');
        assertSame([], Permissions::granted($ctx, $stranger), 'falling through to the profile table, which is empty');
    } finally {
        stubRecover();
        CompanyAccess::forget();
    }
});

echo "\nAccess administration\n";

/** Call one of AccessController's validators directly. Http throws under CLI. */
function accessCall(string $method, array $args): mixed
{
    $r = new \ReflectionMethod(AccessController::class, $method);
    $r->setAccessible(true);

    return $r->invokeArgs(null, $args);
}

check('a profile can only carry permissions this product defines', function () {
    // The allowlist is the point. An arbitrary string in that JSON array is a
    // permission somebody believes is enforced and no assert will ever ask
    // about — a grant that silently does nothing, or worse, reads as a denial.
    $accepted = accessCall('permissions', [['quotation.view', 'margin.view', 'quotation.view']]);
    sort($accepted);
    assertSame(['margin.view', 'quotation.view'], $accepted, 'known codes, deduplicated');

    assertThrows(
        static fn () => accessCall('permissions', [['quotation.view', 'quotation.destroy_everything']]),
        'does not define',
        'unknown permission code',
    );
    assertThrows(
        static fn () => accessCall('permissions', ['quotation.view']),
        'list of permission codes',
        'a string where a list belongs',
    );
});

check('a profile needs a name, and the name is bounded', function () {
    assertSame('Sales executive', accessCall('name', ['  Sales executive  ']), 'trimmed');
    assertThrows(static fn () => accessCall('name', ['   ']), 'needs a name', 'blank name');
    assertThrows(static fn () => accessCall('name', [str_repeat('x', 81)]), 'longer than 80', 'overlong name');
});

check('the screen agrees with the enforcement about an inactive profile', function () use ($ctx) {
    resetDatabase();
    // effective() is what the Access screen shows as "what they can do today".
    // If it counted an inactive profile, the screen would promise access that
    // Permissions::granted() refuses — and the administrator would believe the
    // screen.
    $liveId = (int) Db::insert('sales_permission_profiles', [
        'cmp_id'       => $ctx->cmpId,
        'profile_name' => 'Live',
        'permissions'  => json_encode(['quotation.view']),
    ], 'profile_id');
    $deadId = (int) Db::insert('sales_permission_profiles', [
        'cmp_id'       => $ctx->cmpId,
        'profile_name' => 'Retired',
        'permissions'  => json_encode(['margin.view']),
        'is_active'    => false,
    ], 'profile_id');

    $effective = accessCall('effective', [[
        ['profile_id' => $liveId, 'profile_name' => 'Live', 'is_active' => true],
        ['profile_id' => $deadId, 'profile_name' => 'Retired', 'is_active' => false],
    ]]);

    assertSame(['quotation.view'], $effective, 'only the active profile counts');
    assertSame([], accessCall('effective', [[
        ['profile_id' => $deadId, 'profile_name' => 'Retired', 'is_active' => false],
    ]]), 'nothing active grants nothing');
});

// ---------------------------------------------------------------------------

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
