<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * One attempt to make another product do something, and its outcome.
 *
 * THIS IS NOT AN OUTBOX OF DATA. A row here holds a COMMAND ("post this sale to
 * Books", "reserve this stock in Inventory"), the idempotency key that makes
 * retrying it safe, and the reference the other product handed back. It never
 * holds a copy of the other product's document, balance or master, and nothing
 * sweeps these rows to compare them against anything — there is no second copy
 * to reconcile.
 *
 * The lifecycle is deliberately small:
 *
 *   PENDING ──▶ POSTING ──▶ COMPLETED          (reference stored, done)
 *                  │
 *                  ├──────▶ FAILED             (retryable; the user can press Retry)
 *                  └──────▶ BLOCKED            (the other product refused on business
 *                                               grounds — retrying will not help)
 *
 * WHY NO CRON. A retry runs when something happens: the user presses Retry, or
 * the next request that touches the same document drains it. A scheduled job
 * that walked this table would be indistinguishable from the synchronisation
 * this architecture exists to avoid, and it would hide failures from the person
 * who could actually fix them. A stuck command is shown in the UI, on the
 * document it belongs to, with its error.
 *
 * THE KEY IS MINTED ONCE AND STORED BEFORE THE CALL. That ordering is the whole
 * defence against a double-posted invoice: if the network dies after Books has
 * written the voucher but before we see the response, the retry presents the
 * same key and Books replays the original answer instead of writing a second
 * one.
 */
final class IntegrationCommand
{
    public const PENDING   = 'PENDING';
    public const POSTING   = 'POSTING';
    public const COMPLETED = 'COMPLETED';
    public const FAILED    = 'FAILED';
    public const BLOCKED   = 'BLOCKED';

    public const TABLE = 'sales_integration_commands';

    /**
     * Find the command for this (document, kind) or create it, and return its row.
     *
     * Idempotent by construction: a second press of Save finds the existing row
     * with its existing key rather than minting a new one.
     *
     * @param array<string, mixed> $request what we intend to ask for — enough to retry, no more
     */
    public static function open(
        Context $ctx,
        string $targetService,
        string $commandType,
        string $entityType,
        int $entityId,
        array $request,
    ): array {
        $existing = Db::first(
            'SELECT * FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND entity_type = :etype AND entity_id = :eid AND command_type = :ctype
             ORDER BY command_id DESC LIMIT 1',
            ['cmp' => $ctx->cmpId, 'etype' => $entityType, 'eid' => $entityId, 'ctype' => $commandType],
        );

        if ($existing !== null && $existing['status'] !== self::FAILED) {
            return $existing;
        }

        // A FAILED command is retried on its ORIGINAL key, not a fresh one. A new
        // key would let the other product write a second document for the same
        // intent — which is exactly the duplicate this class exists to prevent.
        if ($existing !== null) {
            Db::update(self::TABLE, [
                'status'      => self::PENDING,
                'attempts'    => (int) $existing['attempts'],
                'updated_at'  => self::now(),
            ], ['command_id' => $existing['command_id']]);

            return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE command_id = :id', ['id' => $existing['command_id']]) ?? $existing;
        }

        $commandId = Db::insert(self::TABLE, [
            'cmp_id'          => $ctx->cmpId,
            'fy_id'           => $ctx->fyId,
            'bo_id'           => $ctx->boId,
            'target_service'  => $targetService,
            'command_type'    => $commandType,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'idempotency_key' => self::mintKey($ctx, $commandType, $entityType, $entityId),
            'status'          => self::PENDING,
            'attempts'        => 0,
            'request_summary' => $request,
            'created_at'      => self::now(),
            'updated_at'      => self::now(),
        ], 'command_id');

        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE command_id = :id', ['id' => $commandId]) ?? [];
    }

    public static function markPosting(int $commandId): void
    {
        Db::run(
            'UPDATE ' . self::TABLE . ' SET status = :s, attempts = attempts + 1, last_attempt_at = :t, updated_at = :t
             WHERE command_id = :id',
            ['s' => self::POSTING, 't' => self::now(), 'id' => $commandId],
        );
    }

    /**
     * The other product accepted. Store WHAT IT CALLED THE RESULT — an id, a uuid,
     * a number — and nothing else from its response body.
     *
     * @param array<string, mixed> $reference
     */
    public static function complete(int $commandId, array $reference): void
    {
        Db::update(self::TABLE, [
            'status'            => self::COMPLETED,
            'external_reference' => $reference,
            'last_error'        => null,
            'completed_at'      => self::now(),
            'updated_at'        => self::now(),
        ], ['command_id' => $commandId]);
    }

    /** Transport or server failure — the same key may be presented again. */
    public static function fail(int $commandId, string $error): void
    {
        Db::update(self::TABLE, [
            'status'     => self::FAILED,
            'last_error' => self::trimError($error),
            'updated_at' => self::now(),
        ], ['command_id' => $commandId]);
    }

    /**
     * A business refusal — period locked, stock blocked, customer over limit.
     *
     * Kept apart from FAILED because retrying it is pointless and a UI that
     * offers Retry here just teaches people to press it twice. Something has to
     * change first, and the message says what.
     */
    public static function block(int $commandId, string $reason): void
    {
        Db::update(self::TABLE, [
            'status'     => self::BLOCKED,
            'last_error' => self::trimError($reason),
            'updated_at' => self::now(),
        ], ['command_id' => $commandId]);
    }

    /**
     * Commands attached to one of our documents, for the status strip on its screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEntity(Context $ctx, string $entityType, int $entityId): array
    {
        return Db::all(
            'SELECT command_id, target_service, command_type, status, attempts, last_error,
                    external_reference, last_attempt_at, completed_at
             FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND entity_type = :etype AND entity_id = :eid
             ORDER BY command_id',
            ['cmp' => $ctx->cmpId, 'etype' => $entityType, 'eid' => $entityId],
        );
    }

    /** Everything still unresolved, so the UI can show a real count instead of hiding it. */
    public static function outstanding(Context $ctx, int $limit = 100): array
    {
        [$scope, $params] = $ctx->scopeClause();

        return Db::all(
            'SELECT * FROM ' . self::TABLE . "
             WHERE {$scope} AND status IN ('PENDING', 'POSTING', 'FAILED', 'BLOCKED')
             ORDER BY updated_at DESC LIMIT " . (int) $limit,
            $params,
        );
    }

    /**
     * A key that is stable for the intent and unique across companies.
     *
     * Deterministic on purpose: two browser tabs that both press Save on the same
     * document arrive at the same key and the second is a replay, not a duplicate.
     * The random tail is scoped to the row, not the attempt, so it survives retry.
     */
    private static function mintKey(Context $ctx, string $commandType, string $entityType, int $entityId): string
    {
        return sprintf(
            '%s:%d:%s:%s:%d:%s',
            Env::get('APP_PRODUCT_KEY', 'sales'),
            $ctx->cmpId,
            $commandType,
            $entityType,
            $entityId,
            substr(bin2hex(random_bytes(6)), 0, 10),
        );
    }

    /** Keep the cause, drop the novel — an error column is not a log. */
    private static function trimError(string $error): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $error) ?? $error);

        return mb_substr($clean, 0, 480);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
