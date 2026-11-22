import { createRouter, createWebHistory } from 'vue-router';

/**
 * Portal routes (base /portal). Guest pages render the portal "gate" screen;
 * everything else sits inside AuthGate → PortalLayout (the portal shell).
 */
const router = createRouter({
  history: createWebHistory('/portal'),
  routes: [
    { path: '/login', name: 'PortalLogin', component: () => import('./views/Login.vue'), meta: { guest: true } },
    { path: '/set-password', name: 'PortalSetPassword', component: () => import('./views/SetPassword.vue'), meta: { guest: true } },
    {
      path: '/',
      component: () => import('./components/AuthGate.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          component: () => import('./layouts/PortalLayout.vue'),
          redirect: '/dashboard',
          children: [
            { path: 'dashboard', name: 'PortalDashboard', component: () => import('./views/Dashboard.vue') },
            { path: 'invoices', name: 'PortalInvoices', component: () => import('./views/Invoices.vue') },
            { path: 'invoices/:id', name: 'PortalInvoiceDetail', component: () => import('./views/InvoiceDetail.vue') },
            { path: 'payments', name: 'PortalPayments', component: () => import('./views/Payments.vue') },
            { path: 'statement', name: 'PortalStatement', component: () => import('./views/Statement.vue') },
            // Software License Keys (shown in the menu once the customer holds a key)
            { path: 'licenses', name: 'PortalLicenses', component: () => import('./views/Licenses.vue') },
            { path: 'quotations', name: 'PortalQuotations', component: () => import('./views/Quotations.vue') },
            { path: 'quotations/new', name: 'PortalQuotationRequest', component: () => import('./views/QuotationRequest.vue') },
            { path: 'quotations/:id', name: 'PortalQuotationDetail', component: () => import('./views/QuotationDetail.vue') },
            // SaaS: /requests is Protocol & Requests; the CRM (stocky's /requests) is /deals.
            { path: 'requests', name: 'PortalRequests', component: () => import('./views/Requests.vue') },
            { path: 'requests/new', name: 'PortalRequestNew', component: () => import('./views/RequestNew.vue') },
            { path: 'requests/:id(\\d+)', name: 'PortalRequestDetail', component: () => import('./views/RequestDetail.vue') },
            { path: 'deals', name: 'PortalDeals', component: () => import('./views/Deals.vue') },
            { path: 'deals/:id(\\d+)', name: 'PortalDealDetail', component: () => import('./views/DealDetail.vue') },
            { path: 'appointments', name: 'PortalAppointments', component: () => import('./views/Appointments.vue') },
            { path: 'appointments/new', name: 'PortalAppointmentBook', component: () => import('./views/AppointmentBook.vue') },
            { path: 'appointments/:id', name: 'PortalAppointmentDetail', component: () => import('./views/AppointmentDetail.vue') },
            // Booking Management: visits booked on the website or by staff.
            { path: 'bookings', name: 'PortalBookings', component: () => import('./views/Bookings.vue') },
            { path: 'bookings/:id(\\d+)', name: 'PortalBookingDetail', component: () => import('./views/BookingDetail.vue') },
            { path: 'contracts', name: 'PortalContracts', component: () => import('./views/Contracts.vue') },
            { path: 'contracts/:id', name: 'PortalContractDetail', component: () => import('./views/ContractDetail.vue') },
            { path: 'rentals', name: 'PortalRentals', component: () => import('./views/Rentals.vue') },
            { path: 'rentals/:id(\\d+)', name: 'PortalRentalDetail', component: () => import('./views/RentalDetail.vue') },
            { path: 'rental-invoices', name: 'PortalRentalInvoices', component: () => import('./views/RentalInvoices.vue') },
            { path: 'rental-requests', name: 'PortalRentalRequests', component: () => import('./views/RentalRequests.vue') },
            { path: 'help', name: 'PortalKnowledgeBase', component: () => import('./views/KnowledgeBase.vue') },
            { path: 'help/:slug', name: 'PortalArticle', component: () => import('./views/Article.vue') },
            { path: 'profile', name: 'PortalProfile', component: () => import('./views/Profile.vue') },
          ],
        },
      ],
    },
  ],
  scrollBehavior(to, from, saved) {
    return saved || { top: 0 };
  },
});

export default router;
