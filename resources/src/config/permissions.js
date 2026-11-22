/**
 * Permission catalogue — every group/checkbox from the legacy
 * Create_permission.vue template (extracted, not hand-written), so the Vue 3
 * role editor offers exactly the same 239 permissions in the same order.
 * l = i18n key, f = fallback label used when the key is missing.
 * r = restriction: ticking it TAKES AWAY access (hide cost, block export),
 *     so bulk select-all / group toggles skip it.
 */
export const PERMISSION_GROUPS = [
    {
        "label": "dashboard",
        "items": [
            {
                "v": "dashboard",
                "l": "dashboard"
            },
            {
                "v": "sales_3d_dashboard",
                "l": "sales_3d_dashboard"
            }
        ]
    },
    {
        "label": "UserManagement",
        "items": [
            {
                "v": "users_view",
                "l": "View"
            },
            {
                "v": "users_add",
                "l": "Add"
            },
            {
                "v": "users_edit",
                "l": "Edit"
            },
            {
                "v": "users_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "UserPermissions",
        "items": [
            {
                "v": "permissions_view",
                "l": "View"
            },
            {
                "v": "permissions_add",
                "l": "Add"
            },
            {
                "v": "permissions_edit",
                "l": "Edit"
            },
            {
                "v": "permissions_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Products",
        "items": [
            {
                "v": "products_view",
                "l": "View"
            },
            {
                "v": "products_add",
                "l": "Add"
            },
            {
                "v": "products_edit",
                "l": "Edit"
            },
            {
                "v": "products_delete",
                "l": "Del"
            },
            {
                "v": "barcode_view",
                "l": "Barcode"
            },
            {
                "v": "product_import",
                "l": "import_products"
            },
            {
                "v": "hide_product_cost",
                "l": "Perm_hide_product_cost",
                "f": "Hide Cost Price",
                "r": true
            },
            {
                "v": "disable_product_export",
                "l": "Perm_disable_product_export",
                "f": "Disable Product Export",
                "r": true
            },
            {
                "v": "category",
                "l": "Categories"
            },
            {
                "v": "subcategory",
                "l": "SubCategory"
            },
            {
                "v": "brand",
                "l": "Brand"
            },
            {
                "v": "unit",
                "l": "Units"
            },
            {
                "v": "count_stock",
                "l": "CountStock",
                "f": "Count Stock"
            },
            {
                "v": "subscription_product",
                "l": "subscription_product",
                "f": "Subscription Product"
            },
            {
                "v": "opening_stock_import",
                "l": "Perm_opening_stock_import",
                "f": "Opening Stock Import"
            },
            {
                "v": "serial_numbers",
                "l": "Serial_Numbers"
            },
            {
                "v": "size_guides",
                "l": "Size_Guides"
            },
            {
                "v": "batch_view",
                "l": "batch_view",
                "f": "View Batches"
            },
            {
                "v": "batch_manage",
                "l": "batch_manage",
                "f": "Manage Batches"
            },
            {
                "v": "batch_writeoff",
                "l": "batch_writeoff",
                "f": "Write-Off Batches"
            },
            {
                "v": "batch_force_override",
                "l": "batch_force_override",
                "f": "Override Expiry Block"
            },
            {
                "v": "expiry_report",
                "l": "expiry_report",
                "f": "Expiry Report"
            },
            {
                "v": "Batch_Register_Report",
                "l": "Batch_Register_Report",
                "f": "Batch Register Report"
            }
        ]
    },
    {
        "label": "LicK_Perm_Group",
        "items": [
            {
                "v": "license_keys_view",
                "l": "LicK_Perm_View",
                "f": "License keys: view keys & licenses"
            },
            {
                "v": "license_keys_manage",
                "l": "LicK_Perm_Manage",
                "f": "License keys: add/import, write off, issue, resend, settings"
            },
            {
                "v": "license_keys_control",
                "l": "LicK_Perm_Control",
                "f": "Licenses: extend, suspend, revoke, reset activations"
            },
            {
                "v": "license_keys_reveal",
                "l": "LicK_Perm_Reveal",
                "f": "License keys: reveal & export full keys"
            }
        ]
    },
    {
        "label": "StockAdjustement",
        "items": [
            {
                "v": "adjustment_view",
                "l": "View"
            },
            {
                "v": "adjustment_add",
                "l": "Add"
            },
            {
                "v": "adjustment_edit",
                "l": "Edit"
            },
            {
                "v": "adjustment_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Damages",
        "raw": true,
        "items": [
            {
                "v": "damage_view",
                "l": "Damages",
                "f": "Damages"
            }
        ]
    },
    {
        "label": "StockTransfers",
        "items": [
            {
                "v": "transfer_view",
                "l": "View"
            },
            {
                "v": "transfer_add",
                "l": "Add"
            },
            {
                "v": "transfer_edit",
                "l": "Edit"
            },
            {
                "v": "transfer_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Accounting",
        "items": [
            {
                "v": "accounting_dashboard",
                "l": "Perm_accounting_dashboard",
                "f": "Accounting dashboard"
            },
            {
                "v": "chart_of_accounts",
                "l": "Perm_chart_of_accounts",
                "f": "Chart of accounts"
            },
            {
                "v": "journal_entries",
                "l": "Perm_journal_entries",
                "f": "journal entries"
            },
            {
                "v": "trial_balance",
                "l": "Perm_trial_balance",
                "f": "Trial balance"
            },
            {
                "v": "accounting_profit_loss",
                "l": "Perm_accounting_profit_loss",
                "f": "Accounting profit & loss"
            },
            {
                "v": "balance_sheet",
                "l": "Perm_balance_sheet",
                "f": "Balance sheet"
            },
            {
                "v": "accounting_tax_report",
                "l": "Perm_accounting_tax_report",
                "f": "Accounting tax report"
            },
            {
                "v": "accounting_reconciliation",
                "l": "Perm_accounting_reconciliation",
                "f": "Accounting reconciliation"
            },
            {
                "v": "account",
                "l": "List_accounts"
            },
            {
                "v": "transfer_money",
                "l": "Perm_transfer_money",
                "f": "Transfer money"
            },
            {
                "v": "expense_view",
                "l": "expense_view"
            },
            {
                "v": "expense_add",
                "l": "expense_add"
            },
            {
                "v": "expense_edit",
                "l": "expense_edit"
            },
            {
                "v": "expense_delete",
                "l": "expense_delete"
            },
            {
                "v": "deposit_view",
                "l": "deposit_view"
            },
            {
                "v": "deposit_add",
                "l": "deposit_add"
            },
            {
                "v": "deposit_edit",
                "l": "deposit_edit"
            },
            {
                "v": "deposit_delete",
                "l": "deposit_delete"
            }
        ]
    },
    {
        "label": "Asset Management",
        "raw": true,
        "items": [
            {
                "v": "assets",
                "l": "Assets"
            },
            {
                "v": "asset_assignments",
                "l": "Assignments",
                "f": "Assignments"
            },
            {
                "v": "asset_maintenance",
                "l": "Maintenance",
                "f": "Maintenance"
            },
            {
                "v": "asset_transfers",
                "l": "Transfers",
                "f": "Transfers"
            },
            {
                "v": "asset_reports",
                "l": "Reports",
                "f": "Asset reports"
            }
        ]
    },
    {
        "label": "Service_Maintenance",
        "items": [
            {
                "v": "service_jobs",
                "l": "Service_Maintenance"
            }
        ]
    },
    {
        "label": "Sales",
        "items": [
            {
                "v": "Sales_view",
                "l": "View"
            },
            {
                "v": "Sales_add",
                "l": "Add"
            },
            {
                "v": "Sales_edit",
                "l": "Edit"
            },
            {
                "v": "Sales_delete",
                "l": "Del"
            },
            {
                "v": "Pos_view",
                "l": "pointofsales"
            },
            {
                "v": "pos_offline_sales",
                "l": "Perm_Offline_Sales",
                "f": "Offline sales (review, retry, discard)"
            },
            {
                "v": "pos_void_items",
                "l": "Perm_Pos_Void_Items",
                "f": "Remove POS items without approval (approve with PIN, voided items report)"
            },
            {
                "v": "pos_terminals_manage",
                "l": "Perm_Pos_Terminals_Manage",
                "f": "POS devices: add, test, refund & void card payments"
            },
            {
                "v": "pos_terminals_transactions",
                "l": "Perm_Pos_Terminals_Transactions",
                "f": "POS devices: view card transactions"
            },
            {
                "v": "promotion",
                "l": "Promotions",
                "f": "Promotions"
            },
            {
                "v": "real_time_sales_counter",
                "l": "Real_time_Sales_Counter",
                "f": "Real-time Sales Counter"
            },
            {
                "v": "kitchen_display_view",
                "l": "KitchenDisplay",
                "f": "Kitchen Display"
            },
            {
                "v": "kitchen_display_manage",
                "l": "KitchenDisplay",
                "f": "Kitchen Display",
                "l2": "Manage",
                "f2": "Manage",
                "sep": " - "
            },
            {
                "v": "customer_display_screen_setup",
                "l": "Perm_customer_display_screen_setup",
                "f": "customer display screen setup"
            },
            {
                "v": "shipment",
                "l": "Shipments"
            },
            {
                "v": "edit_product_sale",
                "l": "Change_product_details"
            },
            {
                "v": "edit_tax_discount_shipping_sale",
                "l": "edit_tax_and_discount_and_shipping"
            }
        ]
    },
    {
        "label": "Commissions",
        "items": [
            {
                "v": "commissions_view",
                "l": "View"
            },
            {
                "v": "commissions_add",
                "l": "Add"
            },
            {
                "v": "commissions_edit",
                "l": "Edit"
            },
            {
                "v": "commissions_delete",
                "l": "Del"
            },
            {
                "v": "webhooks_view",
                "l": "View"
            },
            {
                "v": "webhooks_add",
                "l": "Add"
            },
            {
                "v": "webhooks_edit",
                "l": "Edit"
            },
            {
                "v": "webhooks_delete",
                "l": "Del"
            },
            {
                "v": "knowledge_base_view",
                "l": "View",
                "f": "View",
                "l2": "Manage",
                "f2": "Manage",
                "sep": " / "
            }
        ]
    },
    {
        "label": "Purchases",
        "items": [
            {
                "v": "Purchases_view",
                "l": "View"
            },
            {
                "v": "Purchases_add",
                "l": "Add"
            },
            {
                "v": "Purchases_edit",
                "l": "Edit"
            },
            {
                "v": "Purchases_delete",
                "l": "Del"
            },
            {
                "v": "edit_product_purchase",
                "l": "Change_product_details"
            },
            {
                "v": "edit_tax_discount_shipping_purchase",
                "l": "edit_tax_and_discount_and_shipping"
            }
        ]
    },
    {
        "label": "Quotations",
        "items": [
            {
                "v": "Quotations_view",
                "l": "View"
            },
            {
                "v": "Quotations_add",
                "l": "Add"
            },
            {
                "v": "Quotations_edit",
                "l": "Edit"
            },
            {
                "v": "Quotations_delete",
                "l": "Del"
            },
            {
                "v": "edit_product_quotation",
                "l": "Change_product_details"
            },
            {
                "v": "edit_tax_discount_shipping_quotation",
                "l": "edit_tax_and_discount_and_shipping"
            }
        ]
    },
    {
        "label": "SalesReturn",
        "items": [
            {
                "v": "Sale_Returns_view",
                "l": "View"
            },
            {
                "v": "Sale_Returns_add",
                "l": "Add"
            },
            {
                "v": "Sale_Returns_edit",
                "l": "Edit"
            },
            {
                "v": "Sale_Returns_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PurchasesReturn",
        "items": [
            {
                "v": "Purchase_Returns_view",
                "l": "View"
            },
            {
                "v": "Purchase_Returns_add",
                "l": "Add"
            },
            {
                "v": "Purchase_Returns_edit",
                "l": "Edit"
            },
            {
                "v": "Purchase_Returns_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PaymentsSales",
        "items": [
            {
                "v": "payment_sales_view",
                "l": "View"
            },
            {
                "v": "payment_sales_add",
                "l": "Add"
            },
            {
                "v": "payment_sales_edit",
                "l": "Edit"
            },
            {
                "v": "payment_sales_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PaymentsPurchases",
        "items": [
            {
                "v": "payment_purchases_view",
                "l": "View"
            },
            {
                "v": "payment_purchases_add",
                "l": "Add"
            },
            {
                "v": "payment_purchases_edit",
                "l": "Edit"
            },
            {
                "v": "payment_purchases_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PaymentsReturns",
        "items": [
            {
                "v": "payment_returns_view",
                "l": "View"
            },
            {
                "v": "payment_returns_add",
                "l": "Add"
            },
            {
                "v": "payment_returns_edit",
                "l": "Edit"
            },
            {
                "v": "payment_returns_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Customers",
        "items": [
            {
                "v": "Customers_view",
                "l": "View"
            },
            {
                "v": "Customers_add",
                "l": "Add"
            },
            {
                "v": "Customers_edit",
                "l": "Edit"
            },
            {
                "v": "Customers_delete",
                "l": "Del"
            },
            {
                "v": "customers_import",
                "l": "Import_Customers"
            },
            {
                "v": "pay_due",
                "l": "pay_all_sell_due_at_a_time"
            },
            {
                "v": "pay_sale_return_due",
                "l": "pay_all_sell_return_due_at_a_time"
            },
            {
                "v": "customer_wallet_view",
                "l": "CW_Perm_View",
                "f": "Customer wallet: balances, ledger & withdrawals"
            },
            {
                "v": "customer_wallet_manage",
                "l": "CW_Perm_Manage",
                "f": "Customer wallet: top-up, refund, adjust, freeze, settings, withdrawals"
            },
            {
                "v": "gift_cards_manage",
                "l": "CW_Perm_Gift_Cards",
                "f": "Gift cards: issue, edit, redeem"
            }
        ]
    },
    {
        "label": "Client_Portal",
        "items": [
            {
                "v": "portal_accounts",
                "l": "Portal_Perm_Accounts",
                "f": "Portal dashboard, accounts & invitations"
            },
            {
                "v": "portal_settings",
                "l": "Portal_Perm_Settings",
                "f": "Portal settings & languages"
            }
        ]
    },
    {
        "label": "Suppliers",
        "items": [
            {
                "v": "Suppliers_view",
                "l": "View"
            },
            {
                "v": "Suppliers_add",
                "l": "Add"
            },
            {
                "v": "Suppliers_edit",
                "l": "Edit"
            },
            {
                "v": "Suppliers_delete",
                "l": "Del"
            },
            {
                "v": "Suppliers_import",
                "l": "Import_Suppliers"
            },
            {
                "v": "pay_supplier_due",
                "l": "pay_all_purchase_due_at_a_time"
            },
            {
                "v": "pay_purchase_return_due",
                "l": "pay_all_purchase_return_due_at_a_time"
            },
            {
                "v": "supplier_wallet_view",
                "l": "SW_Perm_View",
                "f": "Supplier wallet: balances & ledger"
            },
            {
                "v": "supplier_wallet_manage",
                "l": "SW_Perm_Manage",
                "f": "Supplier wallet: top-up, refund, adjust, freeze"
            }
        ]
    },
    {
        "label": "Reports",
        "items": [
            {
                "v": "Reports_payments_Sales",
                "l": "Reports_payments_Sales"
            },
            {
                "v": "Reports_payments_Purchases",
                "l": "Reports_payments_Purchases"
            },
            {
                "v": "Reports_payments_Sale_Returns",
                "l": "Reports_payments_Sale_Return"
            },
            {
                "v": "Reports_payments_purchase_Return",
                "l": "Reports_payments_Purchase_Return"
            },
            {
                "v": "inventory_valuation",
                "l": "inventory_valuation",
                "f": "Inventory Valuation Summary"
            },
            {
                "v": "report_transactions",
                "l": "report_transactions",
                "f": "Report Transactions"
            },
            {
                "v": "cash_flow_report",
                "l": "cash_flow_report",
                "f": "Cash flow report"
            },
            {
                "v": "report_attendance_summary",
                "l": "Perm_report_attendance_summary",
                "f": "Report attendance summary"
            },
            {
                "v": "seller_report",
                "l": "seller_report",
                "f": "Seller Report"
            },
            {
                "v": "report_sales_by_category",
                "l": "Sales_by_Category",
                "f": "Sales by Category"
            },
            {
                "v": "report_sales_by_brand",
                "l": "Sales_by_Brand",
                "f": "Sales by Brand"
            },
            {
                "v": "AI_Reports",
                "l": "AI_Reports",
                "f": "AI Reports"
            },
            {
                "v": "expenses_report",
                "l": "Page_Expenses_Report",
                "f": "Expenses Report"
            },
            {
                "v": "deposits_report",
                "l": "deposits_report",
                "f": "Deposits Report"
            },
            {
                "v": "Reports_sales",
                "l": "SalesReport"
            },
            {
                "v": "Reports_purchase",
                "l": "PurchasesReport"
            },
            {
                "v": "Reports_customers",
                "l": "CustomersReport"
            },
            {
                "v": "inactive_customers_report",
                "l": "inactive_customers_report",
                "f": "Inactive customers report"
            },
            {
                "v": "service_jobs_report",
                "l": "Service_Jobs_Report"
            },
            {
                "v": "checklist_completion_report",
                "l": "Checklist_Completion_Report"
            },
            {
                "v": "customer_maintenance_history_report",
                "l": "Customer_Maintenance_History_Report"
            },
            {
                "v": "Reports_suppliers",
                "l": "SuppliersReport"
            },
            {
                "v": "Reports_profit",
                "l": "ProfitandLoss"
            },
            {
                "v": "Reports_quantity_alerts",
                "l": "ProductQuantityAlerts"
            },
            {
                "v": "Warehouse_report",
                "l": "WarehouseStockChart"
            },
            {
                "v": "internal_location_report",
                "l": "Internal_Location_Report"
            },
            {
                "v": "Top_products",
                "l": "Top_Selling_Products"
            },
            {
                "v": "Top_customers",
                "l": "Top_customers"
            },
            {
                "v": "users_report",
                "l": "Users_Report"
            },
            {
                "v": "stock_report",
                "l": "stock_report"
            },
            {
                "v": "product_report",
                "l": "product_report"
            },
            {
                "v": "cash_register_report",
                "l": "cash_register_report",
                "f": "Cash Register Report"
            },
            {
                "v": "report_warranty",
                "l": "Warranty_Guarantee_Report",
                "f": "Warranty / Guarantee Report"
            },
            {
                "v": "zeroSalesProducts",
                "l": "Zero_Sales_Products_Report",
                "f": "zero Sales Products Report"
            },
            {
                "v": "Dead_Stock_Report",
                "l": "Dead_Stock_Report",
                "f": "Dead Stock Report"
            },
            {
                "v": "Stock_Aging_Report",
                "l": "Stock_Aging_Report",
                "f": "Stock Aging Report"
            },
            {
                "v": "Stock_Transfer_Report",
                "l": "Stock_Transfer_Report",
                "f": "Stock Transfer Report"
            },
            {
                "v": "Stock_Adjustment_Report",
                "l": "Stock_Adjustment_Report",
                "f": "Stock Adjustment Report"
            },
            {
                "v": "Top_Suppliers_Report",
                "l": "Top_Suppliers_Report",
                "f": "Top Suppliers Report"
            },
            {
                "v": "draft_invoices_report",
                "l": "draft_invoices_report",
                "f": "Draft Invoices Report"
            },
            {
                "v": "discount_summary_report",
                "l": "discount_summary_report",
                "f": "Discount Summary Report"
            },
            {
                "v": "due_collection_report",
                "l": "due_collection_report",
                "f": "Due Collection Report"
            },
            {
                "v": "pos_sales_report",
                "l": "pos_sales_report",
                "f": "POS Sales Report"
            },
            {
                "v": "tax_summary_report",
                "l": "tax_summary_report",
                "f": "Tax Summary Report"
            },
            {
                "v": "return_ratio_report",
                "l": "return_ratio_report",
                "f": "Return Ratio Report"
            },
            {
                "v": "negative_stock_report",
                "l": "negative_stock_report",
                "f": "Negative Stock Report"
            },
            {
                "v": "customer_loyalty_points_report",
                "l": "Customer_Loyalty_Points_Report"
            },
            {
                "v": "product_sales_report",
                "l": "product_sales_report"
            },
            {
                "v": "products_sold_summary",
                "l": "products_sold_summary"
            },
            {
                "v": "product_purchases_report",
                "l": "Product_purchases_report"
            },
            {
                "v": "report_error_logs",
                "l": "Perm_report_error_logs",
                "f": "Report error logs"
            },
            {
                "v": "report_device_management",
                "l": "Login_Activity_Report"
            },
            {
                "v": "analytics_report",
                "l": "analytics_report",
                "f": "Analytics Report"
            },
            {
                "v": "Stock_Inventory_Valuation",
                "l": "Stock_Inventory_Valuation",
                "f": "Stock Inventory Valuation"
            },
            {
                "v": "serial_numbers_report",
                "l": "Perm_serial_numbers_report",
                "f": "Serial Numbers Report"
            }
        ]
    },
    {
        "label": "HRM",
        "items": [
            {
                "v": "view_employee",
                "l": "view_employee"
            },
            {
                "v": "add_employee",
                "l": "Add_Employee"
            },
            {
                "v": "edit_employee",
                "l": "edit_employee"
            },
            {
                "v": "delete_employee",
                "l": "delete_employee"
            },
            {
                "v": "company",
                "l": "Company"
            },
            {
                "v": "department",
                "l": "department"
            },
            {
                "v": "designation",
                "l": "Designation"
            },
            {
                "v": "office_shift",
                "l": "Office_Shift"
            },
            {
                "v": "attendance",
                "l": "Attendance"
            },
            {
                "v": "leave",
                "l": "Leave_request"
            },
            {
                "v": "holiday",
                "l": "Holiday"
            },
            {
                "v": "payroll",
                "l": "payroll",
                "f": "Payroll"
            },
            {
                "v": "contracts",
                "l": "contracts",
                "f": "Contracts"
            },
            {
                "v": "bookings",
                "l": "bookings",
                "f": "Bookings"
            },
            {
                "v": "trays",
                "l": "trays",
                "f": "Trays"
            },
            {
                "v": "bookings_settings",
                "l": "Bkm_Perm_Settings",
                "f": "Booking settings"
            },
            {
                "v": "bookings_services",
                "l": "Bkm_Perm_Services",
                "f": "Booking services: durations, staff, website"
            },
            {
                "v": "bookings_staff",
                "l": "Bkm_Perm_Staff",
                "f": "Bookable staff and working hours"
            },
            {
                "v": "bookings_reports",
                "l": "Bkm_Perm_Reports",
                "f": "Booking dashboard and reports"
            }
        ]
    },
    {
        "label": "Projects Management",
        "raw": true,
        "items": [
            {
                "v": "projects",
                "l": "projects",
                "f": "Projects"
            },
            {
                "v": "tasks",
                "l": "tasks",
                "f": "Tasks"
            },
            {
                "v": "project_milestones",
                "l": "Milestones",
                "f": "Milestones"
            },
            {
                "v": "project_timesheets",
                "l": "Timesheets",
                "f": "Timesheets"
            },
            {
                "v": "project_reports",
                "l": "Reports",
                "f": "Project reports"
            }
        ]
    },
    {
        "label": "School",
        "raw": true,
        "items": [
            {
                "v": "school_dashboard",
                "l": "dashboard"
            },
            {
                "v": "school_students_view",
                "l": "View"
            },
            {
                "v": "school_students_add",
                "l": "Add"
            },
            {
                "v": "school_students_edit",
                "l": "Edit"
            },
            {
                "v": "school_students_delete",
                "l": "Del"
            },
            {
                "v": "school_teachers",
                "l": "Teachers",
                "f": "Teachers"
            },
            {
                "v": "school_academics",
                "l": "Perm_school_academics",
                "f": "Academic setup"
            },
            {
                "v": "school_enrollment",
                "l": "Enrolment",
                "f": "Enrolment"
            },
            {
                "v": "school_attendance",
                "l": "Attendance",
                "f": "Attendance"
            },
            {
                "v": "school_exams",
                "l": "Perm_school_exams",
                "f": "Exams & results"
            },
            {
                "v": "school_timetable",
                "l": "Timetable",
                "f": "Timetable"
            },
            {
                "v": "school_fees",
                "l": "Fees",
                "f": "Fees"
            },
            {
                "v": "school_reports",
                "l": "Reports",
                "f": "Reports"
            }
        ]
    },
    {
        "label": "Hospital (HMS)",
        "raw": true,
        "items": [
            {
                "v": "hms_dashboard",
                "l": "dashboard"
            },
            {
                "v": "hms_patients_view",
                "l": "View"
            },
            {
                "v": "hms_patients_add",
                "l": "Add"
            },
            {
                "v": "hms_patients_edit",
                "l": "Edit"
            },
            {
                "v": "hms_patients_delete",
                "l": "Del"
            },
            {
                "v": "hms_doctors",
                "l": "Doctors",
                "f": "Doctors"
            },
            {
                "v": "hms_departments",
                "l": "Departments",
                "f": "Departments"
            },
            {
                "v": "hms_appointments",
                "l": "Appointments",
                "f": "Appointments"
            },
            {
                "v": "hms_visits",
                "l": "Consultations",
                "f": "Consultations"
            },
            {
                "v": "hms_admissions",
                "l": "Admissions",
                "f": "Admissions"
            },
            {
                "v": "hms_wards",
                "l": "Hm_Wards_And_Beds",
                "f": "Wards & Beds"
            },
            {
                "v": "hms_lab",
                "l": "Laboratory",
                "f": "Laboratory"
            },
            {
                "v": "hms_billing",
                "l": "Billing",
                "f": "Billing"
            },
            {
                "v": "hms_reports",
                "l": "Reports",
                "f": "Reports"
            }
        ]
    },
    {
        "label": "Vehicle & Fleet",
        "raw": true,
        "items": [
            {
                "v": "fleet_vehicles_view",
                "l": "View"
            },
            {
                "v": "fleet_vehicles_add",
                "l": "Add"
            },
            {
                "v": "fleet_vehicles_edit",
                "l": "Edit"
            },
            {
                "v": "fleet_vehicles_delete",
                "l": "Del"
            },
            {
                "v": "fleet_maintenance",
                "l": "Maintenance",
                "f": "Maintenance"
            },
            {
                "v": "fleet_fuel",
                "l": "Fl_Fuel_Logs",
                "f": "Fuel Logs"
            },
            {
                "v": "fleet_assignments",
                "l": "Assignments",
                "f": "Assignments"
            },
            {
                "v": "fleet_reports",
                "l": "Reports",
                "f": "Reports"
            }
        ]
    },
    {
        "label": "Document Archive",
        "raw": true,
        "items": [
            {
                "v": "documents_view",
                "l": "View"
            },
            {
                "v": "documents_add",
                "l": "Add"
            },
            {
                "v": "documents_edit",
                "l": "Edit"
            },
            {
                "v": "documents_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Recruit",
        "items": [
            {
                "v": "recruit_job",
                "l": "Jobs"
            },
            {
                "v": "recruit_category",
                "l": "Job_Categories"
            },
            {
                "v": "recruit_candidate",
                "l": "Candidates"
            },
            {
                "v": "recruit_application",
                "l": "Applications"
            },
            {
                "v": "recruit_interview",
                "l": "Interviews"
            }
        ]
    },
    {
        "label": "Meeting_Management",
        "items": [
            {
                "v": "meeting",
                "l": "Meetings"
            },
            {
                "v": "meeting_attendance",
                "l": "Manage_Attendance"
            },
            {
                "v": "meeting_report",
                "l": "Reports"
            }
        ]
    },
    {
        "label": "Marketing_Management",
        "items": [
            {
                "v": "marketing_dashboard",
                "l": "Marketing_Dashboard"
            },
            {
                "v": "marketing_campaigns",
                "l": "Campaigns"
            },
            {
                "v": "marketing_segments",
                "l": "Customer_Segments"
            },
            {
                "v": "marketing_templates",
                "l": "Marketing_Templates"
            },
            {
                "v": "marketing_reports",
                "l": "Marketing_Reports"
            },
            {
                "v": "marketing_settings",
                "l": "Marketing_Settings"
            }
        ]
    },
    {
        "label": "Real_Estate",
        "items": [
            {
                "v": "realestate_properties",
                "l": "Properties"
            },
            {
                "v": "realestate_categories",
                "l": "Property_Categories"
            },
            {
                "v": "realestate_inquiries",
                "l": "Property_Inquiries"
            }
        ]
    },
    {
        "label": "Settings",
        "items": [
            {
                "v": "setting_system",
                "l": "SystemSettings"
            },
            {
                "v": "business_modules",
                "l": "Modules"
            },
            {
                "v": "update_settings",
                "l": "update_settings"
            },
            {
                "v": "login_device_management",
                "l": "login_device_management",
                "f": "Login Device Management"
            },
            {
                "v": "loyalty_rewards",
                "l": "Loyalty_Rewards"
            },
            {
                "v": "woocommerce_settings",
                "l": "woocommerce_settings",
                "f": "Woocommerce settings"
            },
            {
                "v": "payment_methods",
                "l": "payment_methods",
                "f": "Payment methods"
            },
            {
                "v": "quickbooks_settings",
                "l": "Perm_quickbooks_settings",
                "f": "Quickbooks settings"
            },
            {
                "v": "zapier_settings",
                "l": "Perm_zapier_settings",
                "f": "Zapier integration"
            },
            {
                "v": "api_keys",
                "l": "Perm_Api_Keys",
                "f": "REST API keys (Settings > API)"
            },
            {
                "v": "custom_domains",
                "l": "Perm_custom_domains",
                "f": "Custom domain (Settings > Custom Domain)"
            },
            {
                "v": "slack_settings",
                "l": "Perm_slack_settings",
                "f": "Slack integration"
            },
            {
                "v": "telegram_settings",
                "l": "Perm_telegram_settings",
                "f": "Telegram integration"
            },
            {
                "v": "salla_settings",
                "l": "Perm_salla_settings",
                "f": "Salla integration"
            },
            {
                "v": "xero_settings",
                "l": "Perm_xero_settings",
                "f": "Xero integration"
            },
            {
                "v": "prestashop_settings",
                "l": "Perm_prestashop_settings",
                "f": "PrestaShop integration"
            },
            {
                "v": "opencart_settings",
                "l": "Perm_opencart_settings",
                "f": "OpenCart integration"
            },
            {
                "v": "google_sheets_settings",
                "l": "Perm_google_sheets_settings",
                "f": "Google Sheets integration"
            },
            {
                "v": "mailchimp_settings",
                "l": "Perm_mailchimp_settings",
                "f": "Mailchimp integration"
            },
            {
                "v": "jumia_settings",
                "l": "Perm_jumia_settings",
                "f": "Jumia integration"
            },
            {
                "v": "printful_settings",
                "l": "Perm_printful_settings",
                "f": "Printful integration"
            },
            {
                "v": "zatca_settings",
                "l": "Zatca_E_Invoicing",
                "f": "ZATCA e-invoicing"
            },
            {
                "v": "sms_settings",
                "l": "sms_settings"
            },
            {
                "v": "notification_template",
                "l": "notification_template"
            },
            {
                "v": "pos_settings",
                "l": "pos_settings"
            },
            {
                "v": "payment_gateway",
                "l": "payment_gateway"
            },
            {
                "v": "mail_settings",
                "l": "mail_settings"
            },
            {
                "v": "currency",
                "l": "Currencies"
            },
            {
                "v": "multi_currency",
                "l": "Multi_Currency",
                "f": "Multi currency (change document currency)"
            },
            {
                "v": "warehouse",
                "l": "Warehouses"
            },
            {
                "v": "warehouse_locations",
                "l": "Warehouse_Locations"
            },
            {
                "v": "backup",
                "l": "Backup"
            },
            {
                "v": "system_health_view",
                "l": "System_Health"
            },
            {
                "v": "audit_log",
                "l": "Audit_Log"
            },
            {
                "v": "ai_assistant",
                "l": "AI_Assistant",
                "f": "AI Assistant (chat & dashboard insights)"
            },
            {
                "v": "ai_settings",
                "l": "AI_Settings",
                "f": "AI Assistant settings (API key, model)"
            },
            {
                "v": "appearance_settings",
                "l": "appearance_settings",
                "f": "Dynamic Appearance"
            },
            {
                "v": "translations_settings",
                "l": "Translations",
                "f": "Translations"
            },
            {
                "v": "module_settings",
                "l": "module_settings",
                "f": "Module Settings"
            },
            {
                "v": "fbr_settings",
                "l": "fbr_settings",
                "f": "FBR Digital Invoicing"
            },
            {
                "v": "agt_settings",
                "l": "agt_settings",
                "f": "AGT E-Invoicing (Angola)"
            },
            {
                "v": "dgi_settings",
                "l": "dgi_settings",
                "f": "DGI Facture Normalisée (DR Congo)"
            }
        ]
    },
    {
        "label": "Manufacturing (MRP)",
        "raw": true,
        "items": [
            {
                "v": "mrp_boms",
                "l": "Perm_mrp_boms",
                "f": "Bills of materials and work centres"
            },
            {
                "v": "mrp_production",
                "l": "Perm_mrp_production",
                "f": "Production orders and shop floor"
            },
            {
                "v": "mrp_quality",
                "l": "Perm_mrp_quality",
                "f": "Quality control"
            },
            {
                "v": "mrp_planning",
                "l": "Planning",
                "f": "MRP planning"
            },
            {
                "v": "mrp_reports",
                "l": "Reports",
                "f": "Manufacturing reports"
            }
        ]
    },
    {
        "label": "Shopify",
        "raw": true,
        "items": [
            {
                "v": "shopify_stores",
                "l": "Stores",
                "f": "Shopify stores"
            },
            {
                "v": "shopify_sync",
                "l": "Perm_shopify_sync",
                "f": "Run syncs"
            },
            {
                "v": "shopify_logs",
                "l": "Logs",
                "f": "Shopify logs"
            },
            {
                "v": "shopify_settings",
                "l": "Settings",
                "f": "Shopify settings"
            }
        ]
    },
    {
        "label": "WhatsApp",
        "raw": true,
        "items": [
            {
                "v": "whatsapp_settings",
                "l": "Settings",
                "f": "WhatsApp settings"
            },
            {
                "v": "whatsapp_templates",
                "l": "Templates",
                "f": "WhatsApp templates"
            },
            {
                "v": "whatsapp_logs",
                "l": "Perm_whatsapp_logs",
                "f": "WhatsApp message logs"
            }
        ]
    },
    {
        "label": "Support Tickets",
        "raw": true,
        "items": [
            {
                "v": "support",
                "l": "Perm_support",
                "f": "Manage support tickets"
            }
        ]
    },
    {
        "label": "Online Store",
        "raw": true,
        "items": [
            {
                "v": "Store_settings_view",
                "l": "Page_Store_Settings",
                "f": "Store Settings"
            },
            {
                "v": "ewallet",
                "l": "E_Wallet",
                "f": "E-Wallet"
            },
            {
                "v": "Orders_view",
                "l": "Orders",
                "f": "Orders"
            },
            {
                "v": "Collections_view",
                "l": "Collections",
                "f": "Collections"
            },
            {
                "v": "Banners_view",
                "l": "Banners",
                "f": "Banners"
            },
            {
                "v": "Subscribers_view",
                "l": "Subscribers",
                "f": "Subscribers"
            },
            {
                "v": "Messages_view",
                "l": "Messages",
                "f": "Messages"
            },
            {
                "v": "store_visit",
                "l": "Visit_Online_Store",
                "f": "Visit Online Store"
            },
            {
                "v": "store_invite_codes",
                "l": "Invite_Codes",
                "f": "Invite Codes"
            },
            {
                "v": "store_pending_customers",
                "l": "Pending_Customers",
                "f": "Pending Customers"
            },
            {
                "v": "store_customers_without_login",
                "l": "Customers_without_Login",
                "f": "Customers without Login"
            },
            {
                "v": "store_customers_with_login",
                "l": "Customers_with_Login",
                "f": "Customers with Login"
            },
            {
                "v": "store_shipping_methods",
                "l": "Shipping_Methods",
                "f": "Shipping Methods"
            },
            {
                "v": "store_tax_rates",
                "l": "Tax_Rates",
                "f": "Tax Rates"
            },
            {
                "v": "store_coupons",
                "l": "Coupons",
                "f": "Coupons"
            },
            {
                "v": "store_flash_sales",
                "l": "Flash_Sales",
                "f": "Flash Sales"
            },
            {
                "v": "store_reviews",
                "l": "Product_Reviews",
                "f": "Product Reviews"
            },
            {
                "v": "store_popups",
                "l": "Popup_Messages",
                "f": "Popup Messages"
            },
            {
                "v": "store_quote_requests",
                "l": "Quote_Requests",
                "f": "Quote Requests"
            },
            {
                "v": "store_returns",
                "l": "Returns_Requests",
                "f": "Returns Requests"
            },
            {
                "v": "store_pages",
                "l": "Pages",
                "f": "Pages"
            },
            {
                "v": "store_menus",
                "l": "Menus",
                "f": "Menus"
            },
            {
                "v": "store_vehicle_leads",
                "l": "Vehicle_Leads",
                "f": "Vehicle Leads"
            }
        ]
    },
    {
        "label": "Billing",
        "items": [
            {
                "v": "billing_view",
                "l": "Billing",
                "f": "Billing"
            }
        ]
    },
    {
        "label": "Pt_Protocol",
        "items": [
            {
                "v": "protocol_view",
                "l": "Pt_Perm_View",
                "f": "View requests (own, desk, assigned)"
            },
            {
                "v": "protocol_add",
                "l": "Pt_Perm_Add",
                "f": "Submit requests"
            },
            {
                "v": "protocol_act",
                "l": "Pt_Perm_Act",
                "f": "Act on requests at my desks"
            },
            {
                "v": "protocol_manage",
                "l": "Pt_Perm_Manage",
                "f": "Manage desks & all requests"
            }
        ]
    },
    {
        "label": "Cr_Couriers",
        "items": [
            {
                "v": "courier_settings",
                "l": "Cr_Perm_Settings",
                "f": "Manage courier accounts & settings"
            },
            {
                "v": "courier_book",
                "l": "Cr_Perm_Book",
                "f": "Book, cancel & print courier shipments"
            },
            {
                "v": "courier_shipments",
                "l": "Cr_Perm_Shipments",
                "f": "View courier shipments, COD & returns"
            },
            {
                "v": "courier_reports",
                "l": "Cr_Perm_Reports",
                "f": "Courier report: delivery & return rates, COD"
            }
        ]
    },
    {
        "label": "Perm_Group_Restaurant_Management",
        "items": [
            {
                "v": "rst_dashboard",
                "l": "Rst_Perm_Dashboard",
                "f": "Restaurant dashboard"
            },
            {
                "v": "rst_floor",
                "l": "Rst_Perm_Floor",
                "f": "Floor plan: see tables and open orders"
            },
            {
                "v": "rst_orders_take",
                "l": "Rst_Perm_Orders_Take",
                "f": "Take orders and send them to the kitchen"
            },
            {
                "v": "rst_orders_void",
                "l": "Rst_Perm_Orders_Void",
                "f": "Void items and cancel orders"
            },
            {
                "v": "rst_discounts",
                "l": "Rst_Perm_Discounts",
                "f": "Give discounts on bills"
            },
            {
                "v": "rst_billing",
                "l": "Rst_Perm_Billing",
                "f": "Print bills and take payments"
            },
            {
                "v": "rst_menu",
                "l": "Rst_Perm_Menu",
                "f": "Menu: add-ons, dish details, sold-out"
            },
            {
                "v": "rst_reservations",
                "l": "Rst_Perm_Reservations",
                "f": "Table reservations"
            },
            {
                "v": "rst_reports",
                "l": "Rst_Perm_Reports",
                "f": "Restaurant reports"
            },
            {
                "v": "rst_setup",
                "l": "Rst_Perm_Setup",
                "f": "Floor setup: areas, tables, stations"
            },
            {
                "v": "rst_settings",
                "l": "Rst_Perm_Settings",
                "f": "Restaurant settings"
            },
            {
                "v": "rst_kitchen",
                "l": "Rst_Perm_Kitchen",
                "f": "Kitchen display: prepare, bump and recall tickets"
            }
        ]
    },
    {
        "label": "Perm_Group_Rental_Management",
        "items": [
            {
                "v": "rnt_dashboard",
                "l": "Dashboard",
                "f": "Dashboard"
            },
            {
                "v": "rnt_items_view",
                "l": "View"
            },
            {
                "v": "rnt_items_add",
                "l": "Add"
            },
            {
                "v": "rnt_items_edit",
                "l": "Edit"
            },
            {
                "v": "rnt_items_delete",
                "l": "Del"
            },
            {
                "v": "rnt_customers_view",
                "l": "Rental_Customers_View",
                "f": "Customers: view"
            },
            {
                "v": "rnt_customers_add",
                "l": "Rental_Customers_Add",
                "f": "Customers: add"
            },
            {
                "v": "rnt_customers_edit",
                "l": "Rental_Customers_Edit",
                "f": "Customers: edit"
            },
            {
                "v": "rnt_customers_delete",
                "l": "Rental_Customers_Delete",
                "f": "Customers: delete"
            },
            {
                "v": "rnt_contracts_view",
                "l": "Rental_Contracts_View",
                "f": "Contracts: view"
            },
            {
                "v": "rnt_contracts_add",
                "l": "Rental_Contracts_Add",
                "f": "Contracts: add"
            },
            {
                "v": "rnt_contracts_edit",
                "l": "Rental_Contracts_Edit",
                "f": "Contracts: edit & manage"
            },
            {
                "v": "rnt_contracts_delete",
                "l": "Rental_Contracts_Delete",
                "f": "Contracts: delete"
            },
            {
                "v": "rnt_availability",
                "l": "Rental_Availability_Perm",
                "f": "Availability timeline & calendar"
            },
            {
                "v": "rnt_reservations_view",
                "l": "Rental_Reservations_View",
                "f": "Reservations: view"
            },
            {
                "v": "rnt_reservations_add",
                "l": "Rental_Reservations_Add",
                "f": "Reservations: add"
            },
            {
                "v": "rnt_reservations_edit",
                "l": "Rental_Reservations_Edit",
                "f": "Reservations: edit & manage"
            },
            {
                "v": "rnt_reservations_delete",
                "l": "Rental_Reservations_Delete",
                "f": "Reservations: delete"
            },
            {
                "v": "rnt_billing_view",
                "l": "Rental_Billing_View",
                "f": "Invoices & payments: view"
            },
            {
                "v": "rnt_payments_add",
                "l": "Rental_Payments_Add",
                "f": "Record payments"
            },
            {
                "v": "rnt_billing_manage",
                "l": "Rental_Billing_Manage",
                "f": "Charges, voids & late-fee waivers"
            },
            {
                "v": "rnt_payments_delete",
                "l": "Rental_Payments_Delete",
                "f": "Delete payments"
            },
            {
                "v": "rnt_handover",
                "l": "Rental_Handover_Perm",
                "f": "Hand over & return items"
            },
            {
                "v": "rnt_deposits",
                "l": "Rental_Deposits_Perm",
                "f": "Manage deposits"
            },
            {
                "v": "rnt_maintenance",
                "l": "Rental_Maintenance_Perm",
                "f": "Maintenance jobs & schedules"
            },
            {
                "v": "rnt_expenses",
                "l": "Rental_Expenses_Perm",
                "f": "Rental expenses"
            },
            {
                "v": "rnt_reports",
                "l": "Rental_Reports_Perm",
                "f": "Reports & statements"
            },
            {
                "v": "rnt_reminders",
                "l": "Rental_Reminders_Perm",
                "f": "Send reminders"
            },
            {
                "v": "rnt_categories",
                "l": "Rental_Categories",
                "f": "Rental Categories"
            },
            {
                "v": "rnt_settings",
                "l": "Rental_Settings",
                "f": "Rental Settings"
            }
        ]
    },
    {
        "label": "CRM",
        "raw": true,
        "items": [
            {
                "v": "crm_dashboard",
                "l": "Dashboard",
                "f": "Dashboard"
            },
            {
                "v": "crm_leads_view",
                "l": "Crm_Perm_Leads_View",
                "f": "View leads"
            },
            {
                "v": "crm_leads_add",
                "l": "Crm_Perm_Leads_Add",
                "f": "Add leads"
            },
            {
                "v": "crm_leads_edit",
                "l": "Crm_Perm_Leads_Edit",
                "f": "Edit leads"
            },
            {
                "v": "crm_leads_delete",
                "l": "Crm_Perm_Leads_Delete",
                "f": "Delete leads"
            },
            {
                "v": "crm_deals_view",
                "l": "Crm_Perm_Deals_View",
                "f": "View deals"
            },
            {
                "v": "crm_deals_add",
                "l": "Crm_Perm_Deals_Add",
                "f": "Add deals"
            },
            {
                "v": "crm_deals_edit",
                "l": "Crm_Perm_Deals_Edit",
                "f": "Edit & move deals"
            },
            {
                "v": "crm_deals_delete",
                "l": "Crm_Perm_Deals_Delete",
                "f": "Delete deals"
            },
            {
                "v": "crm_activities_view",
                "l": "Crm_Perm_Activities_View",
                "f": "View activities & tasks"
            },
            {
                "v": "crm_activities_add",
                "l": "Crm_Perm_Activities_Add",
                "f": "Log & plan activities"
            },
            {
                "v": "crm_activities_edit",
                "l": "Crm_Perm_Activities_Edit",
                "f": "Edit & complete activities"
            },
            {
                "v": "crm_activities_delete",
                "l": "Crm_Perm_Activities_Delete",
                "f": "Delete activities"
            },
            {
                "v": "crm_customers_view",
                "l": "Crm_Perm_Customers_View",
                "f": "Customers & Customer 360"
            },
            {
                "v": "crm_contacts_manage",
                "l": "Crm_Perm_Contacts_Manage",
                "f": "Manage contact people"
            },
            {
                "v": "crm_messages",
                "l": "Crm_Perm_Messages",
                "f": "Conversations: email, SMS & WhatsApp"
            },
            {
                "v": "crm_web_forms",
                "l": "Crm_Perm_Web_Forms",
                "f": "Web forms (lead capture)"
            },
            {
                "v": "crm_reports",
                "l": "Crm_Perm_Reports",
                "f": "Reports"
            },
            {
                "v": "crm_targets",
                "l": "Crm_Perm_Targets",
                "f": "Set sales targets"
            },
            {
                "v": "crm_view_all",
                "l": "Crm_Perm_View_All",
                "f": "See every rep's records"
            },
            {
                "v": "crm_import_export",
                "l": "Crm_Perm_Import_Export",
                "f": "Import & export"
            },
            {
                "v": "crm_settings",
                "l": "Crm_Settings",
                "f": "CRM Settings"
            }
        ]
    }
];
