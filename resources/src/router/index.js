import { ref, watchEffect } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { i18n } from '../i18n';
import { resolveMigratedPath } from '../config/migrated';
import { useAuthStore } from '../stores/auth';
import { moduleKeyForPath } from '../config/modules';
import { start as progressStart, done as progressDone } from '../lib/progress';
// The shell is needed on every page, so it stays in the entry bundle.
import AdminLayout from '../layouts/AdminLayout.vue';

/**
 * Every page is code-split: with ~420 legacy pages to migrate, one bundle is
 * not an option. The wrapper makes a failed chunk fetch loud instead of
 * surfacing later as an unexplained render error.
 *
 * Adding a page (see resources/MIGRATION.md):
 *   1. lazy route here with meta.title + meta.permission;
 *   2. its legacy path in config/migratedRoutes.json — the sidebar rewires
 *      itself AND the legacy router starts redirecting that path to /next.
 */
const lazy = (name, loader) => () =>
    loader().catch(err => {
        console.error(`[stocky-next] chunk load FAILED for "${name}" — cause of any render error that follows:`, err);
        throw err;
    });

const Ping = lazy('Ping', () => import('../pages/Ping.vue'));
const Dashboard = lazy('Dashboard', () => import('../pages/Dashboard.vue'));
const NotFound = lazy('NotFound', () => import('../pages/NotFound.vue'));

// --- Phase 2: simple CRUD ---
const Brands = lazy('Brands', () => import('../pages/Brands.vue'));
const SizeGuides = lazy('SizeGuides', () => import('../pages/products/SizeGuides.vue'));
const Categories = lazy('Categories', () => import('../pages/Categories.vue'));
const SubCategories = lazy('SubCategories', () => import('../pages/SubCategories.vue'));
const Units = lazy('Units', () => import('../pages/Units.vue'));
const Currencies = lazy('Currencies', () => import('../pages/Currencies.vue'));
const Warehouses = lazy('Warehouses', () => import('../pages/Warehouses.vue'));
const PaymentMethods = lazy('PaymentMethods', () => import('../pages/settings/PaymentMethods.vue'));
const ExpenseCategories = lazy('ExpenseCategories', () => import('../pages/expenses/ExpenseCategories.vue'));
const DepositCategories = lazy('DepositCategories', () => import('../pages/deposits/DepositCategories.vue'));
const AssetCategories = lazy('AssetCategories', () => import('../pages/assets/AssetCategories.vue'));
const RecruitCategories = lazy('RecruitCategories', () => import('../pages/recruit/RecruitCategories.vue'));
const PropertyCategories = lazy('PropertyCategories', () => import('../pages/realestate/PropertyCategories.vue'));
const ServiceTechnicians = lazy('ServiceTechnicians', () => import('../pages/service/ServiceTechnicians.vue'));
const ServiceChecklistCategories = lazy('ServiceChecklistCategories', () => import('../pages/service/ServiceChecklistCategories.vue'));
const ServiceChecklists = lazy('ServiceChecklists', () => import('../pages/service/ServiceChecklists.vue'));
const Companies = lazy('Companies', () => import('../pages/hrm/Companies.vue'));
const Departments = lazy('Departments', () => import('../pages/hrm/Departments.vue'));
const Designations = lazy('Designations', () => import('../pages/hrm/Designations.vue'));
const OfficeShifts = lazy('OfficeShifts', () => import('../pages/hrm/OfficeShifts.vue'));
const Holidays = lazy('Holidays', () => import('../pages/hrm/Holidays.vue'));
const LeaveTypes = lazy('LeaveTypes', () => import('../pages/hrm/LeaveTypes.vue'));
const Accounts = lazy('Accounts', () => import('../pages/accounting/Accounts.vue'));
const AccountStatement = lazy('AccountStatement', () => import('../pages/accounting/AccountStatement.vue'));
const TransferMoney = lazy('TransferMoney', () => import('../pages/accounting/TransferMoney.vue'));

// --- Phase 3: reports (routes/permissions as in the stocky reference) ---
const DeadStockReport = lazy('DeadStockReport', () => import('../pages/reports/DeadStockReport.vue'));
const ZeroSalesReport = lazy('ZeroSalesReport', () => import('../pages/reports/ZeroSalesReport.vue'));
const NegativeStockReport = lazy('NegativeStockReport', () => import('../pages/reports/NegativeStockReport.vue'));
const ExpiryReport = lazy('ExpiryReport', () => import('../pages/reports/ExpiryReport.vue'));
const DiscountSummaryReport = lazy('DiscountSummaryReport', () => import('../pages/reports/DiscountSummaryReport.vue'));
const DueCollectionReport = lazy('DueCollectionReport', () => import('../pages/reports/DueCollectionReport.vue'));
const PosSalesReport = lazy('PosSalesReport', () => import('../pages/reports/PosSalesReport.vue'));
const TaxSummaryReport = lazy('TaxSummaryReport', () => import('../pages/reports/TaxSummaryReport.vue'));
const LoyaltyPointsReport = lazy('LoyaltyPointsReport', () => import('../pages/reports/LoyaltyPointsReport.vue'));
const InactiveCustomersReport = lazy('InactiveCustomersReport', () => import('../pages/reports/InactiveCustomersReport.vue'));
const DraftInvoicesReport = lazy('DraftInvoicesReport', () => import('../pages/reports/DraftInvoicesReport.vue'));
const TopSuppliersReport = lazy('TopSuppliersReport', () => import('../pages/reports/TopSuppliersReport.vue'));
const WarrantyReport = lazy('WarrantyReport', () => import('../pages/reports/WarrantyReport.vue'));
const StockAdjustmentReport = lazy('StockAdjustmentReport', () => import('../pages/reports/StockAdjustmentReport.vue'));
const SerialSoldReport = lazy('SerialSoldReport', () => import('../pages/reports/SerialSoldReport.vue'));
const SerialAvailableReport = lazy('SerialAvailableReport', () => import('../pages/reports/SerialAvailableReport.vue'));
const SerialMovementReport = lazy('SerialMovementReport', () => import('../pages/reports/SerialMovementReport.vue'));
const SerialInventoryReport = lazy('SerialInventoryReport', () => import('../pages/reports/SerialInventoryReport.vue'));
const BatchRegisterReport = lazy('BatchRegisterReport', () => import('../pages/reports/BatchRegisterReport.vue'));
const PaymentsReport = lazy('PaymentsReport', () => import('../pages/reports/PaymentsReport.vue'));
const CustomersReport = lazy('CustomersReport', () => import('../pages/reports/CustomersReport.vue'));
const SuppliersReport = lazy('SuppliersReport', () => import('../pages/reports/SuppliersReport.vue'));
const CashRegisterReport = lazy('CashRegisterReport', () => import('../pages/reports/CashRegisterReport.vue'));
const AttendanceReport = lazy('AttendanceReport', () => import('../pages/reports/AttendanceReport.vue'));
const ServiceJobsReport = lazy('ServiceJobsReport', () => import('../pages/reports/ServiceJobsReport.vue'));
const ChecklistCompletionReport = lazy('ChecklistCompletionReport', () => import('../pages/reports/ChecklistCompletionReport.vue'));
const MaintenanceHistoryReport = lazy('MaintenanceHistoryReport', () => import('../pages/reports/MaintenanceHistoryReport.vue'));
const UsersReport = lazy('UsersReport', () => import('../pages/reports/UsersReport.vue'));
const LoginActivityReport = lazy('LoginActivityReport', () => import('../pages/reports/LoginActivityReport.vue'));
const ErrorLogsReport = lazy('ErrorLogsReport', () => import('../pages/reports/ErrorLogsReport.vue'));
const TopSellingProductsReport = lazy('TopSellingProductsReport', () => import('../pages/reports/TopSellingProductsReport.vue'));
const TopCustomersReport = lazy('TopCustomersReport', () => import('../pages/reports/TopCustomersReport.vue'));
const ExpensesReport = lazy('ExpensesReport', () => import('../pages/reports/ExpensesReport.vue'));
const DepositsReport = lazy('DepositsReport', () => import('../pages/reports/DepositsReport.vue'));
const SalesReport = lazy('SalesReport', () => import('../pages/reports/SalesReport.vue'));
const PurchasesReport = lazy('PurchasesReport', () => import('../pages/reports/PurchasesReport.vue'));
const QuantityAlertsReport = lazy('QuantityAlertsReport', () => import('../pages/reports/QuantityAlertsReport.vue'));
const StockReport = lazy('StockReport', () => import('../pages/reports/StockReport.vue'));
const ProductReport = lazy('ProductReport', () => import('../pages/reports/ProductReport.vue'));
const CustomerDetailReport = lazy('CustomerDetailReport', () => import('../pages/reports/CustomerDetailReport.vue'));
const SupplierDetailReport = lazy('SupplierDetailReport', () => import('../pages/reports/SupplierDetailReport.vue'));
const ProductDetailReport = lazy('ProductDetailReport', () => import('../pages/reports/ProductDetailReport.vue'));
const StockDetailReport = lazy('StockDetailReport', () => import('../pages/reports/StockDetailReport.vue'));
const UserDetailReport = lazy('UserDetailReport', () => import('../pages/reports/UserDetailReport.vue'));
const BatchHistoryReport = lazy('BatchHistoryReport', () => import('../pages/reports/BatchHistoryReport.vue'));
const ProductSalesReport = lazy('ProductSalesReport', () => import('../pages/reports/ProductSalesReport.vue'));
const ProductsSoldSummaryReport = lazy('ProductsSoldSummaryReport', () => import('../pages/reports/ProductsSoldSummaryReport.vue'));
const ProductPurchasesReport = lazy('ProductPurchasesReport', () => import('../pages/reports/ProductPurchasesReport.vue'));
const SellerReport = lazy('SellerReport', () => import('../pages/reports/SellerReport.vue'));
const TransactionsReport = lazy('TransactionsReport', () => import('../pages/reports/TransactionsReport.vue'));
const InternalLocationReport = lazy('InternalLocationReport', () => import('../pages/reports/InternalLocationReport.vue'));
const SalesByGroupReport = lazy('SalesByGroupReport', () => import('../pages/reports/SalesByGroupReport.vue'));
const ProfitAndLossReport = lazy('ProfitAndLossReport', () => import('../pages/reports/ProfitAndLossReport.vue'));
const ProfitReport = lazy('ProfitReport', () => import('../pages/reports/profit/ProfitReport.vue'));
const AnalyticsReport = lazy('AnalyticsReport', () => import('../pages/reports/AnalyticsReport.vue'));
const AiReports = lazy('AiReports', () => import('../pages/reports/AiReports.vue'));
const Sales3DDashboard = lazy('Sales3DDashboard', () => import('../pages/dashboard/Sales3DDashboard.vue'));
const CashFlowReport = lazy('CashFlowReport', () => import('../pages/reports/CashFlowReport.vue'));
const StockTransferReport = lazy('StockTransferReport', () => import('../pages/reports/StockTransferReport.vue'));
const ReturnRatioReport = lazy('ReturnRatioReport', () => import('../pages/reports/ReturnRatioReport.vue'));
const StockAgingReport = lazy('StockAgingReport', () => import('../pages/reports/StockAgingReport.vue'));
const InventoryValuationSummaryReport = lazy('InventoryValuationSummaryReport', () => import('../pages/reports/InventoryValuationSummaryReport.vue'));
const StockInventoryValuationReport = lazy('StockInventoryValuationReport', () => import('../pages/reports/StockInventoryValuationReport.vue'));
const WarehouseReport = lazy('WarehouseReport', () => import('../pages/reports/WarehouseReport.vue'));

