<?php

namespace App\Services\Audit;

use App\Http\Controllers;
use App\Models;

/**
 * What the audit log watches, and how it names things.
 *
 * Adding a model: one MODELS entry (type key => class, label attributes,
 * per-model ignored attributes), plus — when its controller updates or
 * soft-deletes with a query (`Model::whereId($id)->update([...])`, which
 * fires no model event) — a CONTROLLERS entry so AuditQueryWrites still
 * catches it. The frontend needs an `Al_T_<type>` key in en.php.
 */
class AuditRegistry
{
    /**
     * type => [class, label attributes (first non-empty wins), ignored attributes]
     */
    public const MODELS = [
        'sale'                 => [Models\Sale::class, ['Ref'], ['paid_amount', 'payment_statut', 'qte_retturn', 'total_retturn']],
        'purchase'             => [Models\Purchase::class, ['Ref'], ['paid_amount', 'payment_statut']],
        'sale_return'          => [Models\SaleReturn::class, ['Ref'], ['paid_amount', 'payment_statut']],
        'purchase_return'      => [Models\PurchaseReturn::class, ['Ref'], ['paid_amount', 'payment_statut']],
        'quotation'            => [Models\Quotation::class, ['Ref'], []],
        'adjustment'           => [Models\Adjustment::class, ['Ref'], []],
        'transfer'             => [Models\Transfer::class, ['Ref'], []],
        'damage'               => [Models\Damage::class, ['Ref'], []],
        'sale_payment'         => [Models\PaymentSale::class, ['Ref'], []],
        'purchase_payment'     => [Models\PaymentPurchase::class, ['Ref'], []],
        'sale_return_payment'  => [Models\PaymentSaleReturns::class, ['Ref'], []],
        'purchase_return_payment' => [Models\PaymentPurchaseReturns::class, ['Ref'], []],
        'expense'              => [Models\Expense::class, ['Ref'], []],
        'deposit'              => [Models\Deposit::class, ['deposit_ref'], []],
        'money_transfer'       => [Models\TransferMoney::class, [], []],
        'product'              => [Models\Product::class, ['name', 'code'], []],
        'client'               => [Models\Client::class, ['name', 'code'], ['points']],
        'provider'             => [Models\Provider::class, ['name', 'code'], []],
        'user'                 => [Models\User::class, ['username', 'email'], []],
        'role'                 => [Models\Role::class, ['name'], []],
        'employee'             => [Models\Employee::class, ['username', 'firstname'], ['remaining_leave']],
        'warehouse'            => [Models\Warehouse::class, ['name'], []],
        'category'             => [Models\Category::class, ['name'], []],
        'brand'                => [Models\Brand::class, ['name'], []],
        'unit'                 => [Models\Unit::class, ['name'], []],
        'account'              => [Models\Account::class, ['account_name', 'account_num'], ['balance']],
        'expense_category'     => [Models\ExpenseCategory::class, ['name'], []],
        'deposit_category'     => [Models\DepositCategory::class, ['title'], []],
        'payment_method'       => [Models\PaymentMethod::class, ['name'], []],
        'currency'             => [Models\Currency::class, ['name', 'code'], []],
        'tax_rate'             => [Models\TaxRate::class, ['name'], []],
        'promotion'            => [Models\Promotion::class, ['name', 'code'], []],
        'store_coupon'         => [Models\StoreCoupon::class, ['code'], ['used_count']],
        'cash_register'        => [Models\CashRegister::class, [], ['total_sales', 'cash_in', 'cash_out']],
        'setting'              => [Models\Setting::class, [], []],
        'pos_setting'          => [Models\PosSetting::class, [], []],
    ];

