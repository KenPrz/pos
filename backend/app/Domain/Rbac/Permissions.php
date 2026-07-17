<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

/**
 * The permission catalog from docs/05-rbac.md, as code.
 *
 * This list and the endpoint list in docs/03-api.md are the same list — an endpoint with
 * no permission is a bug in one of the two.
 *
 * Naming is `resource.action`. The set marked MONEY_LEAVES is not a judgement call: it
 * is the fraud surface from docs/01-architecture.md, enumerated. When adding a
 * permission, the question that decides its role is "can this be used to take money out
 * of the till without a customer noticing?"
 */
final class Permissions
{
    // Orders
    public const string ORDER_OPEN = 'order.open';
    public const string ORDER_LINE_ADD = 'order.line.add';
    public const string ORDER_LINE_UPDATE = 'order.line.update';
    public const string ORDER_LINE_VOID = 'order.line.void';
    public const string ORDER_DISCOUNT_APPLY = 'order.discount.apply';
    public const string ORDER_VOID = 'order.void';
    public const string ORDER_REOPEN = 'order.reopen';
    public const string ORDER_TRANSFER = 'order.transfer';

    // Payments and refunds
    public const string PAYMENT_TAKE = 'payment.take';
    public const string PAYMENT_VOID = 'payment.void';
    public const string REFUND_CREATE = 'refund.create';

    // Shifts and drawer
    public const string SHIFT_OPEN = 'shift.open';
    public const string SHIFT_CLOSE = 'shift.close';
    public const string SHIFT_CASH_MOVEMENT = 'shift.cash_movement';
    public const string SHIFT_APPROVE_VARIANCE = 'shift.approve_variance';
    public const string DRAWER_NO_SALE = 'drawer.no_sale';

    // Catalog and admin
    public const string CATALOG_VIEW = 'catalog.view';
    public const string CATALOG_MANAGE = 'catalog.manage';
    public const string USER_MANAGE = 'user.manage';
    public const string LOCATION_MANAGE = 'location.manage';
    public const string REGISTER_ENROLL = 'register.enroll';

    // Reports
    public const string REPORT_Z_VIEW = 'report.z.view';
    public const string REPORT_SALES_VIEW = 'report.sales.view';
    public const string AUDIT_VIEW = 'audit.view';

    // Stock
    public const string STOCK_ADJUST = 'stock.adjust';
    public const string STOCK_RECEIVE = 'stock.receive';
    public const string STOCK_COUNT = 'stock.count';
    public const string STOCK_MOVEMENTS_VIEW = 'stock.movements.view';

    /**
     * Every permission that can be used to remove money from a till without a customer
     * noticing. Supervisor-or-above, always.
     *
     * `STOCK_ADJUST` belongs here, not just alongside it: an unsupervised inventory
     * adjustment is the classic shrinkage cover-up — the same fraud surface as a till
     * discrepancy, just measured in units instead of cents.
     *
     * @return list<string>
     */
    public static function moneyLeaves(): array
    {
        return [
            self::ORDER_LINE_VOID,
            self::ORDER_DISCOUNT_APPLY,
            self::ORDER_VOID,
            self::ORDER_REOPEN,
            self::PAYMENT_VOID,
            self::REFUND_CREATE,
            self::SHIFT_CASH_MOVEMENT,
            self::DRAWER_NO_SALE,
            self::STOCK_ADJUST,
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ORDER_OPEN,
            self::ORDER_LINE_ADD,
            self::ORDER_LINE_UPDATE,
            self::ORDER_LINE_VOID,
            self::ORDER_DISCOUNT_APPLY,
            self::ORDER_VOID,
            self::ORDER_REOPEN,
            self::ORDER_TRANSFER,
            self::PAYMENT_TAKE,
            self::PAYMENT_VOID,
            self::REFUND_CREATE,
            self::SHIFT_OPEN,
            self::SHIFT_CLOSE,
            self::SHIFT_CASH_MOVEMENT,
            self::SHIFT_APPROVE_VARIANCE,
            self::DRAWER_NO_SALE,
            self::CATALOG_VIEW,
            self::CATALOG_MANAGE,
            self::USER_MANAGE,
            self::LOCATION_MANAGE,
            self::REGISTER_ENROLL,
            self::REPORT_Z_VIEW,
            self::REPORT_SALES_VIEW,
            self::AUDIT_VIEW,
            self::STOCK_ADJUST,
            self::STOCK_RECEIVE,
            self::STOCK_COUNT,
            self::STOCK_MOVEMENTS_VIEW,
        ];
    }

    /**
     * What a cashier can do alone: run a shift.
     *
     * They open and close their own drawer without a supervisor, because requiring one
     * for a routine open means either a manager tied to the terminal all morning or a
     * manager's PIN on a sticky note — and the second is what actually happens. Variance
     * *approval* is where a supervisor's time belongs.
     *
     * @return list<string>
     */
    public static function cashier(): array
    {
        return [
            self::ORDER_OPEN,
            self::ORDER_LINE_ADD,
            self::ORDER_LINE_UPDATE,
            self::PAYMENT_TAKE,
            self::SHIFT_OPEN,
            self::SHIFT_CLOSE,
            self::CATALOG_VIEW,
            self::REPORT_Z_VIEW,
        ];
    }

    /**
     * Everything a cashier can do, plus the fraud surface.
     *
     * `STOCK_RECEIVE` and `STOCK_COUNT` ride along here rather than inventing a fourth
     * role: they aren't fraud-surface on their own (`STOCK_ADJUST` — in `moneyLeaves()`
     * — is), but a store with only two roles has nowhere else sensible to put them.
     *
     * @return list<string>
     */
    public static function supervisor(): array
    {
        return [
            ...self::cashier(),
            ...self::moneyLeaves(),
            self::ORDER_TRANSFER,
            self::SHIFT_APPROVE_VARIANCE,
            self::REPORT_SALES_VIEW,
            self::STOCK_RECEIVE,
            self::STOCK_COUNT,
            self::STOCK_MOVEMENTS_VIEW,
        ];
    }

    /*
     * There is no admin() list. Admin is `users.is_admin` and bypasses the gate entirely
     * (Gate::before), so it needs no permission set — see docs/05-rbac.md.
     *
     * A consequence worth knowing: catalog.manage, user.manage, location.manage,
     * register.enroll and audit.view are granted by no role. That is correct — only
     * admins do those things, and admins bypass. The names still exist because the
     * endpoints still check them, and an admin-only endpoint must still name what it
     * requires.
     */
}