// --- ported: customers, customers-with-login, customers-without-login, suppliers, users, permissions, hrm/, hospital/, school/, mrp, marketing/, service/, projects, tasks, recruit/, fleet/, accounting-v2/, commissions/, meeting, meetings, assets, bookings, contracts, realestate/, kb, kitchen-display, store/, expenses, deposits, damages, promotions, subscriptions, customer-display/setup, real-time-sales-counter ---
const Customers = lazy('Customers', () => import('../pages/people/Customers.vue'));
const ImportCustomers = lazy('ImportCustomers', () => import('../pages/people/ImportCustomers.vue'));
const CustomerForm = lazy('CustomerForm', () => import('../pages/people/CustomerForm.vue'));
const CustomerDetails = lazy('CustomerDetails', () => import('../pages/people/CustomerDetails.vue'));
const CustomerLedger = lazy('CustomerLedger', () => import('../pages/people/CustomerLedger.vue'));
const CustomerWallets = lazy('CustomerWallets', () => import('../pages/people/CustomerWallets.vue'));
const CustomerWalletLedger = lazy('CustomerWalletLedger', () => import('../pages/people/CustomerWalletLedger.vue'));
const GiftCards = lazy('GiftCards', () => import('../pages/people/GiftCards.vue'));
const Suppliers = lazy('Suppliers', () => import('../pages/people/Suppliers.vue'));
const ImportSuppliers = lazy('ImportSuppliers', () => import('../pages/people/ImportSuppliers.vue'));
const SupplierForm = lazy('SupplierForm', () => import('../pages/people/SupplierForm.vue'));
const SupplierWallets = lazy('SupplierWallets', () => import('../pages/people/SupplierWallets.vue'));
const SupplierLedger = lazy('SupplierLedger', () => import('../pages/people/SupplierLedger.vue'));
const Users = lazy('Users', () => import('../pages/people/Users.vue'));
const UserForm = lazy('UserForm', () => import('../pages/people/UserForm.vue'));
const MrpDashboard = lazy('MrpDashboard', () => import('../pages/manufacturing/MrpDashboard.vue'));
const MrpProductionOrders = lazy('MrpProductionOrders', () => import('../pages/manufacturing/ProductionOrders.vue'));
const MrpProductionOrderForm = lazy('MrpProductionOrderForm', () => import('../pages/manufacturing/ProductionOrderForm.vue'));
const MrpProductionOrderDetails = lazy('MrpProductionOrderDetails', () => import('../pages/manufacturing/ProductionOrderDetails.vue'));
const MrpWorkOrders = lazy('MrpWorkOrders', () => import('../pages/manufacturing/WorkOrders.vue'));
const MrpBoms = lazy('MrpBoms', () => import('../pages/manufacturing/Boms.vue'));
const MrpBomForm = lazy('MrpBomForm', () => import('../pages/manufacturing/BomForm.vue'));
const MrpWorkCenters = lazy('MrpWorkCenters', () => import('../pages/manufacturing/WorkCenters.vue'));
const MrpQualityChecks = lazy('MrpQualityChecks', () => import('../pages/manufacturing/QualityChecks.vue'));
const MrpPlanning = lazy('MrpPlanning', () => import('../pages/manufacturing/MrpPlanning.vue'));
const MrpReports = lazy('MrpReports', () => import('../pages/manufacturing/MrpReports.vue'));
const Assets = lazy('Assets', () => import('../pages/assets/Assets.vue'));
const AssetForm = lazy('AssetForm', () => import('../pages/assets/AssetForm.vue'));
const DueAssets = lazy('DueAssets', () => import('../pages/assets/DueAssets.vue'));
const AssetsDashboard = lazy('AssetsDashboard', () => import('../pages/assets/AssetsDashboard.vue'));
const AssetDetails = lazy('AssetDetails', () => import('../pages/assets/AssetDetails.vue'));
const AssetAssignments = lazy('AssetAssignments', () => import('../pages/assets/AssetAssignments.vue'));
const AssetMaintenance = lazy('AssetMaintenance', () => import('../pages/assets/AssetMaintenance.vue'));
const AssetTransfers = lazy('AssetTransfers', () => import('../pages/assets/AssetTransfers.vue'));
const AssetDepreciation = lazy('AssetDepreciation', () => import('../pages/assets/AssetDepreciation.vue'));
const AssetReports = lazy('AssetReports', () => import('../pages/assets/AssetReports.vue'));
const Subscriptions = lazy('Subscriptions', () => import('../pages/subscriptions/Subscriptions.vue'));
const SubscriptionForm = lazy('SubscriptionForm', () => import('../pages/subscriptions/SubscriptionForm.vue'));
const SubscriptionDetails = lazy('SubscriptionDetails', () => import('../pages/subscriptions/SubscriptionDetails.vue'));
const BookingCalendar = lazy('BookingCalendar', () => import('../pages/bookings/BookingCalendar.vue'));
const ServiceJobsList = lazy('ServiceJobsList', () => import('../pages/service/ServiceJobsList.vue'));
const ServiceJobForm = lazy('ServiceJobForm', () => import('../pages/service/ServiceJobForm.vue'));
const ServiceJobDetails = lazy('ServiceJobDetails', () => import('../pages/service/ServiceJobDetails.vue'));
const CustomerMaintenanceHistory = lazy('CustomerMaintenanceHistory', () => import('../pages/service/CustomerMaintenanceHistory.vue'));
const GroupPermissions = lazy('GroupPermissions', () => import('../pages/permissions/GroupPermissions.vue'));
const PermissionForm = lazy('PermissionForm', () => import('../pages/permissions/PermissionForm.vue'));
const StoreSettings = lazy('StoreSettings', () => import('../pages/store/StoreSettings.vue'));
const StoreOrders = lazy('StoreOrders', () => import('../pages/store/Orders.vue'));
const StoreOrderShow = lazy('StoreOrderShow', () => import('../pages/store/OrderShow.vue'));
const StoreCollections = lazy('StoreCollections', () => import('../pages/store/Collections.vue'));
const StoreCollectionForm = lazy('StoreCollectionForm', () => import('../pages/store/CollectionForm.vue'));
const StoreMessages = lazy('StoreMessages', () => import('../pages/store/Messages.vue'));
const StoreInviteCodes = lazy('StoreInviteCodes', () => import('../pages/store/InviteCodes.vue'));
const StoreSubscribers = lazy('StoreSubscribers', () => import('../pages/store/Subscribers.vue'));
const StorePendingCustomers = lazy('StorePendingCustomers', () => import('../pages/store/PendingCustomers.vue'));
const StoreBanners = lazy('StoreBanners', () => import('../pages/store/Banners.vue'));
const StoreBannerForm = lazy('StoreBannerForm', () => import('../pages/store/BannerForm.vue'));
const StorePaymentGatewayPage = lazy('StorePaymentGatewayPage', () => import('../pages/store/StorePaymentGateway.vue'));
const StoreFlashSales = lazy('StoreFlashSales', () => import('../pages/store/FlashSales.vue'));
const StoreQuoteRequests = lazy('StoreQuoteRequests', () => import('../pages/store/QuoteRequests.vue'));
const StoreVehicleLeads = lazy('StoreVehicleLeads', () => import('../pages/store/VehicleLeads.vue'));
const StoreReviews = lazy('StoreReviews', () => import('../pages/store/Reviews.vue'));
const StoreReturns = lazy('StoreReturns', () => import('../pages/store/Returns.vue'));
const StoreMenus = lazy('StoreMenus', () => import('../pages/store/Menus.vue'));
const StoreCoupons = lazy('StoreCoupons', () => import('../pages/store/Coupons.vue'));
const StoreTaxRates = lazy('StoreTaxRates', () => import('../pages/store/TaxRates.vue'));
const StorePages = lazy('StorePages', () => import('../pages/store/Pages.vue'));
const StorePopups = lazy('StorePopups', () => import('../pages/store/Popups.vue'));
const StoreShippingMethods = lazy('StoreShippingMethods', () => import('../pages/store/ShippingMethods.vue'));
const Properties = lazy('Properties', () => import('../pages/realestate/Properties.vue'));
const PropertyForm = lazy('PropertyForm', () => import('../pages/realestate/PropertyForm.vue'));
const PropertyInquiries = lazy('PropertyInquiries', () => import('../pages/realestate/PropertyInquiries.vue'));
const AccountingDashboard = lazy('AccountingDashboard', () => import('../pages/accounting/v2/AccountingDashboard.vue'));
const ChartOfAccounts = lazy('ChartOfAccounts', () => import('../pages/accounting/v2/ChartOfAccounts.vue'));
const JournalEntries = lazy('JournalEntries', () => import('../pages/accounting/v2/JournalEntries.vue'));
const TrialBalance = lazy('TrialBalance', () => import('../pages/accounting/v2/TrialBalance.vue'));
const ProfitAndLossV2 = lazy('ProfitAndLossV2', () => import('../pages/accounting/v2/ProfitAndLoss.vue'));
const BalanceSheet = lazy('BalanceSheet', () => import('../pages/accounting/v2/BalanceSheet.vue'));
const TaxReportV2 = lazy('TaxReportV2', () => import('../pages/accounting/v2/TaxReport.vue'));
const ReconciliationV2 = lazy('ReconciliationV2', () => import('../pages/accounting/v2/Reconciliation.vue'));
const MeetingDashboard = lazy('MeetingDashboard', () => import('../pages/meeting/MeetingDashboard.vue'));
const MeetingDetails = lazy('MeetingDetails', () => import('../pages/meeting/MeetingDetails.vue'));
const MeetingCalendar = lazy('MeetingCalendar', () => import('../pages/meeting/MeetingCalendar.vue'));
const MeetingReports = lazy('MeetingReports', () => import('../pages/meeting/MeetingReports.vue'));
const RecruitDashboard = lazy('RecruitDashboard', () => import('../pages/recruit/RecruitDashboard.vue'));
const RecruitJobs = lazy('RecruitJobs', () => import('../pages/recruit/RecruitJobs.vue'));
const RecruitCandidates = lazy('RecruitCandidates', () => import('../pages/recruit/RecruitCandidates.vue'));
const RecruitApplications = lazy('RecruitApplications', () => import('../pages/recruit/RecruitApplications.vue'));
const RecruitInterviews = lazy('RecruitInterviews', () => import('../pages/recruit/RecruitInterviews.vue'));
const RecruitReports = lazy('RecruitReports', () => import('../pages/recruit/RecruitReports.vue'));
const CommissionPrograms = lazy('CommissionPrograms', () => import('../pages/commissions/CommissionPrograms.vue'));
const SalesAgents = lazy('SalesAgents', () => import('../pages/commissions/SalesAgents.vue'));
const CommissionRules = lazy('CommissionRules', () => import('../pages/commissions/CommissionRules.vue'));
const CommissionReceipts = lazy('CommissionReceipts', () => import('../pages/commissions/CommissionReceipts.vue'));
const CommissionReport = lazy('CommissionReport', () => import('../pages/commissions/CommissionReport.vue'));
const CommissionGuide = lazy('CommissionGuide', () => import('../pages/commissions/CommissionGuide.vue'));
const Bookings = lazy('Bookings', () => import('../pages/bookings/Bookings.vue'));
const BookingForm = lazy('BookingForm', () => import('../pages/bookings/BookingForm.vue'));
const KitchenDisplay = lazy('KitchenDisplay', () => import('../pages/kitchen/KitchenDisplay.vue'));
const Trays = lazy('Trays', () => import('../pages/bookings/Trays.vue'));
const BookingSettings = lazy('BookingSettings', () => import('../pages/bookings/BookingSettings.vue'));
const BookingServices = lazy('BookingServices', () => import('../pages/bookings/BookingServices.vue'));
const BookingStaff = lazy('BookingStaff', () => import('../pages/bookings/BookingStaff.vue'));
const BookingRequests = lazy('BookingRequests', () => import('../pages/bookings/BookingRequests.vue'));
const BookingDay = lazy('BookingDay', () => import('../pages/bookings/BookingDay.vue'));
const BookingDashboard = lazy('BookingDashboard', () => import('../pages/bookings/BookingDashboard.vue'));
const BookingReports = lazy('BookingReports', () => import('../pages/bookings/BookingReports.vue'));
const BookingGuide = lazy('BookingGuide', () => import('../pages/bookings/BookingGuide.vue'));
const Meetings = lazy('Meetings', () => import('../pages/meeting/Meetings.vue'));
// Protocol & Requests (internal messaging / administrative protocol / ticketing)
const ProtocolRequests = lazy('ProtocolRequests', () => import('../pages/protocol/ProtocolRequests.vue'));
const ProtocolRequestDetails = lazy('ProtocolRequestDetails', () => import('../pages/protocol/ProtocolRequestDetails.vue'));
const ProtocolDesks = lazy('ProtocolDesks', () => import('../pages/protocol/ProtocolDesks.vue'));
// Client Portal section (ported from stocky)
const PortalDashboard = lazy('PortalDashboard', () => import('../pages/client_portal/PortalDashboard.vue'));
const PortalAccounts = lazy('PortalAccounts', () => import('../pages/client_portal/PortalAccounts.vue'));
const PortalDeletionLog = lazy('PortalDeletionLog', () => import('../pages/client_portal/PortalDeletionLog.vue'));
const PortalSettings = lazy('PortalSettings', () => import('../pages/client_portal/PortalSettings.vue'));
const PortalLanguages = lazy('PortalLanguages', () => import('../pages/client_portal/PortalLanguages.vue'));
const PortalTranslations = lazy('PortalTranslations', () => import('../pages/client_portal/PortalTranslations.vue'));
const KnowledgeBase = lazy('KnowledgeBase', () => import('../pages/knowledge_base/KnowledgeBase.vue'));
const KnowledgeBaseArticle = lazy('KnowledgeBaseArticle', () => import('../pages/knowledge_base/KnowledgeBaseArticle.vue'));
const ProjectsDashboard = lazy('ProjectsDashboard', () => import('../pages/projects/ProjectsDashboard.vue'));
const ProjectMilestones = lazy('ProjectMilestones', () => import('../pages/projects/Milestones.vue'));
const ProjectTimesheets = lazy('ProjectTimesheets', () => import('../pages/projects/Timesheets.vue'));
const ProjectReports = lazy('ProjectReports', () => import('../pages/projects/ProjectReports.vue'));
const TaskBoard = lazy('TaskBoard', () => import('../pages/projects/TaskBoard.vue'));
const Projects = lazy('Projects', () => import('../pages/projects/Projects.vue'));
const ProjectForm = lazy('ProjectForm', () => import('../pages/projects/ProjectForm.vue'));
const Tasks = lazy('Tasks', () => import('../pages/tasks/Tasks.vue'));
const TaskForm = lazy('TaskForm', () => import('../pages/tasks/TaskForm.vue'));
const Promotions = lazy('Promotions', () => import('../pages/promotions/Promotions.vue'));
const PromotionForm = lazy('PromotionForm', () => import('../pages/promotions/PromotionForm.vue'));
const PromotionUsages = lazy('PromotionUsages', () => import('../pages/promotions/PromotionUsages.vue'));
const Contracts = lazy('Contracts', () => import('../pages/contracts/Contracts.vue'));
const ContractForm = lazy('ContractForm', () => import('../pages/contracts/ContractForm.vue'));
const ContractView = lazy('ContractView', () => import('../pages/contracts/ContractView.vue'));
const FleetDashboard = lazy('FleetDashboard', () => import('../pages/fleet/FleetDashboard.vue'));
const Vehicles = lazy('Vehicles', () => import('../pages/fleet/Vehicles.vue'));
const VehicleForm = lazy('VehicleForm', () => import('../pages/fleet/VehicleForm.vue'));
const VehicleDetails = lazy('VehicleDetails', () => import('../pages/fleet/VehicleDetails.vue'));
const FleetMaintenance = lazy('FleetMaintenance', () => import('../pages/fleet/Maintenance.vue'));
const FleetFuelLogs = lazy('FleetFuelLogs', () => import('../pages/fleet/FuelLogs.vue'));
const FleetAssignments = lazy('FleetAssignments', () => import('../pages/fleet/Assignments.vue'));
const FleetReports = lazy('FleetReports', () => import('../pages/fleet/FleetReports.vue'));
const HospitalDashboard = lazy('HospitalDashboard', () => import('../pages/hospital/HospitalDashboard.vue'));
const HospitalPatients = lazy('HospitalPatients', () => import('../pages/hospital/Patients.vue'));
const PatientForm = lazy('PatientForm', () => import('../pages/hospital/PatientForm.vue'));
const PatientDetails = lazy('PatientDetails', () => import('../pages/hospital/PatientDetails.vue'));
const HospitalDoctors = lazy('HospitalDoctors', () => import('../pages/hospital/Doctors.vue'));
const HospitalDepartments = lazy('HospitalDepartments', () => import('../pages/hospital/HospitalDepartments.vue'));
const HospitalAppointments = lazy('HospitalAppointments', () => import('../pages/hospital/Appointments.vue'));
const HospitalVisits = lazy('HospitalVisits', () => import('../pages/hospital/Visits.vue'));
const HospitalVisitForm = lazy('HospitalVisitForm', () => import('../pages/hospital/VisitForm.vue'));
const HospitalWards = lazy('HospitalWards', () => import('../pages/hospital/Wards.vue'));
const HospitalAdmissions = lazy('HospitalAdmissions', () => import('../pages/hospital/Admissions.vue'));
const HospitalLabTests = lazy('HospitalLabTests', () => import('../pages/hospital/LabTests.vue'));
const HospitalLabOrders = lazy('HospitalLabOrders', () => import('../pages/hospital/LabOrders.vue'));
const HospitalInvoices = lazy('HospitalInvoices', () => import('../pages/hospital/Invoices.vue'));
const HospitalReports = lazy('HospitalReports', () => import('../pages/hospital/HospitalReports.vue'));
const SchoolDashboard = lazy('SchoolDashboard', () => import('../pages/school/SchoolDashboard.vue'));
const SchoolStudents = lazy('SchoolStudents', () => import('../pages/school/Students.vue'));
const SchoolStudentForm = lazy('SchoolStudentForm', () => import('../pages/school/StudentForm.vue'));
const SchoolStudentDetails = lazy('SchoolStudentDetails', () => import('../pages/school/StudentDetails.vue'));
const SchoolTeachers = lazy('SchoolTeachers', () => import('../pages/school/Teachers.vue'));
const SchoolAcademics = lazy('SchoolAcademics', () => import('../pages/school/Academics.vue'));
const SchoolEnrollments = lazy('SchoolEnrollments', () => import('../pages/school/Enrollments.vue'));
const SchoolAttendance = lazy('SchoolAttendance', () => import('../pages/school/Attendance.vue'));
const SchoolTimetable = lazy('SchoolTimetable', () => import('../pages/school/Timetable.vue'));
const SchoolExams = lazy('SchoolExams', () => import('../pages/school/Exams.vue'));
const SchoolFees = lazy('SchoolFees', () => import('../pages/school/Fees.vue'));
const SchoolReports = lazy('SchoolReports', () => import('../pages/school/SchoolReports.vue'));

// Rental Management
const RentalDashboard = lazy('RentalDashboard', () => import('../pages/rental/RentalDashboard.vue'));
const RentalItems = lazy('RentalItems', () => import('../pages/rental/RentalItems.vue'));
const RentalItemForm = lazy('RentalItemForm', () => import('../pages/rental/RentalItemForm.vue'));
const RentalItemDetails = lazy('RentalItemDetails', () => import('../pages/rental/RentalItemDetails.vue'));
const RentalCustomers = lazy('RentalCustomers', () => import('../pages/rental/RentalCustomers.vue'));
const RentalCustomerForm = lazy('RentalCustomerForm', () => import('../pages/rental/RentalCustomerForm.vue'));
const RentalCustomerDetails = lazy('RentalCustomerDetails', () => import('../pages/rental/RentalCustomerDetails.vue'));
const RentalContracts = lazy('RentalContracts', () => import('../pages/rental/RentalContracts.vue'));
const RentalContractForm = lazy('RentalContractForm', () => import('../pages/rental/RentalContractForm.vue'));
const RentalContractDetails = lazy('RentalContractDetails', () => import('../pages/rental/RentalContractDetails.vue'));
const RentalAgreementTemplates = lazy('RentalAgreementTemplates', () => import('../pages/rental/RentalAgreementTemplates.vue'));
const RentalAvailability = lazy('RentalAvailability', () => import('../pages/rental/RentalAvailability.vue'));
const RentalCalendar = lazy('RentalCalendar', () => import('../pages/rental/RentalCalendar.vue'));
const RentalReservations = lazy('RentalReservations', () => import('../pages/rental/RentalReservations.vue'));
const RentalReservationForm = lazy('RentalReservationForm', () => import('../pages/rental/RentalContractForm.vue'));
const RentalReservationDetails = lazy('RentalReservationDetails', () => import('../pages/rental/RentalReservationDetails.vue'));
const RentalInvoices = lazy('RentalInvoices', () => import('../pages/rental/RentalInvoices.vue'));
const RentalInvoiceDetails = lazy('RentalInvoiceDetails', () => import('../pages/rental/RentalInvoiceDetails.vue'));
const RentalPayments = lazy('RentalPayments', () => import('../pages/rental/RentalPayments.vue'));
const RentalHandover = lazy('RentalHandover', () => import('../pages/rental/RentalHandover.vue'));
const RentalMaintenance = lazy('RentalMaintenance', () => import('../pages/rental/RentalMaintenance.vue'));
const RentalExpenses = lazy('RentalExpenses', () => import('../pages/rental/RentalExpenses.vue'));
const RentalReports = lazy('RentalReports', () => import('../pages/rental/RentalReports.vue'));
const RentalStatement = lazy('RentalStatement', () => import('../pages/rental/RentalStatement.vue'));
const RentalCategories = lazy('RentalCategories', () => import('../pages/rental/RentalCategories.vue'));
const RentalSettings = lazy('RentalSettings', () => import('../pages/rental/RentalSettings.vue'));
// Restaurant Management
const RestaurantSettings = lazy('RestaurantSettings', () => import('../pages/restaurant/RestaurantSettings.vue'));
const RestaurantFloorPlan = lazy('RestaurantFloorPlan', () => import('../pages/restaurant/FloorPlan.vue'));
const RestaurantFloorSetup = lazy('RestaurantFloorSetup', () => import('../pages/restaurant/FloorSetup.vue'));
const RestaurantMenuSetup = lazy('RestaurantMenuSetup', () => import('../pages/restaurant/MenuSetup.vue'));
const RestaurantOrders = lazy('RestaurantOrders', () => import('../pages/restaurant/OrdersList.vue'));
const RestaurantOrderScreen = lazy('RestaurantOrderScreen', () => import('../pages/restaurant/OrderScreen.vue'));
const RestaurantKitchen = lazy('RestaurantKitchen', () => import('../pages/restaurant/KitchenScreen.vue'));
const RestaurantBill = lazy('RestaurantBill', () => import('../pages/restaurant/BillScreen.vue'));
const RestaurantReservations = lazy('RestaurantReservations', () => import('../pages/restaurant/Reservations.vue'));
const RestaurantDashboard = lazy('RestaurantDashboard', () => import('../pages/restaurant/RestaurantDashboard.vue'));
const RestaurantReports = lazy('RestaurantReports', () => import('../pages/restaurant/RestaurantReports.vue'));
const RestaurantGuide = lazy('RestaurantGuide', () => import('../pages/restaurant/RestaurantGuide.vue'));

