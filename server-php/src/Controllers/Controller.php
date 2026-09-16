<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;

/**
 * Shared entry work for every scoped endpoint.
 *
 * Authenticate, resolve the company scope, and CHECK THAT THIS SESSION MAY OPEN
 * THAT COMPANY — in that order, before a controller touches a row. The tenant
 * check is not optional and is not something an individual endpoint remembers to
 * do: it happens here, once, for all of them.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(): array
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        return [$auth, $ctx];
    }
}
