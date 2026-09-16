<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Controllers\CatalogController;
use Aicountly\Api\Controllers\CustomersController;
use Aicountly\Api\Controllers\DashboardController;
use Aicountly\Api\Controllers\FollowupsController;
use Aicountly\Api\Controllers\ManageController;
use Aicountly\Api\Controllers\MastersController;
use Aicountly\Api\Controllers\OrdersController;
use Aicountly\Api\Controllers\QuotationsController;
use Aicountly\Api\Controllers\ReturnsController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\TargetsController;

/**
 * Every route this API serves.
 *
 * The shape follows the rest of the fleet: `/api/v1/<resource>`, company context
 * on the query string or in the body, `{data}` / `{data, meta}` envelopes.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // Who am I, what may I do, and what is this company configured like.
        // The company switcher. Live reads from Manage, NOT company-scoped --
        // this is what the caller uses to choose the company in the first place.
        $router->get('v1/manage/companies', [ManageController::class, 'companies']);
        $router->get('v1/manage/companyinfo', [ManageController::class, 'companyInfo']);

        $router->get('v1/session', [SettingsController::class, 'session']);
        $router->get('v1/permissions', [SettingsController::class, 'permissions']);
        $router->get('v1/settings', [SettingsController::class, 'show']);
        $router->put('v1/settings', [SettingsController::class, 'update']);

        // Sales access administration. Profiles and assignments are OURS;
        // the people are Manage's and are read live, never copied here.
        $router->get('v1/access/profiles', [AccessController::class, 'profiles']);
        $router->post('v1/access/profiles', [AccessController::class, 'createProfile']);
        $router->put('v1/access/profiles/{id}', [AccessController::class, 'updateProfile']);
        $router->delete('v1/access/profiles/{id}', [AccessController::class, 'deleteProfile']);
        $router->get('v1/access/members', [AccessController::class, 'members']);
        // PUT, not POST: the body is the WHOLE set of profiles this person
        // holds, so the same request twice leaves the same state.
        $router->put('v1/access/members/{uuid}', [AccessController::class, 'assign']);
        $router->delete('v1/access/members/{uuid}', [AccessController::class, 'removeOrphan']);

        // Read-through to the products that own the data. These exist so the
        // browser makes one same-origin call instead of four cross-origin ones,
        // and so the session key never has to be handed to another origin.
        // They are pass-throughs: nothing they return is stored.
        $router->get('v1/catalog/items', [CatalogController::class, 'items']);
        $router->get('v1/catalog/items/search', [CatalogController::class, 'searchItems']);
        $router->get('v1/catalog/items/{id}', [CatalogController::class, 'item']);
        $router->get('v1/catalog/availability', [CatalogController::class, 'availability']);
        $router->post('v1/catalog/availability/check', [CatalogController::class, 'checkAvailability']);
        $router->get('v1/catalog/warehouses', [CatalogController::class, 'warehouses']);
        $router->get('v1/catalog/uoms', [CatalogController::class, 'uoms']);
        $router->get('v1/catalog/customers', [CatalogController::class, 'customers']);
        $router->get('v1/catalog/customers/{id}/credit', [CatalogController::class, 'customerCredit']);
        $router->get('v1/catalog/tax-categories', [CatalogController::class, 'taxCategories']);
        $router->post('v1/catalog/price', [CatalogController::class, 'price']);

        // Sales-owned masters.
        $router->get('v1/territories', [MastersController::class, 'listTerritories']);
        $router->post('v1/territories', [MastersController::class, 'createTerritory']);
        $router->get('v1/channels', [MastersController::class, 'listChannels']);
        $router->post('v1/channels', [MastersController::class, 'createChannel']);
        $router->get('v1/salespeople', [MastersController::class, 'listSalespeople']);
        $router->post('v1/salespeople', [MastersController::class, 'createSalesperson']);
        $router->get('v1/price-books', [MastersController::class, 'listPriceBooks']);
        $router->post('v1/price-books', [MastersController::class, 'createPriceBook']);
        $router->get('v1/price-books/{id}', [MastersController::class, 'showPriceBook']);
        $router->put('v1/price-books/{id}', [MastersController::class, 'updatePriceBook']);
        $router->post('v1/price-books/{id}/rules', [MastersController::class, 'addPriceRule']);
        $router->delete('v1/price-books/{id}/rules/{ruleId}', [MastersController::class, 'deletePriceRule']);

        // Quotations. The specific routes come first: `{action}` would otherwise
        // swallow `revise` and `convert` and try to transition to them.
        $router->get('v1/quotations', [QuotationsController::class, 'index']);
        $router->get('v1/quotations/export', [QuotationsController::class, 'export']);
        $router->post('v1/quotations', [QuotationsController::class, 'create']);
        $router->get('v1/quotations/{id}', [QuotationsController::class, 'show']);
        $router->post('v1/quotations/{id}/revise', [QuotationsController::class, 'revise']);
        $router->post('v1/quotations/{id}/convert', [QuotationsController::class, 'convert']);
        $router->post('v1/quotations/{id}/{action}', [QuotationsController::class, 'transition']);

        // Orders and everything downstream of them.
        $router->get('v1/orders', [OrdersController::class, 'index']);
        $router->get('v1/orders/export', [OrdersController::class, 'export']);
        $router->post('v1/orders', [OrdersController::class, 'create']);
        $router->get('v1/orders/{id}', [OrdersController::class, 'show']);
        $router->get('v1/orders/{id}/fulfilment-status', [OrdersController::class, 'fulfilmentStatus']);
        $router->post('v1/orders/{id}/confirm', [OrdersController::class, 'confirm']);
        $router->post('v1/orders/{id}/reserve', [OrdersController::class, 'reserve']);
        $router->post('v1/orders/{id}/dispatch', [OrdersController::class, 'dispatch']);
        $router->post('v1/orders/{id}/invoice', [OrdersController::class, 'invoice']);
        $router->post('v1/orders/{id}/cancel', [OrdersController::class, 'cancel']);
        $router->post('v1/invoice-requests/{id}/retry', [OrdersController::class, 'retryInvoice']);

        // Returns / RMA.
        $router->get('v1/returns', [ReturnsController::class, 'index']);
        $router->post('v1/returns', [ReturnsController::class, 'create']);
        $router->get('v1/returns/{id}', [ReturnsController::class, 'show']);
        $router->post('v1/returns/{id}/approve', [ReturnsController::class, 'approve']);
        $router->post('v1/returns/{id}/receive', [ReturnsController::class, 'receive']);
        $router->post('v1/returns/{id}/credit-note', [ReturnsController::class, 'creditNote']);

        // The five dashboards. One endpoint each: a view loads what it draws and
        // nothing else, so the Overview never waits on the ageing report it does
        // not show.
        $router->get('v1/dashboard/overview', [DashboardController::class, 'overview']);
        $router->get('v1/dashboard/pipeline', [DashboardController::class, 'pipeline']);
        $router->get('v1/dashboard/pipeline/lane', [DashboardController::class, 'pipelineLane']);
        $router->get('v1/dashboard/fulfilment', [DashboardController::class, 'fulfilment']);
        $router->get('v1/dashboard/collections', [DashboardController::class, 'collections']);
        $router->get('v1/dashboard/forecast', [DashboardController::class, 'forecast']);
        // The original single-dashboard path, kept working and pointed at the
        // Overview so an older client is not broken by the split.
        $router->get('v1/dashboard', [DashboardController::class, 'overview']);

        $router->get('v1/approvals', [DashboardController::class, 'approvals']);
        $router->get('v1/integration-commands', [DashboardController::class, 'commands']);

        // Customers, as Sales sees them: our documents joined at read time to
        // Books' live position. Not a customer master and never becoming one.
        $router->get('v1/customers', [CustomersController::class, 'index']);
        $router->get('v1/customers/{id}', [CustomersController::class, 'show']);

        // Targets and quotas — the half of sales_targets that had no API.
        $router->get('v1/targets', [TargetsController::class, 'index']);
        $router->get('v1/targets/attainment', [TargetsController::class, 'attainment']);
        $router->post('v1/targets', [TargetsController::class, 'create']);
        $router->put('v1/targets/{id}', [TargetsController::class, 'update']);
        $router->delete('v1/targets/{id}', [TargetsController::class, 'destroy']);

        // Follow-ups: what we said to a customer and what they said back.
        $router->get('v1/followups', [FollowupsController::class, 'index']);
        $router->get('v1/followups/draft', [FollowupsController::class, 'draft']);
        $router->post('v1/followups', [FollowupsController::class, 'create']);
        $router->post('v1/followups/{id}/resolve', [FollowupsController::class, 'resolve']);
    }
}