// CRM: Leads & Sales Pipeline
const CrmDashboard = lazy('CrmDashboard', () => import('../pages/crm/CrmDashboard.vue'));
const CrmLeads = lazy('CrmLeads', () => import('../pages/crm/CrmLeads.vue'));
const CrmLeadForm = lazy('CrmLeadForm', () => import('../pages/crm/CrmLeadForm.vue'));
const CrmLeadDetails = lazy('CrmLeadDetails', () => import('../pages/crm/CrmLeadDetails.vue'));
const CrmLeadImport = lazy('CrmLeadImport', () => import('../pages/crm/CrmLeadImport.vue'));
const CrmSettings = lazy('CrmSettings', () => import('../pages/crm/CrmSettings.vue'));
const CrmPipelineBoard = lazy('CrmPipelineBoard', () => import('../pages/crm/CrmPipelineBoard.vue'));
const CrmDeals = lazy('CrmDeals', () => import('../pages/crm/CrmDeals.vue'));
const CrmDealForm = lazy('CrmDealForm', () => import('../pages/crm/CrmDealForm.vue'));
const CrmDealDetails = lazy('CrmDealDetails', () => import('../pages/crm/CrmDealDetails.vue'));
const CrmMyDay = lazy('CrmMyDay', () => import('../pages/crm/CrmMyDay.vue'));
const CrmActivities = lazy('CrmActivities', () => import('../pages/crm/CrmActivities.vue'));
const CrmCustomers = lazy('CrmCustomers', () => import('../pages/crm/CrmCustomers.vue'));
const CrmCustomer360 = lazy('CrmCustomer360', () => import('../pages/crm/CrmCustomer360.vue'));
const CrmInbox = lazy('CrmInbox', () => import('../pages/crm/CrmInbox.vue'));
const CrmWebForms = lazy('CrmWebForms', () => import('../pages/crm/CrmWebForms.vue'));
const CrmReports = lazy('CrmReports', () => import('../pages/crm/CrmReports.vue'));
const CrmTargets = lazy('CrmTargets', () => import('../pages/crm/CrmTargets.vue'));
const Employees = lazy('Employees', () => import('../pages/hrm/Employees.vue'));
const EmployeeForm = lazy('EmployeeForm', () => import('../pages/hrm/EmployeeForm.vue'));
const EmployeeDetails = lazy('EmployeeDetails', () => import('../pages/hrm/EmployeeDetails.vue'));
const Attendance = lazy('Attendance', () => import('../pages/hrm/Attendance.vue'));
const Leaves = lazy('Leaves', () => import('../pages/hrm/Leaves.vue'));
const Payrolls = lazy('Payrolls', () => import('../pages/hrm/Payrolls.vue'));
const MarketingDashboard = lazy('MarketingDashboard', () => import('../pages/marketing/MarketingDashboard.vue'));
const Campaigns = lazy('Campaigns', () => import('../pages/marketing/Campaigns.vue'));
const CampaignForm = lazy('CampaignForm', () => import('../pages/marketing/CampaignForm.vue'));
const CampaignDetails = lazy('CampaignDetails', () => import('../pages/marketing/CampaignDetails.vue'));
const Segments = lazy('Segments', () => import('../pages/marketing/Segments.vue'));
const MarketingTemplates = lazy('MarketingTemplates', () => import('../pages/marketing/Templates.vue'));
const MarketingReports = lazy('MarketingReports', () => import('../pages/marketing/MarketingReports.vue'));
const MarketingSettings = lazy('MarketingSettings', () => import('../pages/marketing/MarketingSettings.vue'));
const MarketingGuide = lazy('MarketingGuide', () => import('../pages/marketing/MarketingGuide.vue'));
const Expenses = lazy('Expenses', () => import('../pages/expenses/Expenses.vue'));
const ExpenseForm = lazy('ExpenseForm', () => import('../pages/expenses/ExpenseForm.vue'));
const Damages = lazy('Damages', () => import('../pages/damages/Damages.vue'));
const DamageForm = lazy('DamageForm', () => import('../pages/damages/DamageForm.vue'));
const CustomersWithoutLogin = lazy('CustomersWithoutLogin', () => import('../pages/people/CustomersWithoutLogin.vue'));
const CustomersWithLogin = lazy('CustomersWithLogin', () => import('../pages/people/CustomersWithLogin.vue'));
const RealTimeSalesCounter = lazy('RealTimeSalesCounter', () => import('../pages/RealTimeSalesCounter.vue'));
const CustomerDisplaySetup = lazy('CustomerDisplaySetup', () => import('../pages/CustomerDisplaySetup.vue'));
const Deposits = lazy('Deposits', () => import('../pages/deposits/Deposits.vue'));
const DepositForm = lazy('DepositForm', () => import('../pages/deposits/DepositForm.vue'));

// --- ported: sales, purchases, quotations, sale-returns, purchase-returns, adjustments, transfers, shipments, documents ---
const DocumentArchive = lazy('DocumentArchive', () => import('../pages/documents/DocumentArchive.vue'));
const Sales = lazy('Sales', () => import('../pages/sales/Sales.vue'));
const SaleForm = lazy('SaleForm', () => import('../pages/sales/SaleForm.vue'));
const ImportSales = lazy('ImportSales', () => import('../pages/sales/ImportSales.vue'));
const SaleDetails = lazy('SaleDetails', () => import('../pages/sales/SaleDetails.vue'));
const Purchases = lazy('Purchases', () => import('../pages/purchases/Purchases.vue'));
const PurchaseForm = lazy('PurchaseForm', () => import('../pages/purchases/PurchaseForm.vue'));
const ImportPurchases = lazy('ImportPurchases', () => import('../pages/purchases/ImportPurchases.vue'));
const PurchaseDetails = lazy('PurchaseDetails', () => import('../pages/purchases/PurchaseDetails.vue'));
const Quotations = lazy('Quotations', () => import('../pages/quotations/Quotations.vue'));
const QuotationForm = lazy('QuotationForm', () => import('../pages/quotations/QuotationForm.vue'));
const QuotationDetails = lazy('QuotationDetails', () => import('../pages/quotations/QuotationDetails.vue'));
const SaleReturns = lazy('SaleReturns', () => import('../pages/sale_return/SaleReturns.vue'));
const SaleReturnForm = lazy('SaleReturnForm', () => import('../pages/sale_return/SaleReturnForm.vue'));
const SaleReturnDetails = lazy('SaleReturnDetails', () => import('../pages/sale_return/SaleReturnDetails.vue'));
const PurchaseReturns = lazy('PurchaseReturns', () => import('../pages/purchase_return/PurchaseReturns.vue'));
const PurchaseReturnForm = lazy('PurchaseReturnForm', () => import('../pages/purchase_return/PurchaseReturnForm.vue'));
const PurchaseReturnDetails = lazy('PurchaseReturnDetails', () => import('../pages/purchase_return/PurchaseReturnDetails.vue'));
const Adjustments = lazy('Adjustments', () => import('../pages/adjustment/Adjustments.vue'));
const AdjustmentForm = lazy('AdjustmentForm', () => import('../pages/adjustment/AdjustmentForm.vue'));
const AdjustmentDetails = lazy('AdjustmentDetails', () => import('../pages/adjustment/AdjustmentDetails.vue'));
const Transfers = lazy('Transfers', () => import('../pages/transfers/Transfers.vue'));
const TransferForm = lazy('TransferForm', () => import('../pages/transfers/TransferForm.vue'));
const TransferDetails = lazy('TransferDetails', () => import('../pages/transfers/TransferDetails.vue'));
const Shipments = lazy('Shipments', () => import('../pages/sales/Shipments.vue'));
const OfflineSales = lazy('OfflineSales', () => import('../pages/sales/OfflineSales.vue'));
const PosVoids = lazy('PosVoids', () => import('../pages/sales/PosVoids.vue'));
const PosTerminals = lazy('PosTerminals', () => import('../pages/sales/PosTerminals.vue'));

// --- ported: products, serial-numbers ---
const Products = lazy('Products', () => import('../pages/products/Products.vue'));
const CountStock = lazy('CountStock', () => import('../pages/products/CountStock.vue'));
const PriceCheck = lazy('PriceCheck', () => import('../pages/products/PriceCheck.vue'));
const Batches = lazy('Batches', () => import('../pages/products/Batches.vue'));
const LicenseKeys = lazy('LicenseKeys', () => import('../pages/license_keys/LicenseKeys.vue'));
const ImportProducts = lazy('ImportProducts', () => import('../pages/products/ImportProducts.vue'));
const ImportProductsUpdate = lazy('ImportProductsUpdate', () => import('../pages/products/ImportProductsUpdate.vue'));
const OpeningStockImport = lazy('OpeningStockImport', () => import('../pages/products/OpeningStockImport.vue'));
const Barcode = lazy('Barcode', () => import('../pages/products/Barcode.vue'));
const ProductForm = lazy('ProductForm', () => import('../pages/products/ProductForm.vue'));
const SerialNumbers = lazy('SerialNumbers', () => import('../pages/serial_numbers/SerialNumbers.vue'));
const SerialNumberDetails = lazy('SerialNumberDetails', () => import('../pages/serial_numbers/SerialNumberDetails.vue'));
const ProductDetails = lazy('ProductDetails', () => import('../pages/products/ProductDetails.vue'));

// --- ported: settings/, woocommerce, shopify ---
const PaymentGateway = lazy('PaymentGateway', () => import('../pages/settings/PaymentGateway.vue'));
const MailSettings = lazy('MailSettings', () => import('../pages/settings/MailSettings.vue'));
const LoginDevices = lazy('LoginDevices', () => import('../pages/settings/LoginDevices.vue'));
const AuditLog = lazy('AuditLog', () => import('../pages/settings/AuditLog.vue'));
const AiAssistant = lazy('AiAssistant', () => import('../pages/ai/AiAssistant.vue'));
const SystemHealth = lazy('SystemHealth', () => import('../pages/settings/SystemHealth.vue'));
const SmsTemplates = lazy('SmsTemplates', () => import('../pages/settings/SmsTemplates.vue'));
const EmailTemplates = lazy('EmailTemplates', () => import('../pages/settings/EmailTemplates.vue'));
const CustomFields = lazy('CustomFields', () => import('../pages/settings/CustomFields.vue'));
const Backup = lazy('Backup', () => import('../pages/settings/Backup.vue'));
const PosSettings = lazy('PosSettings', () => import('../pages/settings/PosSettings.vue'));
const AppearanceSettings = lazy('AppearanceSettings', () => import('../pages/settings/AppearanceSettings.vue'));
const PwaSettings = lazy('PwaSettings', () => import('../pages/settings/PwaSettings.vue'));
const MobileAppSettings = lazy('MobileAppSettings', () => import('../pages/settings/MobileAppSettings.vue'));
const SmsSettings = lazy('SmsSettings', () => import('../pages/settings/SmsSettings.vue'));
const Languages = lazy('Languages', () => import('../pages/settings/Languages.vue'));
const TranslationsView = lazy('TranslationsView', () => import('../pages/settings/TranslationsView.vue'));
const Webhooks = lazy('Webhooks', () => import('../pages/settings/Webhooks.vue'));
const PosReceipt = lazy('PosReceipt', () => import('../pages/settings/PosReceipt.vue'));
const ModuleSettings = lazy('ModuleSettings', () => import('../pages/settings/ModuleSettings.vue'));
const Modules = lazy('Modules', () => import('../pages/settings/Modules.vue'));
const SystemSettings = lazy('SystemSettings', () => import('../pages/settings/SystemSettings.vue'));
const QuickBooksSync = lazy('QuickBooksSync', () => import('../pages/settings/QuickBooksSync.vue'));
const ZapierSettings = lazy('ZapierSettings', () => import('../pages/settings/ZapierSettings.vue'));
const ApiSettings = lazy('ApiSettings', () => import('../pages/settings/ApiSettings.vue'));
const CustomDomains = lazy('CustomDomains', () => import('../pages/settings/CustomDomains.vue'));
const ZatcaSettings = lazy('ZatcaSettings', () => import('../pages/settings/ZatcaSettings.vue'));
const WooCommerceSettings = lazy('WooCommerceSettings', () => import('../pages/settings/WooCommerceSettings.vue'));
// --- Shopify Integration ---
const ShopifyDashboard = lazy('ShopifyDashboard', () => import('../pages/shopify/ShopifyDashboard.vue'));
const ShopifyStores = lazy('ShopifyStores', () => import('../pages/shopify/ShopifyStores.vue'));
const ShopifyStoreDetails = lazy('ShopifyStoreDetails', () => import('../pages/shopify/ShopifyStoreDetails.vue'));
const ShopifySyncCenter = lazy('ShopifySyncCenter', () => import('../pages/shopify/ShopifySyncCenter.vue'));
const ShopifyMappings = lazy('ShopifyMappings', () => import('../pages/shopify/ShopifyMappings.vue'));
const ShopifyLogs = lazy('ShopifyLogs', () => import('../pages/shopify/ShopifyLogs.vue'));
const ShopifyGuidePage = lazy('ShopifyGuidePage', () => import('../pages/shopify/ShopifyGuidePage.vue'));

// --- Phase 7: SaaS-only settings pages (fresh) ---
const FbrSettings = lazy('FbrSettings', () => import('../pages/settings/FbrSettings.vue'));
const AgtSettings = lazy('AgtSettings', () => import('../pages/settings/AgtSettings.vue'));
const DgiSettings = lazy('DgiSettings', () => import('../pages/settings/DgiSettings.vue'));
const WebhookDeliveries = lazy('WebhookDeliveries', () => import('../pages/settings/WebhookDeliveries.vue'));
const WebhookIncomingLogs = lazy('WebhookIncomingLogs', () => import('../pages/settings/WebhookIncomingLogs.vue'));
const WhatsAppSettings = lazy('WhatsAppSettings', () => import('../pages/whatsapp/WhatsAppSettings.vue'));
const WhatsAppTemplates = lazy('WhatsAppTemplates', () => import('../pages/whatsapp/WhatsAppTemplates.vue'));
const WhatsAppTemplateForm = lazy('WhatsAppTemplateForm', () => import('../pages/whatsapp/WhatsAppTemplateForm.vue'));
const WhatsAppLogs = lazy('WhatsAppLogs', () => import('../pages/whatsapp/WhatsAppLogs.vue'));
const IntegrationsHub = lazy('IntegrationsHub', () => import('../pages/integrations/IntegrationsHub.vue'));
const SlackSettings = lazy('SlackSettings', () => import('../pages/integrations/SlackSettings.vue'));
const TelegramSettings = lazy('TelegramSettings', () => import('../pages/integrations/TelegramSettings.vue'));
const SallaSettings = lazy('SallaSettings', () => import('../pages/integrations/SallaSettings.vue'));
const XeroSettings = lazy('XeroSettings', () => import('../pages/integrations/XeroSettings.vue'));
const PrestashopSettings = lazy('PrestashopSettings', () => import('../pages/integrations/PrestashopSettings.vue'));
const OpencartSettings = lazy('OpencartSettings', () => import('../pages/integrations/OpencartSettings.vue'));
const OpencartGuide = lazy('OpencartGuide', () => import('../pages/integrations/OpencartGuide.vue'));
const OpencartLogs = lazy('OpencartLogs', () => import('../pages/integrations/OpencartLogs.vue'));
const GoogleSheetsSettings = lazy('GoogleSheetsSettings', () => import('../pages/integrations/GoogleSheetsSettings.vue'));
const MailchimpSettings = lazy('MailchimpSettings', () => import('../pages/integrations/MailchimpSettings.vue'));
const JumiaSettings = lazy('JumiaSettings', () => import('../pages/integrations/JumiaSettings.vue'));
const PrintfulSettings = lazy('PrintfulSettings', () => import('../pages/integrations/PrintfulSettings.vue'));
const CourierAccounts = lazy('CourierAccounts', () => import('../pages/couriers/CourierAccounts.vue'));
const CourierSettings = lazy('CourierSettings', () => import('../pages/couriers/CourierSettings.vue'));
const CourierShipments = lazy('CourierShipments', () => import('../pages/couriers/CourierShipments.vue'));
const CourierReports = lazy('CourierReports', () => import('../pages/couriers/CourierReports.vue'));
const CourierLogs = lazy('CourierLogs', () => import('../pages/couriers/CourierLogs.vue'));
const CourierGuide = lazy('CourierGuide', () => import('../pages/couriers/CourierGuide.vue'));

// --- ported: profile ---
const Profile = lazy('Profile', () => import('../pages/profile/Profile.vue'));
// --- Phase 8: SaaS-only pages (billing, support, 403) ---
const BillingCurrentPlan = lazy('BillingCurrentPlan', () => import('../pages/billing/BillingCurrentPlan.vue'));
const BillingChangePlan = lazy('BillingChangePlan', () => import('../pages/billing/BillingChangePlan.vue'));
const BillingCheckout = lazy('BillingCheckout', () => import('../pages/billing/BillingCheckout.vue'));
const BillingResult = lazy('BillingResult', () => import('../pages/billing/BillingResult.vue'));
const BillingHistory = lazy('BillingHistory', () => import('../pages/billing/BillingHistory.vue'));
const BillingAddons = lazy('BillingAddons', () => import('../pages/billing/BillingAddons.vue'));
const SupportTickets = lazy('SupportTickets', () => import('../pages/support/SupportTickets.vue'));
const SupportTicketForm = lazy('SupportTicketForm', () => import('../pages/support/SupportTicketForm.vue'));
const SupportTicketDetail = lazy('SupportTicketDetail', () => import('../pages/support/SupportTicketDetail.vue'));
const NotAuthorized = lazy('NotAuthorized', () => import('../pages/NotAuthorized.vue'));

// --- ported: /pos ---
const PosPage = lazy('PosPage', () => import('../pages/pos/PosPage.vue'));