    /**
     * Controllers whose update / destroy / delete_by_selection may write with
     * a query. controller => audited type
     */
    public const CONTROLLERS = [
        Controllers\SalesController::class           => 'sale',
        Controllers\PurchasesController::class       => 'purchase',
        Controllers\SalesReturnController::class     => 'sale_return',
        Controllers\PurchasesReturnController::class => 'purchase_return',
        Controllers\QuotationsController::class      => 'quotation',
        Controllers\AdjustmentController::class      => 'adjustment',
        Controllers\TransferController::class        => 'transfer',
        Controllers\DamageController::class          => 'damage',
        Controllers\PaymentSalesController::class    => 'sale_payment',
        Controllers\PaymentPurchasesController::class => 'purchase_payment',
        Controllers\PaymentSaleReturnsController::class => 'sale_return_payment',
        Controllers\PaymentPurchaseReturnsController::class => 'purchase_return_payment',
        Controllers\ExpensesController::class        => 'expense',
        Controllers\DepositsController::class        => 'deposit',
        Controllers\TransferMoneyController::class   => 'money_transfer',
        Controllers\ProductsController::class        => 'product',
        Controllers\ClientController::class          => 'client',
        Controllers\ProvidersController::class       => 'provider',
        Controllers\UserController::class            => 'user',
        Controllers\PermissionsController::class     => 'role',
        Controllers\hrm\EmployeesController::class   => 'employee',
        Controllers\WarehouseController::class       => 'warehouse',
        Controllers\CategorieController::class       => 'category',
        Controllers\BrandsController::class          => 'brand',
        Controllers\UnitsController::class           => 'unit',
        Controllers\AccountController::class         => 'account',
        Controllers\CategoryExpenseController::class => 'expense_category',
        Controllers\CategoryDepositController::class => 'deposit_category',
        Controllers\PaymentMethodController::class   => 'payment_method',
        Controllers\CurrencyController::class        => 'currency',
        Controllers\PromotionsController::class      => 'promotion',
    ];

    /** Controller actions that change the route's record (update/destroy: last route parameter) or records (selectedIds). */
    public const WRITE_ACTIONS = ['update', 'destroy', 'delete_by_selection', 'deleteBySelection'];

    /** Never worth a log line on their own (bookkeeping the system maintains). */
    public const IGNORED = ['id', 'created_at', 'updated_at', 'remember_token', 'deleted_at'];

    /** Sync bookkeeping written by the integrations on every run. */
    public const IGNORED_PATTERN = '/^(woocommerce|woo|shopify|salla|xero|prestashop|opencart|jumia|quickbooks|qbo)_|^sync_|_synced_at$|^last_sync/i';

    /** Logged as "changed" without the value. */
    public const SECRET_PATTERN = '/password|secret|token|api_key|apikey|private_key|pin_code|pin_hash/i';

    /** Long text is cut to this many characters. */
    public const MAX_VALUE_LENGTH = 1000;

    public static function typeFor(object|string $model): ?string
    {
        static $byClass = null;
        $byClass ??= array_combine(array_column(self::MODELS, 0), array_keys(self::MODELS));

        return $byClass[is_object($model) ? get_class($model) : $model] ?? null;
    }

    public static function classFor(string $type): ?string
    {
        return self::MODELS[$type][0] ?? null;
    }

    public static function typeForController(?string $controller): ?string
    {
        return $controller ? (self::CONTROLLERS[ltrim($controller, '\\')] ?? null) : null;
    }

    /** Attributes of $type that are dropped from the diff. */
    public static function isIgnored(string $type, string $attribute): bool
    {
        return in_array($attribute, self::IGNORED, true)
            || in_array($attribute, self::MODELS[$type][2] ?? [], true)
            || preg_match(self::IGNORED_PATTERN, $attribute) === 1;
    }

    public static function isSecret(string $attribute): bool
    {
        return preg_match(self::SECRET_PATTERN, $attribute) === 1;
    }

    /** A human name for the record from its raw attributes, or null. */
    public static function labelFor(string $type, array $attributes): ?string
    {
        foreach (self::MODELS[$type][1] ?? [] as $attr) {
            $value = $attributes[$attr] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 191);
            }
        }

        if ($type === 'user' || $type === 'employee') {
            $name = trim(($attributes['firstname'] ?? '').' '.($attributes['lastname'] ?? ''));

            return $name !== '' ? mb_substr($name, 0, 191) : null;
        }

        return null;
    }
}