const routes = [
    // Diagnostic page — outside AdminLayout, no auth, no data, no charts.
    { path: '/ping', name: 'ping', component: Ping, meta: { skipAuth: true, title: 'Ping' } },
    // Legacy URLs keep working without server redirects: /app/... (Vue 2 paths
    // — bookmarks, e-mails, gateway callbacks) map through config/migratedRoutes.json
    // (query string preserved); /next/... was the migration prefix.
    {
        path: '/app/:pathMatch(.*)*',
        redirect: to => resolveMigratedPath(to.fullPath)
            || { name: 'not-found', params: { pathMatch: ['app'].concat(to.params.pathMatch || []) }, query: to.query },
    },
    {
        path: '/next/:pathMatch(.*)*',
        redirect: to => ({ path: '/' + [].concat(to.params.pathMatch || []).join('/'), query: to.query, hash: to.hash }),
    },
    // POS 1:1 port — fullscreen, outside AdminLayout (own 100vh layout).
    { path: '/pos', name: 'pos', component: PosPage, meta: { title: 'POS', permission: 'Pos_view' } },
    {
        path: '/',
        component: AdminLayout,
        children: [
            { path: '', redirect: '/dashboard' },
            { path: 'dashboard', name: 'dashboard', component: Dashboard, meta: { title: 'Dashboard', permission: 'dashboard' } },
            // AI Assistant (plan feature 'ai_assistant').
            { path: 'ai-assistant', name: 'ai-assistant', component: AiAssistant, meta: { title: 'AI_Assistant', permission: 'ai_assistant' } },
            // Phase 2 — simple CRUD. Permissions = the legacy sidebar gates (config/menu.js).
            // Rack locations (legacy Warehouse_Locations page) are a tab of Warehouses.
            { path: 'brands', name: 'brands', component: Brands, meta: { title: 'Brands', permission: 'brand' } },
            { path: 'size-guides', name: 'size-guides', component: SizeGuides, meta: { title: 'Size_Guides', permission: 'size_guides' } },
            { path: 'categories', name: 'categories', component: Categories, meta: { title: 'MultiCategoriesLabel', permission: 'category' } },
            { path: 'subcategories', name: 'subcategories', component: SubCategories, meta: { title: 'Page_SubCategories', permission: 'subcategory' } },
            { path: 'units', name: 'units', component: Units, meta: { title: 'Page_Units', permission: 'unit' } },
            { path: 'currencies', name: 'currencies', component: Currencies, meta: { title: 'Page_Currencies', permission: 'currency' } },
            { path: 'warehouses', name: 'warehouses', component: Warehouses, meta: { title: 'Page_Warehouses', permission: ['warehouse', 'warehouse_locations'] } },
            { path: 'settings/payment-methods', name: 'payment-methods', component: PaymentMethods, meta: { title: 'Payment_Methods', permission: 'payment_methods' } },
            { path: 'expenses/categories', name: 'expense-categories', component: ExpenseCategories, meta: { title: 'Page_Expense_Categories', permission: 'expense_view' } },
            { path: 'deposits/categories', name: 'deposit-categories', component: DepositCategories, meta: { title: 'Page_Deposit_Categories', permission: 'deposit_view' } },
            { path: 'assets/categories', name: 'asset-categories', component: AssetCategories, meta: { title: 'Asset_Categories', permission: 'assets' } },
            { path: 'recruit/categories', name: 'recruit-categories', component: RecruitCategories, meta: { title: 'Job_Categories', permission: 'recruit_category' } },
            { path: 'realestate/categories', name: 'realestate-categories', component: PropertyCategories, meta: { title: 'Property_Categories', permission: 'realestate_categories' } },
            { path: 'service/technicians', name: 'service-technicians', component: ServiceTechnicians, meta: { title: 'Service_Technicians', permission: 'service_jobs' } },
            { path: 'service/checklist-categories', name: 'service-checklist-categories', component: ServiceChecklistCategories, meta: { title: 'Checklist_Categories', permission: 'service_jobs' } },
            { path: 'service/checklists', name: 'service-checklists', component: ServiceChecklists, meta: { title: 'Checklist_Items', permission: 'service_jobs' } },
            { path: 'hrm/companies', name: 'hrm-companies', component: Companies, meta: { title: 'Page_Companies', permission: 'company' } },
            { path: 'hrm/departments', name: 'hrm-departments', component: Departments, meta: { title: 'Departments', permission: 'department' } },
            { path: 'hrm/designations', name: 'hrm-designations', component: Designations, meta: { title: 'Designations', permission: 'designation' } },
            { path: 'hrm/office-shifts', name: 'hrm-office-shifts', component: OfficeShifts, meta: { title: 'Page_Office_Shifts', permission: 'office_shift' } },
            { path: 'hrm/holidays', name: 'hrm-holidays', component: Holidays, meta: { title: 'Holidays', permission: 'holiday' } },
            { path: 'hrm/leave-types', name: 'leave-types', component: LeaveTypes, meta: { title: 'Page_Leave_Types', permission: 'leave' } },
            { path: 'accounts', name: 'accounts', component: Accounts, meta: { title: 'Accounts', permission: 'account' } },
            { path: 'accounts/:id/statement', name: 'account-statement', component: AccountStatement, meta: { title: 'Account_Statement', permission: 'account' } },
            { path: 'transfer-money', name: 'transfer-money', component: TransferMoney, meta: { title: 'Page_Transfer_Money', permission: 'transfer_money' } },
            // Phase 3 — reports. One component may serve several routes (meta.kind /
            // meta.dimension); router-view is keyed by path so they remount.
            { path: 'reports/dead-stock', name: 'dead-stock-report', component: DeadStockReport, meta: { title: 'Dead_Stock_Report', permission: 'Dead_Stock_Report' } },
            { path: 'reports/zero-sales', name: 'zero-sales-report', component: ZeroSalesReport, meta: { title: 'Page_Zero_Sales_Products', permission: 'zeroSalesProducts' } },
            { path: 'reports/negative-stock', name: 'negative-stock-report', component: NegativeStockReport, meta: { title: 'Page_Negative_Stock', permission: 'negative_stock_report' } },
            { path: 'reports/expiry', name: 'expiry-report', component: ExpiryReport, meta: { title: 'Expiry_Report', permission: 'expiry_report' } },
            { path: 'reports/discount-summary', name: 'discount-summary-report', component: DiscountSummaryReport, meta: { title: 'Page_Discount_Summary', permission: 'discount_summary_report' } },
            { path: 'reports/due-collection', name: 'due-collection-report', component: DueCollectionReport, meta: { title: 'Page_Due_Collection', permission: 'due_collection_report' } },
            { path: 'reports/pos-sales', name: 'pos-sales-report', component: PosSalesReport, meta: { title: 'POS_Sales_Report', permission: 'pos_sales_report' } },
            { path: 'reports/tax-summary', name: 'tax-summary-report', component: TaxSummaryReport, meta: { title: 'Tax_Summary_Link', permission: 'tax_summary_report' } },
            { path: 'reports/loyalty-points', name: 'loyalty-points-report', component: LoyaltyPointsReport, meta: { title: 'Loyalty_Points', permission: 'customer_loyalty_points_report' } },
            { path: 'reports/inactive-customers', name: 'inactive-customers-report', component: InactiveCustomersReport, meta: { title: 'Page_Inactive_Customers', permission: 'inactive_customers_report' } },
            { path: 'reports/draft-invoices', name: 'draft-invoices-report', component: DraftInvoicesReport, meta: { title: 'Page_Draft_Invoices', permission: 'draft_invoices_report' } },
            { path: 'reports/top-suppliers', name: 'top-suppliers-report', component: TopSuppliersReport, meta: { title: 'Page_Top_Suppliers', permission: 'Top_Suppliers_Report' } },
            { path: 'reports/warranty', name: 'warranty-report', component: WarrantyReport, meta: { title: 'Page_Warranty_And_Guarantee', permission: 'report_warranty' } },
            { path: 'reports/stock-adjustment', name: 'stock-adjustment-report', component: StockAdjustmentReport, meta: { title: 'Page_Stock_Adjustment', permission: 'Stock_Adjustment_Report' } },
            { path: 'reports/serial-sold', name: 'serial-sold-report', component: SerialSoldReport, meta: { title: 'Sold_Serial_Numbers', permission: 'serial_numbers_report' } },
            { path: 'reports/serial-available', name: 'serial-available-report', component: SerialAvailableReport, meta: { title: 'Available_Serial_Numbers', permission: 'serial_numbers_report' } },
            { path: 'reports/serial-movement', name: 'serial-movement-report', component: SerialMovementReport, meta: { title: 'Serial_Movement_Log', permission: 'serial_numbers_report' } },
            { path: 'reports/serial-inventory', name: 'serial-inventory-report', component: SerialInventoryReport, meta: { title: 'Product_Serial_Inventory', permission: 'serial_numbers_report' } },
            { path: 'reports/batch-register', name: 'batch-register-report', component: BatchRegisterReport, meta: { title: 'Batch_Register_Report', permission: 'Batch_Register_Report' } },
            { path: 'reports/payments-sale', name: 'payments-sale-report', component: PaymentsReport, meta: { title: 'Page_Payments_Sales', permission: 'Reports_payments_Sales', kind: 'sale' } },
            { path: 'reports/payments-purchase', name: 'payments-purchase-report', component: PaymentsReport, meta: { title: 'Page_Payments_Purchases', permission: 'Reports_payments_Purchases', kind: 'purchase' } },
            { path: 'reports/payments-sales-returns', name: 'payments-sales-returns-report', component: PaymentsReport, meta: { title: 'Page_Payments_Sales_Returns', permission: 'Reports_payments_Sale_Returns', kind: 'returns_sale' } },
            { path: 'reports/payments-purchases-returns', name: 'payments-purchases-returns-report', component: PaymentsReport, meta: { title: 'Page_Payments_Purchases_Returns', permission: 'Reports_payments_purchase_Return', kind: 'returns_purchase' } },
            { path: 'reports/customers', name: 'customers-report', component: CustomersReport, meta: { title: 'CustomersReport', permission: 'Reports_customers' } },
            { path: 'reports/suppliers', name: 'suppliers-report', component: SuppliersReport, meta: { title: 'SuppliersReport', permission: 'Reports_suppliers' } },
            { path: 'reports/cash-registers', name: 'cash-register-report', component: CashRegisterReport, meta: { title: 'Cash_Register_Report', permission: 'cash_register_report' } },
            { path: 'reports/attendance', name: 'attendance-report', component: AttendanceReport, meta: { title: 'attendance_summary', permission: 'report_attendance_summary' } },
            { path: 'reports/service-jobs', name: 'service-jobs-report', component: ServiceJobsReport, meta: { title: 'Service_Jobs_Report', permission: 'service_jobs_report' } },
            { path: 'reports/checklist-completion', name: 'checklist-completion-report', component: ChecklistCompletionReport, meta: { title: 'Page_Checklist_Completion', permission: 'checklist_completion_report' } },
            { path: 'reports/maintenance-history', name: 'maintenance-history-report', component: MaintenanceHistoryReport, meta: { title: 'Customer_Maintenance_History', permission: 'customer_maintenance_history_report' } },
            { path: 'reports/users', name: 'users-report', component: UsersReport, meta: { title: 'Users_Report', permission: 'users_report' } },
            { path: 'reports/login-activity', name: 'login-activity-report', component: LoginActivityReport, meta: { title: 'Page_Login_Activity', permission: 'report_device_management' } },
            { path: 'reports/error-logs', name: 'error-logs-report', component: ErrorLogsReport, meta: { title: 'Error_Logs', permission: 'report_error_logs' } },
            { path: 'reports/top-selling-products', name: 'top-selling-products-report', component: TopSellingProductsReport, meta: { title: 'Top_Selling_Products', permission: 'Top_products' } },
            { path: 'reports/top-customers', name: 'top-customers-report', component: TopCustomersReport, meta: { title: 'Top_Customers', permission: 'Top_customers' } },
            { path: 'reports/expenses', name: 'expenses-report', component: ExpensesReport, meta: { title: 'Page_Expenses_Report', permission: 'expenses_report' } },
            { path: 'reports/deposits', name: 'deposits-report', component: DepositsReport, meta: { title: 'Deposits_Report', permission: 'deposits_report' } },
            { path: 'reports/sales', name: 'sales-report', component: SalesReport, meta: { title: 'SalesReport', permission: 'Reports_sales' } },
            { path: 'reports/purchases', name: 'purchases-report', component: PurchasesReport, meta: { title: 'PurchasesReport', permission: 'Reports_purchase' } },
            { path: 'reports/quantity-alerts', name: 'quantity-alerts-report', component: QuantityAlertsReport, meta: { title: 'Page_Quantity_Alerts', permission: 'Reports_quantity_alerts' } },
            { path: 'reports/stock', name: 'stock-report', component: StockReport, meta: { title: 'stock_report', permission: 'stock_report' } },
            { path: 'reports/product', name: 'product-report', component: ProductReport, meta: { title: 'Page_Product_Report', permission: 'product_report' } },
            { path: 'reports/customers/:id(\\d+)', name: 'customer-detail-report', component: CustomerDetailReport, meta: { title: 'Page_Customer_Report', permission: 'Reports_customers' } },
            { path: 'reports/suppliers/:id(\\d+)', name: 'supplier-detail-report', component: SupplierDetailReport, meta: { title: 'SupplierReport', permission: 'Reports_suppliers' } },
            { path: 'reports/product/:id(\\d+)', name: 'product-detail-report', component: ProductDetailReport, meta: { title: 'Page_Product_Report', permission: 'product_report' } },
            { path: 'reports/stock/:id(\\d+)', name: 'stock-detail-report', component: StockDetailReport, meta: { title: 'stock_report', permission: 'stock_report' } },
            { path: 'reports/users/:id(\\d+)', name: 'user-detail-report', component: UserDetailReport, meta: { title: 'User_report', permission: 'users_report' } },
            { path: 'reports/batch-history/:id(\\d+)', name: 'batch-history-report', component: BatchHistoryReport, meta: { title: 'Batch_History', permission: 'Batch_Register_Report' } },
            { path: 'reports/product-sales', name: 'product-sales-report', component: ProductSalesReport, meta: { title: 'product_sales_report', permission: 'product_sales_report' } },
            { path: 'reports/products-sold-summary', name: 'products-sold-summary-report', component: ProductsSoldSummaryReport, meta: { title: 'Products_Sold_Summary_Report', permission: 'products_sold_summary' } },
            { path: 'reports/product-purchases', name: 'product-purchases-report', component: ProductPurchasesReport, meta: { title: 'Product_purchases_report', permission: 'product_purchases_report' } },
            { path: 'reports/seller', name: 'seller-report', component: SellerReport, meta: { title: 'Seller_report', permission: 'seller_report' } },
            { path: 'reports/transactions', name: 'transactions-report', component: TransactionsReport, meta: { title: 'Transactions', permission: 'report_transactions' } },
            { path: 'reports/internal-location', name: 'internal-location-report', component: InternalLocationReport, meta: { title: 'Page_Internal_Location', permission: 'internal_location_report' } },
            { path: 'reports/sales-by-category', name: 'sales-by-category-report', component: SalesByGroupReport, meta: { title: 'Sales_by_Category', permission: 'report_sales_by_category', kind: 'category' } },
            { path: 'reports/sales-by-brand', name: 'sales-by-brand-report', component: SalesByGroupReport, meta: { title: 'Sales_by_Brand', permission: 'report_sales_by_brand', kind: 'brand' } },
            { path: 'reports/profit-and-loss', name: 'profit-and-loss-report', component: ProfitAndLossReport, meta: { title: 'ProfitandLoss', permission: 'Reports_profit', costData: true } },
            { path: 'reports/profit/product', name: 'profit-by-product', component: ProfitReport, meta: { title: 'Profit_by_Product', permission: 'Reports_profit', costData: true, dimension: 'product' } },
            { path: 'reports/profit/category', name: 'profit-by-category', component: ProfitReport, meta: { title: 'Profit_by_Category', permission: 'Reports_profit', costData: true, dimension: 'category' } },
            { path: 'reports/profit/unit', name: 'profit-by-unit', component: ProfitReport, meta: { title: 'Profit_by_Unit', permission: 'Reports_profit', costData: true, dimension: 'unit' } },
            { path: 'reports/profit/customer', name: 'profit-by-customer', component: ProfitReport, meta: { title: 'Profit_by_Customer', permission: 'Reports_profit', costData: true, dimension: 'customer' } },
            { path: 'reports/profit/date', name: 'profit-by-date', component: ProfitReport, meta: { title: 'Profit_by_Date', permission: 'Reports_profit', costData: true, dimension: 'date' } },
            { path: 'reports/profit/warehouse', name: 'profit-by-warehouse', component: ProfitReport, meta: { title: 'Profit_by_Warehouse', permission: 'Reports_profit', costData: true, dimension: 'warehouse' } },
            { path: 'reports/analytics', name: 'analytics-report', component: AnalyticsReport, meta: { title: 'Analytics_Report', permission: 'analytics_report' } },
            { path: 'reports/cash-flow', name: 'cash-flow-report', component: CashFlowReport, meta: { title: 'cash_flow', permission: 'cash_flow_report' } },
            { path: 'reports/stock-transfer', name: 'stock-transfer-report', component: StockTransferReport, meta: { title: 'StockTransfer', permission: 'Stock_Transfer_Report' } },
            { path: 'reports/return-ratio', name: 'return-ratio-report', component: ReturnRatioReport, meta: { title: 'Page_Return_Ratio', permission: 'return_ratio_report' } },
            { path: 'reports/stock-aging', name: 'stock-aging-report', component: StockAgingReport, meta: { title: 'Page_Stock_Aging', permission: 'Stock_Aging_Report' } },
            { path: 'reports/inventory-valuation-summary', name: 'inventory-valuation-summary-report', component: InventoryValuationSummaryReport, meta: { title: 'Inventory_Valuation', permission: 'inventory_valuation' } },
            { path: 'reports/stock-inventory-valuation', name: 'stock-inventory-valuation-report', component: StockInventoryValuationReport, meta: { title: 'Stock_Inventory_Valuation', permission: 'Stock_Inventory_Valuation' } },
            { path: 'reports/warehouse', name: 'warehouse-report', component: WarehouseReport, meta: { title: 'Page_Warehouse_Report', permission: 'Warehouse_report' } },
            { path: 'reports/ai-reports', name: 'ai-reports', component: AiReports, meta: { title: 'AI_Reports', permission: 'AI_Reports' } },
            { path: 'reports/sales-3d-dashboard', name: 'sales-3d-dashboard', component: Sales3DDashboard, meta: { title: 'sales_3d_dashboard', permission: 'sales_3d_dashboard' } },
            // --- ported: customers, customers-with-login, customers-without-login, suppliers, users, permissions, hrm/, hospital/, school/, mrp, marketing/, service/, projects, tasks, recruit/, fleet/, accounting-v2/, commissions/, meeting, meetings, assets, bookings, contracts, realestate/, kb, kitchen-display, store/, expenses, deposits, damages, promotions, subscriptions, customer-display/setup, real-time-sales-counter ---

            { path: 'customers', name: 'customers', component: Customers, meta: { title: 'Customers', permission: 'Customers_view' } },

            { path: 'customers/import', name: 'customers-import', component: ImportCustomers, meta: { title: 'Import_Customers', permission: 'customers_import' } },

            { path: 'customers/create', name: 'customer-create', component: CustomerForm, meta: { title: 'Add_Customer', permission: 'Customers_add' } },

            { path: 'customers/:id/edit', name: 'customer-edit', component: CustomerForm, meta: { title: 'Edit_Customer', permission: 'Customers_view' } },

            { path: 'customers/:id/details', name: 'customer-details', component: CustomerDetails, meta: { title: 'CustomerDetails', permission: 'Customers_view' } },

            { path: 'customers/:id/ledger', name: 'customer-ledger', component: CustomerLedger, meta: { title: 'Customer_Ledger', permission: 'Customers_view' } },

            // Customer Wallet + gift cards (plan feature customer_wallet + Settings > Features switch)
            { path: 'customers/wallets', name: 'customer-wallets', component: CustomerWallets, meta: { title: 'CW_Customer_Wallets', permission: 'customer_wallet_view' } },

            { path: 'customers/gift-cards', name: 'gift-cards', component: GiftCards, meta: { title: 'CW_Gift_Cards', permission: 'gift_cards_manage' } },

            { path: 'customers/:id/wallet', name: 'customer-wallet-ledger', component: CustomerWalletLedger, meta: { title: 'CW_Wallet_Ledger', permission: 'customer_wallet_view' } },

            { path: 'suppliers', name: 'suppliers', component: Suppliers, meta: { title: 'Suppliers', permission: 'Suppliers_view' } },

            { path: 'suppliers/import', name: 'suppliers-import', component: ImportSuppliers, meta: { title: 'Import_Suppliers', permission: 'Suppliers_import' } },

            { path: 'suppliers/create', name: 'supplier-create', component: SupplierForm, meta: { title: 'Page_Add_Supplier', permission: 'Suppliers_add' } },

            { path: 'suppliers/:id/edit', name: 'supplier-edit', component: SupplierForm, meta: { title: 'Page_Edit_Supplier', permission: 'Suppliers_view' } },

            // Supplier Wallet (plan feature supplier_wallet + Settings > Features switch)
            { path: 'suppliers/wallets', name: 'supplier-wallets', component: SupplierWallets, meta: { title: 'Supplier_Wallets', permission: 'supplier_wallet_view' } },

            { path: 'suppliers/:id/ledger', name: 'supplier-ledger', component: SupplierLedger, meta: { title: 'Supplier_Ledger', permission: 'supplier_wallet_view' } },


            { path: 'users', name: 'users', component: Users, meta: { title: 'Users', permission: 'users_view' } },

            { path: 'users/create', name: 'user-create', component: UserForm, meta: { title: 'Page_Add_User', permission: 'users_add' } },

            { path: 'users/:id/edit', name: 'user-edit', component: UserForm, meta: { title: 'Page_Edit_User', permission: 'users_view' } },

            { path: 'mrp', name: 'mrp', component: MrpDashboard, meta: { title: 'Manufacturing', permission: 'mrp_production' } },

            { path: 'mrp/production-orders', name: 'mrp-orders', component: MrpProductionOrders, meta: { title: 'Mrp_Production_Orders', permission: 'mrp_production' } },

            { path: 'mrp/production-orders/create', name: 'mrp-order-create', component: MrpProductionOrderForm, meta: { title: 'Mrp_New_Production_Order', permission: 'mrp_production' } },

            { path: 'mrp/production-orders/:id/edit', name: 'mrp-order-edit', component: MrpProductionOrderForm, meta: { title: 'Mrp_Edit_Production_Order', permission: 'mrp_production' } },

            { path: 'mrp/production-orders/:id', name: 'mrp-order-details', component: MrpProductionOrderDetails, meta: { title: 'Mrp_Production_Order', permission: 'mrp_production' } },

            { path: 'mrp/work-orders', name: 'mrp-work-orders', component: MrpWorkOrders, meta: { title: 'Mrp_Shop_Floor', permission: 'mrp_production' } },

            { path: 'mrp/boms', name: 'mrp-boms', component: MrpBoms, meta: { title: 'Mrp_Bills_Of_Materials', permission: 'mrp_boms' } },

            { path: 'mrp/boms/create', name: 'mrp-bom-create', component: MrpBomForm, meta: { title: 'Mrp_New_Bom', permission: 'mrp_boms' } },

            { path: 'mrp/boms/:id/edit', name: 'mrp-bom-edit', component: MrpBomForm, meta: { title: 'Page_Edit_BOM', permission: 'mrp_boms' } },

            { path: 'mrp/work-centers', name: 'mrp-work-centers', component: MrpWorkCenters, meta: { title: 'Mrp_Work_Centres', permission: 'mrp_boms' } },

            { path: 'mrp/quality', name: 'mrp-quality', component: MrpQualityChecks, meta: { title: 'Mrp_Quality_Control', permission: 'mrp_quality' } },

            { path: 'mrp/planning', name: 'mrp-planning', component: MrpPlanning, meta: { title: 'Page_MRP_Planning', permission: 'mrp_planning' } },

            { path: 'mrp/reports', name: 'mrp-reports', component: MrpReports, meta: { title: 'Manufacturing_Reports', permission: 'mrp_reports' } },







            { path: 'assets', name: 'assets', component: Assets, meta: { title: 'Assets', permission: 'assets' } },

            { path: 'assets/create', name: 'asset-create', component: AssetForm, meta: { title: 'Add_Asset', permission: 'assets' } },

            { path: 'assets/:id/edit', name: 'asset-edit', component: AssetForm, meta: { title: 'Edit_Asset', permission: 'assets' } },

            { path: 'assets/due', name: 'assets-due', component: DueAssets, meta: { title: 'Due_Assets', permission: 'assets' } },
            { path: 'assets/dashboard', name: 'assets-dashboard', component: AssetsDashboard, meta: { title: 'Asset_Dashboard', permission: 'assets' } },
            { path: 'assets/assignments', name: 'asset-assignments', component: AssetAssignments, meta: { title: 'Asset_Assignments', permission: 'asset_assignments' } },
            { path: 'assets/maintenance', name: 'asset-maintenance', component: AssetMaintenance, meta: { title: 'Page_Asset_Maintenance', permission: 'asset_maintenance' } },
            { path: 'assets/transfers', name: 'asset-transfers', component: AssetTransfers, meta: { title: 'Page_Asset_Transfers', permission: 'asset_transfers' } },
            { path: 'assets/depreciation', name: 'asset-depreciation', component: AssetDepreciation, meta: { title: 'Depreciation', permission: 'asset_reports' } },
            { path: 'assets/reports', name: 'asset-reports', component: AssetReports, meta: { title: 'Asset_Reports', permission: 'asset_reports' } },
            // Must stay after every literal assets/* path above.
            { path: 'assets/:id', name: 'asset-details', component: AssetDetails, meta: { title: 'Asset', permission: 'assets' } },


            { path: 'subscriptions', name: 'subscriptions', component: Subscriptions, meta: { title: 'Subscriptions', permission: 'subscription_product' } },

            { path: 'subscriptions/create', name: 'subscription-create', component: SubscriptionForm, meta: { title: 'Page_Create_Subscription', permission: 'subscription_product' } },

            { path: 'subscriptions/:id(\\d+)', name: 'subscription-details', component: SubscriptionDetails, meta: { title: 'Subscription_details', permission: 'subscription_product' } },

            { path: 'bookings/calendar', name: 'booking-calendar', component: BookingCalendar, meta: { title: 'Page_Booking_Calendar', permission: 'bookings' } },

            { path: 'service/jobs', name: 'service-jobs', component: ServiceJobsList, meta: { title: 'Service_Jobs', permission: 'service_jobs' } },

            { path: 'service/jobs/create', name: 'service-job-create', component: ServiceJobForm, meta: { title: 'Create_Service_Job', permission: 'service_jobs' } },

            { path: 'service/jobs/edit/:id(\\d+)', name: 'service-job-edit', component: ServiceJobForm, meta: { title: 'Edit_Service_Job', permission: 'service_jobs' } },

            { path: 'service/jobs/details/:id(\\d+)', name: 'service-job-details', component: ServiceJobDetails, meta: { title: 'Service_Job_Details', permission: 'service_jobs' } },

            { path: 'service/history', name: 'service-history', component: CustomerMaintenanceHistory, meta: { title: 'Maintenance_History', permission: 'service_jobs' } },

            { path: 'permissions', name: 'permissions', component: GroupPermissions, meta: { title: 'GroupPermissions', permission: 'permissions_view' } },

            { path: 'permissions/create', name: 'permission-create', component: PermissionForm, meta: { title: 'Create_Permission', permission: 'permissions_add' } },

            { path: 'permissions/:id(\\d+)/edit', name: 'permission-edit', component: PermissionForm, meta: { title: 'Edit_Permission', permission: 'permissions_edit' } },

            { path: 'store/settings', name: 'store-settings', component: StoreSettings, meta: { title: 'Page_Store_Settings', permission: 'Store_settings_view' } },

            { path: 'store/orders', name: 'store-orders', component: StoreOrders, meta: { title: 'Online_Orders', permission: 'Orders_view' } },

            { path: 'store/orders/:id(\\d+)', name: 'store-order-show', component: StoreOrderShow, meta: { title: 'Order', permission: 'Orders_view' } },

            { path: 'store/collections', name: 'store-collections', component: StoreCollections, meta: { title: 'Collections', permission: 'Collections_view' } },

            { path: 'store/collections/create', name: 'store-collection-create', component: StoreCollectionForm, meta: { title: 'New_Collection', permission: 'Collections_view' } },

            { path: 'store/collections/edit/:id(\\d+)', name: 'store-collection-edit', component: StoreCollectionForm, meta: { title: 'Edit_Collection', permission: 'Collections_view' } },

            { path: 'store/messages', name: 'store-messages', component: StoreMessages, meta: { title: 'Messages', permission: 'Messages_view' } },

            { path: 'store/invite-codes', name: 'store-invite-codes', component: StoreInviteCodes, meta: { title: 'Invite_Codes', permission: 'store_invite_codes' } },

            { path: 'store/subscribers', name: 'store-subscribers', component: StoreSubscribers, meta: { title: 'Subscribers', permission: 'Subscribers_view' } },

            { path: 'store/pending-customers', name: 'store-pending-customers', component: StorePendingCustomers, meta: { title: 'Pending_Customers', permission: 'store_pending_customers' } },

            { path: 'store/banners', name: 'store-banners', component: StoreBanners, meta: { title: 'Banners', permission: 'Banners_view' } },

            { path: 'store/banners/edit/:id', name: 'store-banner-edit', component: StoreBannerForm, meta: { title: 'Banner', permission: 'Banners_view' } },

            { path: 'store/payment-gateway', name: 'store-payment-gateway', component: StorePaymentGatewayPage, meta: { title: 'Payment_Gateway', permission: 'payment_gateway' } },

            { path: 'store/shipping-methods', name: 'store-shipping-methods', component: StoreShippingMethods, meta: { title: 'Shipping_Methods', permission: 'store_shipping_methods' } },

            { path: 'store/tax-rates', name: 'store-tax-rates', component: StoreTaxRates, meta: { title: 'Tax_Rates', permission: 'store_tax_rates' } },

            { path: 'store/coupons', name: 'store-coupons', component: StoreCoupons, meta: { title: 'Coupons', permission: 'store_coupons' } },

            { path: 'store/flash-sales', name: 'store-flash-sales', component: StoreFlashSales, meta: { title: 'Flash_Sales', permission: 'store_flash_sales' } },

            { path: 'store/reviews', name: 'store-reviews', component: StoreReviews, meta: { title: 'Product_Reviews', permission: 'store_reviews' } },

            { path: 'store/popups', name: 'store-popups', component: StorePopups, meta: { title: 'Popup_Messages', permission: 'store_popups' } },

            { path: 'store/quote-requests', name: 'store-quote-requests', component: StoreQuoteRequests, meta: { title: 'Quote_Requests', permission: 'store_quote_requests' } },

            { path: 'store/vehicle-leads', name: 'store-vehicle-leads', component: StoreVehicleLeads, meta: { title: 'Vehicle_Leads', permission: 'store_vehicle_leads' } },

            { path: 'store/returns', name: 'store-returns', component: StoreReturns, meta: { title: 'Page_Returns_Requests', permission: 'store_returns' } },

            { path: 'store/pages', name: 'store-pages', component: StorePages, meta: { title: 'Pages', permission: 'store_pages' } },

            { path: 'store/menus', name: 'store-menus', component: StoreMenus, meta: { title: 'Menus', permission: 'store_menus' } },

            { path: 'realestate/properties', name: 'realestate-properties', component: Properties, meta: { title: 'Properties', permission: 'realestate_properties' } },

            { path: 'realestate/properties/create', name: 'realestate-property-create', component: PropertyForm, meta: { title: 'Add_Property', permission: 'realestate_properties' } },

            { path: 'realestate/properties/edit/:id(\\d+)', name: 'realestate-property-edit', component: PropertyForm, meta: { title: 'Edit_Property', permission: 'realestate_properties' } },

            { path: 'realestate/inquiries', name: 'realestate-inquiries', component: PropertyInquiries, meta: { title: 'Property_Inquiries', permission: 'realestate_inquiries' } },

            { path: 'accounting-v2/dashboard', name: 'accounting-v2-dashboard', component: AccountingDashboard, meta: { title: 'Page_Accounting_Dashboard', permission: 'accounting_dashboard' } },

            { path: 'accounting-v2/chart-of-accounts', name: 'accounting-v2-coa', component: ChartOfAccounts, meta: { title: 'Chart_of_Accounts_Title', permission: 'chart_of_accounts' } },

            { path: 'accounting-v2/journal-entries', name: 'accounting-v2-journal-entries', component: JournalEntries, meta: { title: 'Journal_Entries_Link', permission: 'journal_entries' } },

            { path: 'accounting-v2/reports/trial-balance', name: 'accounting-v2-trial-balance', component: TrialBalance, meta: { title: 'Trial_Balance_Link', permission: 'trial_balance' } },

            { path: 'accounting-v2/reports/profit-and-loss', name: 'accounting-v2-profit-loss', component: ProfitAndLossV2, meta: { title: 'ProfitandLoss', permission: 'accounting_profit_loss' } },

            { path: 'accounting-v2/reports/balance-sheet', name: 'accounting-v2-balance-sheet', component: BalanceSheet, meta: { title: 'Balance_Sheet_Link', permission: 'balance_sheet' } },

            { path: 'accounting-v2/reports/tax-report', name: 'accounting-v2-tax-report', component: TaxReportV2, meta: { title: 'Tax_Summary_Report', permission: 'accounting_tax_report' } },

            { path: 'accounting-v2/reports/reconciliation', name: 'accounting-v2-reconciliation', component: ReconciliationV2, meta: { title: 'Reconciliation', permission: 'accounting_reconciliation' } },

            // Protocol & Requests
            { path: 'protocol/requests', name: 'protocol-requests', component: ProtocolRequests, meta: { title: 'Pt_Protocol', permission: ['protocol_view', 'protocol_manage'] } },
            { path: 'protocol/requests/:id(\\d+)', name: 'protocol-request-details', component: ProtocolRequestDetails, meta: { title: 'Pt_Request', permission: ['protocol_view', 'protocol_manage'] } },
            { path: 'protocol/desks', name: 'protocol-desks', component: ProtocolDesks, meta: { title: 'Page_Protocol_Desks', permission: 'protocol_manage' } },
            // Client Portal section — core (no plan key); per-module plan gating lives in PortalSettings.
            { path: 'client-portal/dashboard', name: 'client-portal-dashboard', component: PortalDashboard, meta: { title: 'Portal_Dashboard', permission: 'portal_accounts' } },
            { path: 'client-portal/accounts', name: 'client-portal-accounts', component: PortalAccounts, meta: { title: 'Portal_Accounts', permission: 'portal_accounts' } },
            { path: 'client-portal/deletions', name: 'client-portal-deletions', component: PortalDeletionLog, meta: { title: 'Portal_Deletion_Log', permission: 'portal_accounts' } },
            { path: 'client-portal/settings', name: 'client-portal-settings', component: PortalSettings, meta: { title: 'Portal_Settings', permission: 'portal_settings' } },
            { path: 'client-portal/languages', name: 'client-portal-languages', component: PortalLanguages, meta: { title: 'Portal_Languages', permission: 'portal_settings' } },
            { path: 'client-portal/translations/:locale', name: 'client-portal-translations', component: PortalTranslations, meta: { title: 'Portal_Translations', permission: 'portal_settings' } },
            { path: 'meeting/dashboard', name: 'meeting-dashboard', component: MeetingDashboard, meta: { title: 'Page_Meeting_Dashboard', permission: 'meeting' } },

            { path: 'meeting/details/:id(\\d+)', name: 'meeting-details', component: MeetingDetails, meta: { title: 'Meeting_Details', permission: 'meeting' } },

            { path: 'meeting/calendar', name: 'meeting-calendar', component: MeetingCalendar, meta: { title: 'Page_Meeting_Calendar', permission: 'meeting' } },

            { path: 'meeting/reports', name: 'meeting-reports', component: MeetingReports, meta: { title: 'Page_Meeting_Reports', permission: 'meeting_report' } },

            { path: 'recruit/dashboard', name: 'recruit-dashboard', component: RecruitDashboard, meta: { title: 'Page_Recruit_Dashboard', permission: 'recruit_job' } },

            { path: 'recruit/jobs', name: 'recruit-jobs', component: RecruitJobs, meta: { title: 'Jobs', permission: 'recruit_job' } },

            { path: 'recruit/candidates', name: 'recruit-candidates', component: RecruitCandidates, meta: { title: 'Candidates', permission: 'recruit_candidate' } },

            { path: 'recruit/applications', name: 'recruit-applications', component: RecruitApplications, meta: { title: 'Applications', permission: 'recruit_application' } },

            { path: 'recruit/interviews', name: 'recruit-interviews', component: RecruitInterviews, meta: { title: 'Interviews', permission: 'recruit_interview' } },

            { path: 'recruit/reports', name: 'recruit-reports', component: RecruitReports, meta: { title: 'Page_Recruit_Reports', permission: 'recruit_job' } },

            { path: 'commissions/programs', name: 'commission-programs', component: CommissionPrograms, meta: { title: 'Commission_Programs', permission: 'commissions_view' } },

            { path: 'commissions/agents', name: 'commission-agents', component: SalesAgents, meta: { title: 'Sales_Agents', permission: 'commissions_view' } },

            { path: 'commissions/rules', name: 'commission-rules', component: CommissionRules, meta: { title: 'Commission_Rules', permission: 'commissions_view' } },

            { path: 'commissions/receipts', name: 'commission-receipts', component: CommissionReceipts, meta: { title: 'Commission_Receipts', permission: 'commissions_view' } },

            { path: 'commissions/report', name: 'commission-report', component: CommissionReport, meta: { title: 'Commission_Report', permission: 'commissions_view' } },
            { path: 'commissions/guide', name: 'commission-guide', component: CommissionGuide, meta: { title: 'Commission_Guide', permission: 'commissions_view' } },

            { path: 'bookings', name: 'bookings', component: Bookings, meta: { title: 'Bookings', permission: 'bookings' } },

            { path: 'bookings/create', name: 'booking-create', component: BookingForm, meta: { title: 'Create_Booking', permission: 'bookings' } },

            { path: 'bookings/:id(\\d+)/edit', name: 'booking-edit', component: BookingForm, meta: { title: 'Edit_Booking', permission: 'bookings' } },

            { path: 'kitchen-display', name: 'kitchen-display', component: KitchenDisplay, meta: { title: 'KitchenDisplay', permission: 'kitchen_display_view', keepSignedIn: true } },

            { path: 'bookings/trays', name: 'booking-trays', component: Trays, meta: { title: 'Trays', permission: 'trays' } },
            { path: 'bookings/settings', name: 'booking-settings', component: BookingSettings, meta: { title: 'Bkm_Settings', permission: 'bookings_settings' } },
            { path: 'bookings/services', name: 'booking-services', component: BookingServices, meta: { title: 'Bkm_Services', permission: 'bookings_services' } },
            { path: 'bookings/staff', name: 'booking-staff', component: BookingStaff, meta: { title: 'Bkm_Staff_Hours', permission: 'bookings_staff' } },
            { path: 'bookings/requests', name: 'booking-requests', component: BookingRequests, meta: { title: 'Bkm_Requests', permission: 'bookings' } },
            { path: 'bookings/day', name: 'booking-day', component: BookingDay, meta: { title: 'Bkm_Day_View', permission: 'bookings' } },
            { path: 'bookings/dashboard', name: 'booking-dashboard', component: BookingDashboard, meta: { title: 'Bkm_Dashboard', permission: ['bookings', 'bookings_reports'] } },
            { path: 'bookings/reports', name: 'booking-reports', component: BookingReports, meta: { title: 'Bkm_Reports', permission: 'bookings_reports' } },
            { path: 'bookings/guide', name: 'booking-guide', component: BookingGuide, meta: { title: 'Bkm_Guide', permission: ['bookings', 'bookings_settings', 'bookings_services', 'bookings_staff', 'bookings_reports'] } },

            { path: 'meetings', name: 'meetings', component: Meetings, meta: { title: 'Meetings', permission: 'meeting' } },

            { path: 'kb', name: 'kb', component: KnowledgeBase, meta: { title: 'Knowledge_Base', permission: 'knowledge_base_view' } },




            { path: 'kb/articles/:id', name: 'kb-article', component: KnowledgeBaseArticle, meta: { title: 'Article', permission: 'knowledge_base_view', source: 'help' } },
            { path: 'kb/team-articles/:id', name: 'kb-team-article', component: KnowledgeBaseArticle, meta: { title: 'Article', permission: 'knowledge_base_view', source: 'team' } },

            { path: 'projects/dashboard', name: 'projects-dashboard', component: ProjectsDashboard, meta: { title: 'Page_Projects_Dashboard', permission: 'projects' } },

            { path: 'projects/milestones', name: 'project-milestones', component: ProjectMilestones, meta: { title: 'Milestones', permission: 'project_milestones' } },

            { path: 'projects/timesheets', name: 'project-timesheets', component: ProjectTimesheets, meta: { title: 'Timesheets', permission: 'project_timesheets' } },

            { path: 'projects/reports', name: 'project-reports', component: ProjectReports, meta: { title: 'Project Reports', permission: 'project_reports' } },

            { path: 'tasks/board', name: 'task-board', component: TaskBoard, meta: { title: 'Pm_Task_Board', permission: 'tasks' } },

            { path: 'projects', name: 'projects', component: Projects, meta: { title: 'Projects', permission: 'projects' } },

            { path: 'projects/create', name: 'project-create', component: ProjectForm, meta: { title: 'Page_Add_Project', permission: 'projects' } },

            { path: 'projects/:id/edit', name: 'project-edit', component: ProjectForm, meta: { title: 'Edit_Project', permission: 'projects' } },

            { path: 'tasks', name: 'tasks', component: Tasks, meta: { title: 'Tasks', permission: 'tasks' } },

            { path: 'tasks/create', name: 'task-create', component: TaskForm, meta: { title: 'Page_Add_Task', permission: 'tasks' } },

            { path: 'tasks/:id/edit', name: 'task-edit', component: TaskForm, meta: { title: 'Edit_Task', permission: 'tasks' } },

            { path: 'promotions', name: 'promotions', component: Promotions, meta: { title: 'Promotions', permission: 'promotion' } },

            { path: 'promotions/create', name: 'promotion-create', component: PromotionForm, meta: { title: 'Page_Add_Promotion', permission: 'promotion' } },

            { path: 'promotions/:id(\\d+)/edit', name: 'promotion-edit', component: PromotionForm, meta: { title: 'EditPromotion', permission: 'promotion' } },

            { path: 'promotions/usages', name: 'promotion-usages', component: PromotionUsages, meta: { title: 'PromotionUsageReport', permission: 'promotion' } },

            { path: 'contracts', name: 'contracts', component: Contracts, meta: { title: 'Contracts', permission: 'contracts' } },

            { path: 'contracts/create', name: 'contract-create', component: ContractForm, meta: { title: 'Page_Add_Contract', permission: 'contracts' } },

            { path: 'contracts/:id/edit', name: 'contract-edit', component: ContractForm, meta: { title: 'Edit_Contract', permission: 'contracts' } },

            { path: 'contracts/:id(\\d+)', name: 'contract-view', component: ContractView, meta: { title: 'Page_View_Contract', permission: 'contracts' } },

            { path: 'fleet/dashboard', name: 'fleet-dashboard', component: FleetDashboard, meta: { title: 'Page_Fleet_Dashboard', permission: 'fleet_vehicles_view' } },

            { path: 'fleet/vehicles', name: 'fleet-vehicles', component: Vehicles, meta: { title: 'Vehicles', permission: 'fleet_vehicles_view' } },

            { path: 'fleet/vehicles/create', name: 'fleet-vehicle-create', component: VehicleForm, meta: { title: 'Fl_Add_Vehicle', permission: 'fleet_vehicles_add' } },

            { path: 'fleet/vehicles/:id(\\d+)/edit', name: 'fleet-vehicle-edit', component: VehicleForm, meta: { title: 'Fl_Edit_Vehicle', permission: 'fleet_vehicles_edit' } },

            { path: 'fleet/vehicles/:id(\\d+)', name: 'fleet-vehicle', component: VehicleDetails, meta: { title: 'Vehicle', permission: 'fleet_vehicles_view' } },

            { path: 'fleet/maintenance', name: 'fleet-maintenance', component: FleetMaintenance, meta: { title: 'Page_Fleet_Maintenance', permission: 'fleet_maintenance' } },

            { path: 'fleet/fuel-logs', name: 'fleet-fuel-logs', component: FleetFuelLogs, meta: { title: 'Fl_Fuel_Logs', permission: 'fleet_fuel' } },

            { path: 'fleet/assignments', name: 'fleet-assignments', component: FleetAssignments, meta: { title: 'Page_Vehicle_Assignments', permission: 'fleet_assignments' } },

            { path: 'fleet/reports', name: 'fleet-reports', component: FleetReports, meta: { title: 'Fleet Reports', permission: 'fleet_reports' } },

            { path: 'hospital/dashboard', name: 'hospital-dashboard', component: HospitalDashboard, meta: { title: 'Page_Hospital_Dashboard', permission: 'hms_dashboard' } },

            { path: 'hospital/patients', name: 'hospital-patients', component: HospitalPatients, meta: { title: 'Patients', permission: 'hms_patients_view' } },

            { path: 'hospital/patients/create', name: 'hospital-patient-create', component: PatientForm, meta: { title: 'Hm_Register_Patient', permission: 'hms_patients_add' } },

            { path: 'hospital/patients/:id(\\d+)/edit', name: 'hospital-patient-edit', component: PatientForm, meta: { title: 'Hm_Edit_Patient', permission: 'hms_patients_edit' } },

            { path: 'hospital/patients/:id(\\d+)', name: 'hospital-patient', component: PatientDetails, meta: { title: 'Page_Patient_Record', permission: 'hms_patients_view' } },

            { path: 'hospital/doctors', name: 'hospital-doctors', component: HospitalDoctors, meta: { title: 'Doctors', permission: 'hms_doctors' } },

            { path: 'hospital/departments', name: 'hospital-departments', component: HospitalDepartments, meta: { title: 'Page_Hospital_Departments', permission: 'hms_departments' } },

            { path: 'hospital/appointments', name: 'hospital-appointments', component: HospitalAppointments, meta: { title: 'Appointments', permission: 'hms_appointments' } },

            { path: 'hospital/visits', name: 'hospital-visits', component: HospitalVisits, meta: { title: 'Consultations', permission: 'hms_visits' } },

            { path: 'hospital/visits/create', name: 'hospital-visit-create', component: HospitalVisitForm, meta: { title: 'Hm_New_Consultation', permission: 'hms_visits' } },

            { path: 'hospital/visits/:id(\\d+)/edit', name: 'hospital-visit-edit', component: HospitalVisitForm, meta: { title: 'Hm_Edit_Consultation', permission: 'hms_visits' } },

            { path: 'hospital/wards', name: 'hospital-wards', component: HospitalWards, meta: { title: 'Hm_Wards_And_Beds', permission: 'hms_wards' } },

            { path: 'hospital/admissions', name: 'hospital-admissions', component: HospitalAdmissions, meta: { title: 'Admissions', permission: 'hms_admissions' } },

            { path: 'hospital/lab-tests', name: 'hospital-lab-tests', component: HospitalLabTests, meta: { title: 'Hm_Lab_Test_Catalogue', permission: 'hms_lab' } },

            { path: 'hospital/lab-orders', name: 'hospital-lab-orders', component: HospitalLabOrders, meta: { title: 'Hm_Lab_Orders', permission: 'hms_lab' } },

            { path: 'hospital/invoices', name: 'hospital-invoices', component: HospitalInvoices, meta: { title: 'Page_Hospital_Billing', permission: 'hms_billing' } },

            { path: 'hospital/reports', name: 'hospital-reports', component: HospitalReports, meta: { title: 'Hospital Reports', permission: 'hms_reports' } },

            { path: 'school/dashboard', name: 'school-dashboard', component: SchoolDashboard, meta: { title: 'Page_School_Dashboard', permission: 'school_dashboard' } },

            { path: 'school/students', name: 'school-students', component: SchoolStudents, meta: { title: 'Students', permission: 'school_students_view' } },

            { path: 'school/students/create', name: 'school-student-create', component: SchoolStudentForm, meta: { title: 'Sc_Admit_Student', permission: 'school_students_add' } },

            { path: 'school/students/:id(\\d+)/edit', name: 'school-student-edit', component: SchoolStudentForm, meta: { title: 'Sc_Edit_Student', permission: 'school_students_edit' } },

            { path: 'school/students/:id(\\d+)', name: 'school-student', component: SchoolStudentDetails, meta: { title: 'Page_Student_Record', permission: 'school_students_view' } },

            { path: 'school/teachers', name: 'school-teachers', component: SchoolTeachers, meta: { title: 'Teachers', permission: 'school_teachers' } },

            { path: 'school/academics', name: 'school-academics', component: SchoolAcademics, meta: { title: 'Sc_Academic_Setup', permission: 'school_academics' } },

            { path: 'school/enrollments', name: 'school-enrollments', component: SchoolEnrollments, meta: { title: 'Enrolment', permission: 'school_enrollment' } },

            { path: 'school/attendance', name: 'school-attendance', component: SchoolAttendance, meta: { title: 'Attendance', permission: 'school_attendance' } },

            { path: 'school/timetable', name: 'school-timetable', component: SchoolTimetable, meta: { title: 'Timetable', permission: 'school_timetable' } },

            { path: 'school/exams', name: 'school-exams', component: SchoolExams, meta: { title: 'Sc_Exams_And_Results', permission: 'school_exams' } },

            { path: 'school/fees', name: 'school-fees', component: SchoolFees, meta: { title: 'Page_School_Fees', permission: 'school_fees' } },

            { path: 'school/reports', name: 'school-reports', component: SchoolReports, meta: { title: 'School Reports', permission: 'school_reports' } },

            // Rental Management
            { path: 'rental/dashboard', name: 'rental-dashboard', component: RentalDashboard, meta: { title: 'Rental_Dashboard', permission: 'rnt_dashboard' } },
            { path: 'rental/items', name: 'rental-items', component: RentalItems, meta: { title: 'Rental_Items', permission: 'rnt_items_view' } },
            { path: 'rental/items/create', name: 'rental-item-create', component: RentalItemForm, meta: { title: 'Page_Add_Rental_Item', permission: 'rnt_items_add' } },
            { path: 'rental/items/:id(\\d+)/edit', name: 'rental-item-edit', component: RentalItemForm, meta: { title: 'Page_Edit_Rental_Item', permission: 'rnt_items_edit' } },
            { path: 'rental/items/:id(\\d+)', name: 'rental-item', component: RentalItemDetails, meta: { title: 'Page_Rental_Item', permission: 'rnt_items_view' } },
            { path: 'rental/customers', name: 'rental-customers', component: RentalCustomers, meta: { title: 'Rental_Customers', permission: 'rnt_customers_view' } },
            { path: 'rental/customers/create', name: 'rental-customer-create', component: RentalCustomerForm, meta: { title: 'Page_Add_Rental_Customer', permission: 'rnt_customers_add' } },
            { path: 'rental/customers/:id(\\d+)/edit', name: 'rental-customer-edit', component: RentalCustomerForm, meta: { title: 'Page_Edit_Rental_Customer', permission: 'rnt_customers_edit' } },
            { path: 'rental/customers/:id(\\d+)', name: 'rental-customer', component: RentalCustomerDetails, meta: { title: 'Page_Rental_Customer', permission: 'rnt_customers_view' } },
            { path: 'rental/contracts', name: 'rental-contracts', component: RentalContracts, meta: { title: 'Rental_Contracts', permission: 'rnt_contracts_view' } },
            { path: 'rental/contracts/create', name: 'rental-contract-create', component: RentalContractForm, meta: { title: 'Page_New_Rental_Contract', permission: 'rnt_contracts_add' } },
            { path: 'rental/contracts/:id(\\d+)/edit', name: 'rental-contract-edit', component: RentalContractForm, meta: { title: 'Page_Edit_Rental_Contract', permission: 'rnt_contracts_edit' } },
            { path: 'rental/contracts/:id(\\d+)', name: 'rental-contract', component: RentalContractDetails, meta: { title: 'Page_Rental_Contract', permission: 'rnt_contracts_view' } },
            { path: 'rental/agreement-templates', name: 'rental-agreement-templates', component: RentalAgreementTemplates, meta: { title: 'Rental_Agreement_Templates', permission: 'rnt_settings' } },
            { path: 'rental/availability', name: 'rental-availability', component: RentalAvailability, meta: { title: 'Page_Rental_Availability', permission: 'rnt_availability' } },
            { path: 'rental/calendar', name: 'rental-calendar', component: RentalCalendar, meta: { title: 'Page_Rental_Calendar', permission: 'rnt_availability' } },
            { path: 'rental/reservations', name: 'rental-reservations', component: RentalReservations, meta: { title: 'Rental_Reservations', permission: 'rnt_reservations_view' } },
            { path: 'rental/reservations/create', name: 'rental-reservation-create', component: RentalReservationForm, meta: { title: 'Page_New_Reservation', permission: 'rnt_reservations_add' } },
            { path: 'rental/reservations/:id(\\d+)/edit', name: 'rental-reservation-edit', component: RentalReservationForm, meta: { title: 'Page_Edit_Reservation', permission: 'rnt_reservations_edit' } },
            { path: 'rental/reservations/:id(\\d+)', name: 'rental-reservation', component: RentalReservationDetails, meta: { title: 'Rental_Reservation', permission: 'rnt_reservations_view' } },
            { path: 'rental/invoices', name: 'rental-invoices', component: RentalInvoices, meta: { title: 'Rental_Invoices', permission: 'rnt_billing_view' } },
            { path: 'rental/invoices/:id(\\d+)', name: 'rental-invoice', component: RentalInvoiceDetails, meta: { title: 'Page_Rental_Invoice', permission: 'rnt_billing_view' } },
            { path: 'rental/payments', name: 'rental-payments', component: RentalPayments, meta: { title: 'Rental_Payments', permission: 'rnt_billing_view' } },
            { path: 'rental/contracts/:id(\\d+)/handover/:type(checkout|checkin)', name: 'rental-handover', component: RentalHandover, meta: { title: 'Page_Rental_Handover', permission: 'rnt_handover' } },
            { path: 'rental/maintenance', name: 'rental-maintenance', component: RentalMaintenance, meta: { title: 'Page_Rental_Maintenance', permission: 'rnt_maintenance' } },
            { path: 'rental/expenses', name: 'rental-expenses', component: RentalExpenses, meta: { title: 'Rental_Expenses', permission: 'rnt_expenses' } },
            { path: 'rental/reports', name: 'rental-reports', component: RentalReports, meta: { title: 'Rental_Reports', permission: 'rnt_reports' } },
            { path: 'rental/customers/:id(\\d+)/statement', name: 'rental-statement', component: RentalStatement, meta: { title: 'Page_Customer_Statement', permission: 'rnt_reports' } },
            { path: 'rental/categories', name: 'rental-categories', component: RentalCategories, meta: { title: 'Rental_Categories', permission: 'rnt_categories' } },
            { path: 'rental/settings', name: 'rental-settings', component: RentalSettings, meta: { title: 'Rental_Settings', permission: 'rnt_settings' } },

            // Restaurant Management
            { path: 'restaurant/floor', name: 'restaurant-floor', component: RestaurantFloorPlan, meta: { title: 'Rst_Floor_Plan', permission: 'rst_floor' } },
            { path: 'restaurant/orders', name: 'restaurant-orders', component: RestaurantOrders, meta: { title: 'Rst_Orders', permission: 'rst_orders_take' } },
            { path: 'restaurant/order/:id(\\d+)', name: 'restaurant-order', component: RestaurantOrderScreen, meta: { title: 'Rst_Order', permission: 'rst_orders_take' } },
            { path: 'restaurant/order/:id(\\d+)/bill', name: 'restaurant-bill', component: RestaurantBill, meta: { title: 'Rst_Bill', permission: 'rst_billing' } },
            { path: 'restaurant/dashboard', name: 'restaurant-dashboard', component: RestaurantDashboard, meta: { title: 'Rst_Dashboard', permission: 'rst_dashboard' } },
            { path: 'restaurant/guide', name: 'restaurant-guide', component: RestaurantGuide, meta: { title: 'Rst_Guide', permission: ['rst_dashboard', 'rst_floor', 'rst_orders_take', 'rst_orders_void', 'rst_discounts', 'rst_billing', 'rst_menu', 'rst_reservations', 'rst_reports', 'rst_setup', 'rst_settings', 'rst_kitchen'] } },
            { path: 'restaurant/reports', name: 'restaurant-reports', component: RestaurantReports, meta: { title: 'Rst_Reports', permission: 'rst_reports' } },
            { path: 'restaurant/reservations', name: 'restaurant-reservations', component: RestaurantReservations, meta: { title: 'Rst_Reservations', permission: 'rst_reservations' } },
            { path: 'restaurant/kitchen', name: 'restaurant-kitchen', component: RestaurantKitchen, meta: { title: 'Rst_Kitchen', permission: 'rst_kitchen', keepSignedIn: true } },
            { path: 'restaurant/menu', name: 'restaurant-menu', component: RestaurantMenuSetup, meta: { title: 'Rst_Menu', permission: 'rst_menu' } },
            { path: 'restaurant/setup/floor', name: 'restaurant-floor-setup', component: RestaurantFloorSetup, meta: { title: 'Rst_Floor_Setup', permission: 'rst_setup' } },
            { path: 'restaurant/settings', name: 'restaurant-settings', component: RestaurantSettings, meta: { title: 'Rst_Settings', permission: 'rst_settings' } },

            // CRM: Leads & Sales Pipeline
            { path: 'crm/dashboard', name: 'crm-dashboard', component: CrmDashboard, meta: { title: 'Crm_Dashboard', permission: 'crm_dashboard' } },
            { path: 'crm/leads', name: 'crm-leads', component: CrmLeads, meta: { title: 'Crm_Leads', permission: 'crm_leads_view' } },
            { path: 'crm/leads/create', name: 'crm-lead-create', component: CrmLeadForm, meta: { title: 'Page_New_Lead', permission: 'crm_leads_add' } },
            { path: 'crm/leads/import', name: 'crm-lead-import', component: CrmLeadImport, meta: { title: 'Page_Import_Leads', permission: 'crm_import_export' } },
            { path: 'crm/leads/:id(\\d+)/edit', name: 'crm-lead-edit', component: CrmLeadForm, meta: { title: 'Page_Edit_Lead', permission: 'crm_leads_edit' } },
            { path: 'crm/leads/:id(\\d+)', name: 'crm-lead', component: CrmLeadDetails, meta: { title: 'Lead', permission: 'crm_leads_view' } },
            { path: 'crm/settings', name: 'crm-settings', component: CrmSettings, meta: { title: 'Crm_Settings', permission: 'crm_settings' } },
            { path: 'crm/customers', name: 'crm-customers', component: CrmCustomers, meta: { title: 'Page_CRM_Customers', permission: 'crm_customers_view' } },
            { path: 'crm/customers/:id(\\d+)', name: 'crm-customer', component: CrmCustomer360, meta: { title: 'Page_Customer_360', permission: 'crm_customers_view' } },
            { path: 'crm/reports', name: 'crm-reports', component: CrmReports, meta: { title: 'Page_CRM_Reports', permission: 'crm_reports' } },
            { path: 'crm/targets', name: 'crm-targets', component: CrmTargets, meta: { title: 'Page_Sales_Targets', permission: 'crm_targets' } },
            { path: 'crm/web-forms', name: 'crm-web-forms', component: CrmWebForms, meta: { title: 'Page_Web_Forms', permission: 'crm_web_forms' } },
            { path: 'crm/inbox', name: 'crm-inbox', component: CrmInbox, meta: { title: 'Page_CRM_Inbox', permission: 'crm_messages' } },
            { path: 'crm/my-day', name: 'crm-my-day', component: CrmMyDay, meta: { title: 'Crm_My_Day', permission: 'crm_activities_view' } },
            { path: 'crm/activities', name: 'crm-activities', component: CrmActivities, meta: { title: 'Crm_Activities', permission: 'crm_activities_view' } },
            { path: 'crm/pipeline', name: 'crm-pipeline', component: CrmPipelineBoard, meta: { title: 'Pipeline', permission: 'crm_deals_view' } },
            { path: 'crm/deals', name: 'crm-deals', component: CrmDeals, meta: { title: 'Crm_Deals', permission: 'crm_deals_view' } },
            { path: 'crm/deals/create', name: 'crm-deal-create', component: CrmDealForm, meta: { title: 'Page_New_Deal', permission: 'crm_deals_add' } },
            { path: 'crm/deals/:id(\\d+)/edit', name: 'crm-deal-edit', component: CrmDealForm, meta: { title: 'Page_Edit_Deal', permission: 'crm_deals_edit' } },
            { path: 'crm/deals/:id(\\d+)', name: 'crm-deal', component: CrmDealDetails, meta: { title: 'Crm_Deal', permission: 'crm_deals_view' } },

            { path: 'hrm/employees', name: 'employees', component: Employees, meta: { title: 'Employees', permission: 'view_employee' } },

            { path: 'hrm/employees/create', name: 'employee-create', component: EmployeeForm, meta: { title: 'Add_Employee', permission: 'add_employee' } },

            { path: 'hrm/employees/:id/edit', name: 'employee-edit', component: EmployeeForm, meta: { title: 'edit_employee', permission: 'edit_employee' } },

            { path: 'hrm/employees/:id(\\d+)/details', name: 'employee-details', component: EmployeeDetails, meta: { title: 'Employee_Details', permission: 'view_employee' } },

            { path: 'hrm/attendance', name: 'attendance', component: Attendance, meta: { title: 'Attendance', permission: 'attendance' } },

            { path: 'hrm/leaves', name: 'leaves', component: Leaves, meta: { title: 'Page_Leave_Requests', permission: 'leave' } },

            { path: 'hrm/payrolls', name: 'payrolls', component: Payrolls, meta: { title: 'Payroll', permission: 'payroll' } },

            { path: 'marketing/dashboard', name: 'marketing-dashboard', component: MarketingDashboard, meta: { title: 'Marketing_Dashboard', permission: 'marketing_dashboard' } },

            { path: 'marketing/campaigns', name: 'marketing-campaigns', component: Campaigns, meta: { title: 'Campaigns', permission: 'marketing_campaigns' } },

            { path: 'marketing/campaigns/create', name: 'marketing-campaign-create', component: CampaignForm, meta: { title: 'Create_Campaign', permission: 'marketing_campaigns' } },

            { path: 'marketing/campaigns/:id/edit', name: 'marketing-campaign-edit', component: CampaignForm, meta: { title: 'Edit_Campaign', permission: 'marketing_campaigns' } },

            { path: 'marketing/campaigns/:id', name: 'marketing-campaign-details', component: CampaignDetails, meta: { title: 'Campaign_Details', permission: 'marketing_campaigns' } },

            { path: 'marketing/segments', name: 'marketing-segments', component: Segments, meta: { title: 'Customer_Segments', permission: 'marketing_segments' } },

            { path: 'marketing/templates/:type(sms|whatsapp|email)', name: 'marketing-templates', component: MarketingTemplates, meta: { title: 'Marketing_Templates', permission: 'marketing_templates' } },

            { path: 'marketing/reports', name: 'marketing-reports', component: MarketingReports, meta: { title: 'Marketing_Reports', permission: 'marketing_reports' } },

            { path: 'marketing/settings', name: 'marketing-settings', component: MarketingSettings, meta: { title: 'Marketing_Settings', permission: 'marketing_settings' } },

            { path: 'marketing/guide', name: 'marketing-guide', component: MarketingGuide, meta: { title: 'Mkg_Guide', permission: ['marketing_dashboard', 'marketing_campaigns', 'marketing_segments', 'marketing_templates', 'marketing_reports', 'marketing_settings'] } },

            { path: 'expenses', name: 'expenses', component: Expenses, meta: { title: 'Expenses', permission: 'expense_view' } },

            { path: 'expenses/create', name: 'expense-create', component: ExpenseForm, meta: { title: 'Create_Expense', permission: 'expense_add' } },

            { path: 'expenses/:id(\\d+)/edit', name: 'expense-edit', component: ExpenseForm, meta: { title: 'Edit_Expense', permission: 'expense_edit' } },

            { path: 'damages', name: 'damages', component: Damages, meta: { title: 'Damages', permission: 'damage_view' } },

            { path: 'damages/create', name: 'damage-create', component: DamageForm, meta: { title: 'Create_Damage', permission: 'damage_view' } },

            { path: 'damages/:id(\\d+)/edit', name: 'damage-edit', component: DamageForm, meta: { title: 'Edit_Damage', permission: 'adjustment_edit' } },

            { path: 'customers-without-login', name: 'customers-without-login', component: CustomersWithoutLogin, meta: { title: 'Customers_without_Login', permission: 'store_customers_without_login' } },

            { path: 'customers-with-login', name: 'customers-with-login', component: CustomersWithLogin, meta: { title: 'Customers_with_Login', permission: 'store_customers_with_login' } },

            { path: 'real-time-sales-counter', name: 'real-time-sales-counter', component: RealTimeSalesCounter, meta: { title: 'Real_time_Sales_Counter', permission: 'real_time_sales_counter' } },

            // Under settings/…: the tenant web catch-all excludes the "customer-display"
            // prefix (the public display screen is the Blade /customer-display route),
            // so /customer-display/setup was a Laravel 404 on a full page load.
            { path: 'settings/customer-display', name: 'customer-display-setup', component: CustomerDisplaySetup, meta: { title: 'Customer_Screen', permission: 'customer_display_screen_setup' } },
            { path: 'customer-display/setup', redirect: '/settings/customer-display' },

            { path: 'deposits', name: 'deposits', component: Deposits, meta: { title: 'Deposits', permission: 'deposit_view' } },

            { path: 'deposits/create', name: 'deposit-create', component: DepositForm, meta: { title: 'Create_deposit', permission: 'deposit_add' } },

            { path: 'deposits/:id/edit', name: 'deposit-edit', component: DepositForm, meta: { title: 'Page_Edit_Deposit', permission: 'deposit_edit' } },
            // --- ported: sales, purchases, quotations, sale-returns, purchase-returns, adjustments, transfers, shipments, documents ---

            { path: 'documents', name: 'documents', component: DocumentArchive, meta: { title: 'Document_Archive', permission: 'documents_view' } },

            { path: 'sales', name: 'sales', component: Sales, meta: { title: 'Sales', permission: 'Sales_view' } },

            { path: 'sales/create', name: 'sale-create', component: SaleForm, meta: { title: 'Page_Add_Sale', permission: 'Sales_add' } },

            { path: 'sales/import', name: 'sales-import', component: ImportSales, meta: { title: 'Import_Sales', permission: 'Sales_add' } },

            { path: 'sales/:id(\\d+)/edit', name: 'sale-edit', component: SaleForm, meta: { title: 'EditSale', permission: 'Sales_edit' } },

            { path: 'sales/from-quotation/:id(\\d+)', name: 'sale-from-quotation', component: SaleForm, meta: { title: 'CreateSale', permission: 'Sales_add', mode: 'convert' } },

            { path: 'sales/:id(\\d+)', name: 'sale-details', component: SaleDetails, meta: { title: 'SaleDetail', permission: 'Sales_view' } },

            { path: 'purchases', name: 'purchases', component: Purchases, meta: { title: 'Purchases', permission: 'Purchases_view' } },

            { path: 'purchases/create', name: 'purchase-create', component: PurchaseForm, meta: { title: 'Page_Add_Purchase', permission: 'Purchases_add' } },

            { path: 'purchases/import', name: 'purchases-import', component: ImportPurchases, meta: { title: 'Import_Purchases', permission: 'Purchases_add' } },

            { path: 'purchases/:id(\\d+)/edit', name: 'purchase-edit', component: PurchaseForm, meta: { title: 'EditPurchase', permission: 'Purchases_edit' } },

            { path: 'purchases/:id(\\d+)', name: 'purchase-details', component: PurchaseDetails, meta: { title: 'PurchaseDetail', permission: 'Purchases_view' } },

            { path: 'quotations', name: 'quotations', component: Quotations, meta: { title: 'Quotations', permission: 'Quotations_view' } },

            { path: 'quotations/create', name: 'quotation-create', component: QuotationForm, meta: { title: 'Page_Add_Quotation', permission: 'Quotations_add' } },

            { path: 'quotations/:id(\\d+)/edit', name: 'quotation-edit', component: QuotationForm, meta: { title: 'EditQuote', permission: 'Quotations_edit' } },

            { path: 'quotations/:id(\\d+)', name: 'quotation-details', component: QuotationDetails, meta: { title: 'Page_Quotation_Detail', permission: 'Quotations_view' } },

            { path: 'sale-returns', name: 'sale-returns', component: SaleReturns, meta: { title: 'Sales_Return', permission: 'Sale_Returns_view' } },

            { path: 'sale-returns/create/:saleId(\\d+)', name: 'sale-return-create', component: SaleReturnForm, meta: { title: 'CreateSaleReturn', permission: 'Sale_Returns_add' } },

            { path: 'sale-returns/:id(\\d+)/edit/:saleId(\\d+)', name: 'sale-return-edit', component: SaleReturnForm, meta: { title: 'EditSaleReturn', permission: 'Sale_Returns_edit' } },

            { path: 'sale-returns/:id(\\d+)', name: 'sale-return-details', component: SaleReturnDetails, meta: { title: 'Page_Sale_Return_Detail', permission: 'Sale_Returns_view' } },

            { path: 'purchase-returns', name: 'purchase-returns', component: PurchaseReturns, meta: { title: 'PurchasesReturn', permission: 'Purchase_Returns_view' } },

            { path: 'purchase-returns/create/:purchaseId(\\d+)', name: 'purchase-return-create', component: PurchaseReturnForm, meta: { title: 'CreatePurchaseReturn', permission: 'Purchase_Returns_add' } },

            { path: 'purchase-returns/:id(\\d+)/edit/:purchaseId(\\d+)', name: 'purchase-return-edit', component: PurchaseReturnForm, meta: { title: 'EditPurchaseReturn', permission: 'Purchase_Returns_edit' } },

            { path: 'purchase-returns/:id(\\d+)', name: 'purchase-return-details', component: PurchaseReturnDetails, meta: { title: 'Page_Purchase_Return_Detail', permission: 'Purchase_Returns_view' } },

            { path: 'adjustments', name: 'adjustments', component: Adjustments, meta: { title: 'Adjustments', permission: 'adjustment_view' } },

            { path: 'adjustments/create', name: 'adjustment-create', component: AdjustmentForm, meta: { title: 'CreateAdjustment', permission: 'adjustment_add' } },

            { path: 'adjustments/:id(\\d+)/edit', name: 'adjustment-edit', component: AdjustmentForm, meta: { title: 'Page_Edit_Adjustment', permission: 'adjustment_edit' } },

            { path: 'adjustments/:id(\\d+)', name: 'adjustment-details', component: AdjustmentDetails, meta: { title: 'AdjustmentDetail', permission: 'adjustment_view' } },

            { path: 'transfers', name: 'transfers', component: Transfers, meta: { title: 'Transfers', permission: 'transfer_view' } },

            { path: 'transfers/create', name: 'transfer-create', component: TransferForm, meta: { title: 'Create_Transfer', permission: 'transfer_add' } },

            { path: 'transfers/:id(\\d+)/edit', name: 'transfer-edit', component: TransferForm, meta: { title: 'EditTransfer', permission: 'transfer_edit' } },

            { path: 'transfers/:id(\\d+)', name: 'transfer-details', component: TransferDetails, meta: { title: 'TransferDetail', permission: 'transfer_view' } },

            { path: 'shipments', name: 'shipments', component: Shipments, meta: { title: 'Shipments', permission: 'shipment' } },
            { path: 'offline-sales', name: 'offline-sales', component: OfflineSales, meta: { title: 'Offline_Sales', permission: 'pos_offline_sales' } },
            { path: 'pos-voids', name: 'pos-voids', component: PosVoids, meta: { title: 'Pv_Voided_Items', permission: 'pos_void_items' } },
            { path: 'pos-terminals', name: 'pos-terminals', component: PosTerminals, meta: { title: 'Pd_Pos_Devices', permission: ['pos_terminals_manage', 'pos_terminals_transactions'] } },
            // --- ported: products, serial-numbers ---

            { path: 'products', name: 'products', component: Products, meta: { title: 'Products', permission: 'products_view' } },

            { path: 'products/count-stock', name: 'count-stock', component: CountStock, meta: { title: 'CountStock', permission: 'count_stock' } },
            // Customer price check: no permission on purpose — a tablet signs in
            // as a role with none and sees only names and selling prices.
            { path: 'price-check', name: 'price-check', component: PriceCheck, meta: { title: 'Price_Check' } },

            { path: 'products/batches', name: 'batches', component: Batches, meta: { title: 'Batches', permission: ['view_batches', 'batch_view'] } },

            // Software License Keys (plan feature license_keys; the API enforces it)
            { path: 'license-keys', name: 'license-keys', component: LicenseKeys, meta: { title: 'LicK_License_Keys', permission: 'license_keys_view' } },

            { path: 'products/import', name: 'products-import', component: ImportProducts, meta: { title: 'import_products', permission: 'product_import' } },

            { path: 'products/import-update', name: 'products-import-update', component: ImportProductsUpdate, meta: { title: 'Page_Import_Products_Update_Only', permission: 'product_import' } },

            { path: 'products/opening-stock-import', name: 'opening-stock-import', component: OpeningStockImport, meta: { title: 'Opening_Stock', permission: 'opening_stock_import' } },

            { path: 'products/barcode', name: 'products-barcode', component: Barcode, meta: { title: 'Page_Print_Barcode', permission: 'barcode_view' } },


            { path: 'products/create', name: 'product-create', component: ProductForm, meta: { title: 'Add_Product', permission: 'products_add' } },

            { path: 'products/:id(\\d+)/edit', name: 'product-edit', component: ProductForm, meta: { title: 'EditProduct', permission: 'products_edit' } },

            { path: 'serial-numbers', name: 'serial-numbers', component: SerialNumbers, meta: { title: 'Page_Serial_Numbers', permission: 'serial_numbers' } },

            { path: 'serial-numbers/:id(\\d+)', name: 'serial-number-details', component: SerialNumberDetails, meta: { title: 'Serial_Number', permission: 'serial_numbers' } },

            { path: 'products/:id(\\d+)', name: 'product-details', component: ProductDetails, meta: { title: 'ProductDetails', permission: 'products_view' } },
            // --- ported: settings/, woocommerce, shopify ---







            { path: 'settings/payment-gateway', name: 'payment-gateway', component: PaymentGateway, meta: { title: 'Payment_Gateway', permission: 'payment_gateway' } },

            { path: 'settings/mail', name: 'mail-settings', component: MailSettings, meta: { title: 'mail_settings', permission: 'mail_settings' } },

            { path: 'settings/login-devices', name: 'login-devices', component: LoginDevices, meta: { title: 'Page_Login_Devices', permission: 'login_device_management' } },

            { path: 'settings/audit-log', name: 'audit-log', component: AuditLog, meta: { title: 'Audit_Log', permission: 'audit_log' } },

            { path: 'settings/system-health', name: 'system-health', component: SystemHealth, meta: { title: 'System_Health', permission: 'system_health_view' } },

            { path: 'settings/sms-templates', name: 'sms-templates', component: SmsTemplates, meta: { title: 'SMS_Templates', permission: 'notification_template' } },

            { path: 'settings/email-templates', name: 'email-templates', component: EmailTemplates, meta: { title: 'Email_Templates', permission: 'notification_template' } },

            { path: 'settings/custom-fields', name: 'custom-fields', component: CustomFields, meta: { title: 'CustomFields' } },

            { path: 'settings/backup', name: 'backup', component: Backup, meta: { title: 'Backup', permission: 'backup' } },

            { path: 'settings/pos', name: 'pos-settings', component: PosSettings, meta: { title: 'POS_Settings', permission: 'pos_settings' } },

            { path: 'settings/appearance', name: 'appearance-settings', component: AppearanceSettings, meta: { title: 'Page_Appearance', permission: 'appearance_settings' } },

            { path: 'settings/pwa', name: 'pwa-settings', component: PwaSettings, meta: { title: 'PWA_Settings', permission: 'appearance_settings' } },
            { path: 'settings/mobile-app', name: 'mobile-app-settings', component: MobileAppSettings, meta: { title: 'Mobile App Settings', titleKey: 'Page_Mobile_App_Settings', permission: 'appearance_settings' } },


            { path: 'settings/sms', name: 'sms-settings', component: SmsSettings, meta: { title: 'sms_settings', permission: 'sms_settings' } },

            { path: 'settings/languages', name: 'languages', component: Languages, meta: { title: 'Languages', permission: 'translations_settings' } },

            { path: 'settings/translations/:locale', name: 'translations-view', component: TranslationsView, meta: { title: 'Translations', permission: 'translations_settings' } },

            { path: 'settings/webhooks', name: 'webhooks', component: Webhooks, meta: { title: 'Webhooks', permission: 'webhooks_view' } },

            { path: 'settings/pos-receipt', name: 'pos-receipt', component: PosReceipt, meta: { title: 'POS_Receipt', permission: 'pos_settings' } },

            { path: 'settings/modules', name: 'module-settings', component: ModuleSettings, meta: { title: 'module_settings', permission: 'module_settings' } },
            { path: 'settings/business-modules', name: 'business-modules', component: Modules, meta: { title: 'Modules', permission: ['business_modules', 'setting_system'] } },



            { path: 'settings/system', name: 'system-settings', component: SystemSettings, meta: { title: 'SystemSettings', permission: 'setting_system' } },

            { path: 'settings/quickbooks', name: 'quickbooks-sync', component: QuickBooksSync, meta: { title: 'Quickbooks_Sync', permission: 'quickbooks_settings' } },

            { path: 'settings/zapier', name: 'zapier-settings', component: ZapierSettings, meta: { title: 'Zapier', permission: 'zapier_settings' } },
            { path: 'settings/api', name: 'api-settings', component: ApiSettings, meta: { title: 'Api_Title', permission: 'api_keys' } },
            { path: 'settings/custom-domains', name: 'custom-domains', component: CustomDomains, meta: { title: 'Cd_Title', permission: 'custom_domains' } },

            { path: 'settings/zatca', name: 'zatca-settings', component: ZatcaSettings, meta: { title: 'Zatca_E_Invoicing', permission: 'zatca_settings' } },

            { path: 'woocommerce', name: 'woocommerce-settings', component: WooCommerceSettings, meta: { title: 'WooCommerce', permission: 'woocommerce_settings' } },

            { path: 'settings/warehouse-locations', redirect: '/warehouses' },
            // Shopify. Literal segments before `stores/:id`.
            { path: 'shopify', name: 'shopify', component: ShopifyDashboard, meta: { title: 'Shopify', permission: 'shopify_stores' } },
            { path: 'shopify/stores', name: 'shopify-stores', component: ShopifyStores, meta: { title: 'Shopify_Stores', permission: 'shopify_stores' } },
            { path: 'shopify/sync', name: 'shopify-sync', component: ShopifySyncCenter, meta: { title: 'Page_Sync_Centre', permission: 'shopify_sync' } },
            { path: 'shopify/mappings', name: 'shopify-mappings', component: ShopifyMappings, meta: { title: 'Page_Shopify_Mappings', permission: 'shopify_sync' } },
            { path: 'shopify/logs', name: 'shopify-logs', component: ShopifyLogs, meta: { title: 'Page_Shopify_Logs', permission: 'shopify_logs' } },
            { path: 'shopify/guide', name: 'shopify-guide', component: ShopifyGuidePage, meta: { title: 'Page_Shopify_Guide', permission: 'shopify_stores' } },
            { path: 'shopify/stores/:id', name: 'shopify-store-details', component: ShopifyStoreDetails, meta: { title: 'Page_Shopify_Store', permission: 'shopify_stores' } },

            // Phase 7 — SaaS-only settings pages (no stocky counterpart)
            { path: 'settings/fbr', name: 'fbr-settings', component: FbrSettings, meta: { title: 'FBR_Settings', permission: 'fbr_settings' } },
            { path: 'settings/agt', name: 'agt-settings', component: AgtSettings, meta: { title: 'Agt_Settings', permission: 'agt_settings' } },
            { path: 'settings/dgi', name: 'dgi-settings', component: DgiSettings, meta: { title: 'Dgi_Settings', permission: 'dgi_settings' } },
            { path: 'settings/webhooks/deliveries', name: 'webhook-deliveries', component: WebhookDeliveries, meta: { title: 'Webhook_Delivery_Logs', permission: 'webhooks_view' } },
            { path: 'settings/webhooks/incoming', name: 'webhook-incoming-logs', component: WebhookIncomingLogs, meta: { title: 'Page_Incoming_Webhook_Logs', permission: 'webhooks_view' } },
            // No settings/update page: platform updates are super-admin only
            // (the tenant update endpoints were removed from the API).
            { path: 'whatsapp/settings', name: 'whatsapp-settings', component: WhatsAppSettings, meta: { title: 'Page_WhatsApp_Settings', permission: 'whatsapp_settings' } },
            { path: 'whatsapp/templates', name: 'whatsapp-templates', component: WhatsAppTemplates, meta: { title: 'WhatsApp_Templates', permission: 'whatsapp_templates' } },
            { path: 'whatsapp/templates/create', name: 'whatsapp-template-create', component: WhatsAppTemplateForm, meta: { title: 'Page_New_WhatsApp_Template', permission: 'whatsapp_templates' } },
            { path: 'whatsapp/templates/:id(\\d+)/edit', name: 'whatsapp-template-edit', component: WhatsAppTemplateForm, meta: { title: 'Page_Edit_WhatsApp_Template', permission: 'whatsapp_templates' } },
            { path: 'whatsapp/logs', name: 'whatsapp-logs', component: WhatsAppLogs, meta: { title: 'Page_WhatsApp_Message_Logs', permission: 'whatsapp_logs' } },
            // Integrations Hub — card overview of every connector; visible with ANY connector permission.
            { path: 'integrations', name: 'integrations-hub', component: IntegrationsHub, meta: { title: 'Integrations', permission: ['woocommerce_settings', 'shopify_stores', 'shopify_sync', 'shopify_logs', 'salla_settings', 'prestashop_settings', 'opencart_settings', 'jumia_settings', 'printful_settings', 'quickbooks_settings', 'xero_settings', 'google_sheets_settings', 'mailchimp_settings', 'zapier_settings', 'api_keys', 'slack_settings', 'telegram_settings', 'whatsapp_settings', 'whatsapp_templates', 'whatsapp_logs', 'webhooks_view', 'courier_settings'] } },
            { path: 'integrations/slack', name: 'slack-settings', component: SlackSettings, meta: { title: 'Slack', permission: 'slack_settings' } },
            { path: 'integrations/telegram', name: 'telegram-settings', component: TelegramSettings, meta: { title: 'Telegram', permission: 'telegram_settings' } },
            { path: 'integrations/salla', name: 'salla-settings', component: SallaSettings, meta: { title: 'Salla', permission: 'salla_settings' } },
            { path: 'integrations/xero', name: 'xero-settings', component: XeroSettings, meta: { title: 'Xero', permission: 'xero_settings' } },
            { path: 'integrations/prestashop', name: 'prestashop-settings', component: PrestashopSettings, meta: { title: 'PrestaShop', permission: 'prestashop_settings' } },
            { path: 'integrations/opencart', name: 'opencart-settings', component: OpencartSettings, meta: { title: 'OpenCart', permission: 'opencart_settings' } },
            { path: 'integrations/opencart/guide', name: 'opencart-guide', component: OpencartGuide, meta: { title: 'Oc_G_Title', permission: 'opencart_settings' } },
            { path: 'integrations/opencart/logs', name: 'opencart-logs', component: OpencartLogs, meta: { title: 'Oc_L_Title', permission: 'opencart_settings' } },
            { path: 'integrations/google-sheets', name: 'google-sheets-settings', component: GoogleSheetsSettings, meta: { title: 'Google Sheets', permission: 'google_sheets_settings' } },
            { path: 'integrations/mailchimp', name: 'mailchimp-settings', component: MailchimpSettings, meta: { title: 'Mailchimp', permission: 'mailchimp_settings' } },
            { path: 'integrations/jumia', name: 'jumia-settings', component: JumiaSettings, meta: { title: 'Jumia', permission: 'jumia_settings' } },
            { path: 'integrations/printful', name: 'printful-settings', component: PrintfulSettings, meta: { title: 'Printful', permission: 'printful_settings' } },
            // Courier integration (plan feature 'couriers').
            { path: 'couriers/accounts', name: 'courier-accounts', component: CourierAccounts, meta: { title: 'Cr_Courier_Accounts', permission: 'courier_settings' } },
            { path: 'couriers/settings', name: 'courier-settings', component: CourierSettings, meta: { title: 'Cr_Courier_Settings', permission: 'courier_settings' } },
            { path: 'couriers/shipments', name: 'courier-shipments', component: CourierShipments, meta: { title: 'Cr_Courier_Shipments', permission: 'courier_shipments' } },
            { path: 'couriers/reports', name: 'courier-reports', component: CourierReports, meta: { title: 'Cr_Courier_Reports', permission: 'courier_reports' } },
            { path: 'couriers/logs', name: 'courier-logs', component: CourierLogs, meta: { title: 'Cr_Courier_Logs', permission: 'courier_settings' } },
            { path: 'couriers/guide', name: 'courier-guide', component: CourierGuide, meta: { title: 'Cr_Courier_Guide', permission: ['courier_settings', 'courier_shipments', 'courier_book'] } },
            // --- ported: profile ---

            { path: 'profile', name: 'profile', component: Profile, meta: { title: 'profil' } },
            // Phase 8 — SaaS-only: subscription billing (no Is_Active on the API, so
            // a tenant without a subscription can still reach it), support centre, 403.
            // Served under /subscription/…, NOT /billing/…: the tenant web catch-all
            // excludes the "billing" prefix because /billing/* belongs to the Blade
            // billing pages (routes/tenant_web.php), so /billing/current-plan 404s
            // on a full page load and /billing/checkout|history|success|failed land
            // on the Blade pages instead of these. Legacy /app/billing/* URLs (menu,
            // gateway return URLs) still arrive here via migratedRoutes.json.
            // ownerBypass: the API lets user #1 in without billing_view
            // (BillingApiController::authorizeBilling), so the guard does too.
            { path: 'subscription/current-plan', name: 'billing-current-plan', component: BillingCurrentPlan, meta: { title: 'Current_Plan', permission: 'billing_view', ownerBypass: true } },
            { path: 'subscription/change-plan', name: 'billing-change-plan', component: BillingChangePlan, meta: { title: 'Change_Plan', permission: 'billing_view', ownerBypass: true } },
            { path: 'subscription/checkout/:id(\\d+)', name: 'billing-checkout', component: BillingCheckout, meta: { title: 'Checkout', permission: 'billing_view', ownerBypass: true } },
            { path: 'subscription/success', name: 'billing-success', component: BillingResult, meta: { title: 'Payment', permission: 'billing_view', kind: 'success', ownerBypass: true } },
            { path: 'subscription/failed', name: 'billing-failed', component: BillingResult, meta: { title: 'Payment_Failed', permission: 'billing_view', kind: 'failed', ownerBypass: true } },
            { path: 'subscription/history', name: 'billing-history', component: BillingHistory, meta: { title: 'Page_Billing_History', permission: 'billing_view', kind: 'history', ownerBypass: true } },
            { path: 'subscription/invoices', name: 'billing-invoices', component: BillingHistory, meta: { title: 'Invoices', permission: 'billing_view', kind: 'invoices', ownerBypass: true } },
            { path: 'subscription/addons', name: 'billing-addons', component: BillingAddons, meta: { title: 'Addons_Title', permission: 'billing_view', ownerBypass: true } },
            // Old bookmarks of the pre-fix SPA paths.
            { path: 'billing/:rest(.*)*', redirect: to => '/subscription/' + (Array.isArray(to.params.rest) ? to.params.rest.join('/') : (to.params.rest || 'current-plan')) },
            { path: 'support/tickets', name: 'support-tickets', component: SupportTickets, meta: { title: 'Page_Support_Tickets', permission: 'support' } },
            { path: 'support/tickets/create', name: 'support-ticket-create', component: SupportTicketForm, meta: { title: 'Page_Create_Support_Ticket', permission: 'support' } },
            { path: 'support/tickets/:id(\\d+)', name: 'support-ticket-detail', component: SupportTicketDetail, meta: { title: 'Page_Support_Ticket', permission: 'support' } },
            { path: 'not-authorized', name: 'not-authorized', component: NotAuthorized, meta: { title: 'Page_403_Not_Authorized' } },
            // --- ported: /pos ---

            { path: ':pathMatch(.*)*', name: 'not-found', component: NotFound, meta: { title: 'Page_Not_found' } },
        ],
    },
];

const router = createRouter({
    // Served under /next/ by the existing /{vue?} catch-all (layouts/master.blade.php
    // includes layouts/next.blade.php for that prefix). Becomes '/' at cutover.
    history: createWebHistory('/'),
    routes,
});

// meta.title is a translation key. The tab title is an effect, so it follows the
// locale messages: the first navigation runs before loadLocale() has resolved,
// and switching language re-renders it without a navigation.
const titleKey = ref('');
watchEffect(() => {
    if (!titleKey.value) return;
    const translated = i18n.global.t(titleKey.value);
    const title = translated === titleKey.value ? titleKey.value.replace(/^Page_/, '').replace(/_/g, ' ') : translated;
    // Same suffix the legacy vue-meta titleTemplate used (next.blade.php).
    const suffix = window.__pageTitleSuffix || '';
    document.title = suffix ? `${title} | ${suffix}` : title;
});

router.beforeEach(async to => {
    progressStart();
    if (to.meta.title) titleKey.value = to.meta.title;
    if (to.meta.skipAuth) return true;

    const auth = useAuthStore();
    await auth.load();
    // `permission` may be a single key or a list of acceptable ones — some
    // pages are reachable under either of two permissions (e.g. batches under
    // view_batches OR batch_view), matching the sidebar gate and the backend.
    if (to.meta.permission) {
        const perms = Array.isArray(to.meta.permission) ? to.meta.permission : [to.meta.permission];
        // ownerBypass: routes whose API already admits the workspace owner
        // (user #1) without the permission — billing (authorizeBilling).
        const ownerOk = to.meta.ownerBypass && auth.user && Number(auth.user.id) === 1;
        if (!perms.some(p => auth.can(p)) && !ownerOk) {
            return { name: 'not-found' };
        }
    }
    // Cost-derived pages (profit reports) are off-limits to roles carrying
    // hide_product_cost, mirroring the sidebar's costData filter.
    if (to.meta.costData && !auth.canSeeCost) {
        return { name: 'not-found' };
    }
    // Pages of a module the admin switched off are unreachable even by direct
    // URL, mirroring the sidebar filter. (This backend ships no module_flags,
    // so every module resolves as enabled — kept for parity with stocky.)
    const moduleKey = moduleKeyForPath(to.path);
    if (moduleKey && !auth.moduleEnabled(moduleKey)) {
        return { name: 'not-found' };
    }
    return true;
});

// Covers both the guard and the lazy chunk load (afterEach fires only once the
// chunk resolved). onError catches failed chunks/guards so the bar never hangs.
router.afterEach(() => progressDone());
router.onError((error, to) => {
    progressDone();
    // Stale-deploy recovery: after a rebuild the old hashed chunks are deleted,
    // so navigating to a not-yet-visited section 404s on its dynamic import.
    // Reload the browser AT THE TARGET route — reloading in place (the
    // vite:preloadError fallback in main.js) looked like "navigation is stuck":
    // the page refreshed but stayed on the section the user was leaving.
    const msg = String((error && error.message) || '');
    if (/Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|ChunkLoadError/i.test(msg)) {
        if (sessionStorage.getItem('stocky-chunk-reload') === '1') return;
        sessionStorage.setItem('stocky-chunk-reload', '1');
        window.__stockyChunkRedirect = true;
        try {
            window.location.assign(router.resolve(to && to.fullPath ? to.fullPath : '/').href);
        } catch (e) {
            window.location.reload();
        }
    }
});

export default router;
