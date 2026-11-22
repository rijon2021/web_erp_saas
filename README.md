# Version 1.0 - 15-04-2026


# Version 1.1  - 23-04-2026

+ Added Custom Domain feature allowing tenants to connect and use their own domains with full DNS verification, SSL support

+ Added secure Central Database Backup tab in Super Settings for creating, listing, downloading, and deleting SQL backups with protected storage and secure access controls.

+ Added Logs & System Health section for centralized monitoring of system errors and tenant infrastructure status.

+ Added Default Language setting for visitors in General Settings, allowing administrators to define the default display language shown to guests before login.

+ Added Pending Payments page for tracking, reviewing, and managing all unpaid or awaiting-confirmation transactions in one centralized view.

+ Fixed various minor issues and improved overall system stability and performance.

🧰 Bug Fixes & Enhancements

+ Updated documentation for better clarity and setup guidance.

+ Resolved multiple minor bugs to improve performance and reliability.


# Version 1.2  - 30-06-2026


## 🚀 Super Admin

### ✨ New Features

* Added **Default Branding** for newly created tenants.
* Added **Reserved Subdomains** management.
* Added **Automatic Subscription & Trial Expiry Reminders** for tenants.
* Added **Demo Tenant Generator**.
* Added a **Support System** between tenants and the Super Admin.
* Added **Mollie Payment Gateway** integration.
* Added an option to **Enable/Disable Languages** from the dashboard for the language selector.
* Added support for **Multilingual Hero Images**.
* Added support for **Custom Fonts** on frontend layouts.
* Added a dedicated **Terms & Conditions** page.


## 🚀 Tenant

### ✨ New Features

* Enhanced the application to be pharmacy-ready by adding batch management and expiry date tracking for products.

* Added Progressive Web App (PWA)

* Added the ability to manage Email and SMS templates per language.

* Added Cash Drawer Auto-Open Integration using QZ Tray.

* Add a new feature called "Direct Network Printing (ESC/POS)" to the POS system.

* Add a new feature in SMS Settings called "Custom SMS Gateway" that allows users to integrate any SMS provider using their own API details

* Improved POS performance for faster loading and smoother transactions

* Added System Health Dashboard

* Added drag & drop widget ordering on Dashboard .

* Added dashboard customization options for font size and font family.

* Added Real-time Sales Counter page with live auto-refresh

* Added Internal Location (Rack/Shelf/Location) feature and reporting support.

* Added a new toggle option in Online Store Settings to show or hide product prices, allowing prices to be visible only for logged-in users in the online store.

* Added Client Portal: clients can login to view invoices, payments, download PDFs

* Assets: added verification dates, due-assets list/page, daily notifications (email + in-app), configurable templates, and cron setup.

* Sales Commission: added commission programs, agents/brokers management, automatic invoice-linked calculations, receipts, and detailed reports.

* Added Knowledge Base module

* Added multi-image support for products with gallery and main image selection

* Added Stripe payment method integration ( Online Store)

* Added Cash on Delivery (COD) payment option in the online store

* Added multi-category and multi-subcategory support for products

* Implemented private account registration with referral code option and admin approval before account activation

* Added ability to change the theme color by selecting a color from the customization panel.

* Added Keyboard Shortcuts to POS

* Added new feature called Overselling Control .

* Added Auto Journal Entries (Accounting Integration).

* Implement a complete Webhooks system

* Added Opening Balance for Suppliers

* Sync Booking Calendar with Google Calendar

* Added 3D Sales Dashboard (Visual Charts)

* 3 Decimal Pricing Support — Enable product pricing with up to 3 decimal places for greater accuracy.

* Kitchen Order Management — Send and manage orders directly from POS to the kitchen workflow.

* POS Return Option — Process product returns directly from the POS interface.

* Advanced Barcode Support — Added support for GTIN, EAN, and other barcode formats, including synchronization with WooCommerce.

* Recruitment Module — Manage job openings, applicants, and hiring processes.

* Meeting Module — Schedule and manage meetings within the system.

* Built-in POS Calculator — Quick calculations directly inside the POS screen.

* Marketing Tools Module — Create and manage customer marketing campaigns.

* Real Estate Theme for Online Store — Launch a property-focused storefront with a dedicated real estate theme.

* Complete Serial Number / IMEI Tracking System

* Multiple Selling Units with Different Prices.


🧰 Bug Fixes & Enhancements

+ Updated documentation for better clarity and setup guidance.

+ Resolved multiple minor bugs to improve performance and reliability.


# Version 1.3  - 30-06-2026

## 🚀 Super Admin

### ✨ New Features

+ Added: Urdu language
+ Added: SMS Settings for the Super Admin panel
+ Added: SMS Templates section for the Super Admin panel
+ Added: Per-Plan Module Management — enable/disable any module per plan, ideal for custom private plans.

## 🚀 Tenant

### ✨ New Features

+ Added: Option to Show/Hide Categories in the Online Store.
+ Added: ZATCA Phase 2 integration.
+ Added: Shopify integration with two-way synchronization.
+ Added: Promotions feature with full sales integration, enabling promotional discounts and offers to be managed and automatically applied during transactions.


🧰 Bug Fixes & Enhancements

+ Updated documentation for better clarity and setup guidance.

+ Resolved multiple minor bugs to improve performance and reliability.



# Version 1.4

## 🚀 Super Admin

### ✨ New Features

* Added **Razorpay** payment gateway
* Added **PayFast** payment gateway
* Added **XPay** payment gateway
* Added **LigdiCash** payment gateway
* Added **WaafiPay** payment gateway
* Added **Dodo Payments** payment gateway
* Added **bKash** payment gateway
* Added **SSLCommerz** payment gateway
* Added **Paymob** payment gateway
* Added **Moyasar** payment gateway
* Added **MyFatoorah** payment gateway
* Added a **Blog Module** for improved SEO
* Added **Login as User / User Impersonation** for SaaS administrators
* Added an option to **enable or disable the default landing page**

---

## 🚀 Tenant

### ✨ New Features & Improvements

* Migrated the frontend to **Vue 3 + Ant Design**
* Added **Products Sold Summary** report
* Added **Pakistan FBR Digital Invoicing**
* Added **Multi-Currency Support**
* Added **Google Fonts Integration**
* Added **Document Archive**
* Added **Manufacturing (MRP)**
* Added **Project Management**
* Added **Booking Management**
* Added **Fleet Management**
* Added **Hospital Management**
* Added **School Management**
* Added new **Profit Reports**, including:

  * Profit by Product
  * Profit by Category
  * Profit by Unit
  * Profit by Customer
  * Profit by Warehouse
  * And more
* Added **Today's Summary** drawer to the header navbar
* Added **Promotions** section
* Added **tenant-authored Knowledge Base articles**, on an *Our Articles* tab beside the read-only *Help Center*, with their own groups, drafts and internal-only flag
* Added **Multi-Sidebar** support
* Optimized **Dark Mode**
* Optimized **RTL Support**
* Added **Zapier Integration**
* Added a new **Integrations Hub**
* Added new sidebar sections:

  * **Ecommerce Platforms**
  * **Integrations**

### 🔗 New Integrations

* Added **Salla** integration
* Added **PrestaShop** integration
* Added **Jumia** integration
* Added **Xero** integration
* Added **Google Sheets** integration
* Added **Mailchimp** integration
* Added **Slack Notifications**
* Added **Telegram Notifications**
* Added **Xero auto-sync** for new sales
* Added **Mailchimp auto-sync** for new customers
* Added **Nightly Google Sheets auto-export**
* Added automatic **keep-alive** for Salla and Xero connections
* Added **plan features and role permissions** for all new integrations

### 🖼️ Product Images by Link

* Added the ability to **paste image URLs** directly into the product form
* The product gallery now includes a **"Paste Image Link"** option next to the upload button
* You can now combine uploaded images with hosted image URLs from sources such as **Unsplash, CDNs, and supplier catalogs**

### 🛍️ Online Store

* Added a new **Electronics** theme for electronics, PC, and laptop stores
* Added a new **Toys & Baby** theme
* Added a new **Grocery & Supermarket** theme
* Redesigned the **Real Estate** theme
* Upgraded the **Default Online Store** theme
* Added **PayPal**, **Paystack** and **Flutterwave** to the online store checkout

  * Each tenant charges into its own merchant account, configured on Online Store → Payment Gateway
  * Hosted redirect flow: the order is created payment-pending and only marked paid after a server-side capture (PayPal) or verification (Paystack, Flutterwave) — a forged return can never settle an order
  * Server-to-server webhooks act as the backstop when the shopper's browser never makes it back, and record refunds issued from the gateway dashboard
* Added **Offline Payments** to the online store: **GCash**, **Bank Transfer** and **Cash on Pickup**

  * Each carries the account details, instructions and (for GCash) the QR code shown at checkout, on the thank-you page and on the order page
  * **GCash** and **Bank Transfer** ask the shopper for a screenshot, reference number and amount, which the admin approves or rejects on the order — approving is what marks the order paid
  * **Cash on Pickup** adds per-warehouse pickup branches (address, hours, contact); a collected order needs no delivery address and is charged no shipping
  * The orders list gains a **Proof pending** filter, and the pending-work bell counts proofs waiting to be verified

### ⚙️ Fixes & Improvements

* Fixed module toggles for **WooCommerce, Shopify, and Zapier** in the sidebar
* Fixed **background queue processing** for webhooks and notifications on shared hosting



## 🧰 Bug Fixes & Enhancements

* Updated all reports to the latest Stocky version

  * Improved the **Customers Report** with complete summary cards and revenue-concentration charts
  * Fixed issues in the **School, Serial Number, Service, and Commission Reports**
* Fixed **per-tenant cache isolation** on the file cache driver, where cached values were previously written to and read from different directories
* Updated the documentation with improved **clarity and setup guidance**
* Resolved multiple minor bugs to improve **performance, stability, and reliability**



# Version 1.5

## 🚀 Super Admin

+ **Important — activate your licence right after updating.** From this version the platform requires an active licence: an installation that is not activated is locked for administrators and tenants alike (tenants see a neutral "temporarily unavailable" page), and only Super Admin → Settings → Licence, the system updater and the super admin login stay reachable. Existing installations have no licence stored yet, so the platform locks as soon as the update finishes until you activate it — do this immediately after updating. Payment-gateway and channel webhooks keep working while locked, so no payments are lost.
+ Added licence activation: the installer has a Licence step (ui-lib.com licence key or Envato purchase code + email; if ui-lib.com cannot be reached you can continue, and the platform runs for 14 days before it locks), and Super Admin → Settings → Licence shows the licence details, support status and domains, lets you move the licence ("Deactivate there" / "Deactivate on this domain"), re-check it, and activate again with the stored key after a lapse. The licence is re-checked about once a day from the super admin panel itself — no cron job is needed. If ui-lib.com cannot be reached, or the licence is only not active on this domain yet (for example after moving the installation), everything keeps working for 14 days (`UILIB_GRACE_DAYS` in `.env`), with a countdown shown to super admins; a refund or revoked licence locks at once.

### ✨ New Features

* Automatic Update: upload the release ZIP from Settings → System Update to back up, update and migrate the platform and every tenant in one step, with automatic rollback if anything fails.
* Payment link for a specific tenant with a custom amount.
* Force logout all users of a tenant.
* New Super Admin Security Settings.
* Login CAPTCHA with built-in CAPTCHA, Google reCAPTCHA, Cloudflare Turnstile and hCaptcha.
* Configurable failed-login attempt lockout.
* Live CAPTCHA key validation before saving.
* Emergency security reset command for Super Admin and tenant logins.
* Landing page mode – use the built-in landing page, redirect to your own website, or disable it
* New E-Invoicing settings for Angola AGT (software credentials, signing key, QR content).

## 🚀 Tenant

### ✨ New Features & Improvements

* Added AI Assistant as the plan feature "AI Assistant", using each tenant's own Claude (Anthropic), ChatGPT (OpenAI) or Gemini (Google) API key: chat with your business data, daily dashboard insights, AI product descriptions (single and bulk), and supplier invoice scanning on new purchases. Choose the provider and model in AI Assistant › Settings; each provider keeps its own key, and conversations continue after switching.

* New: Rental Management.
* New: CRM (Leads & Sales Pipeline)
* New: Audit Log — tracks who created, edited or deleted records (with before/after values) and staff sign-ins.
* Added a Fashion & Apparel online-store theme, with sizes listed on product cards, size-chart guides on product pages, and size and colour filters on the shop page.
* Added a Car Dealership online-store theme: vehicle details on products (make, model, year, mileage…), an inventory page with make/model/year/mileage/body/fuel/gearbox filters, vehicle pages with a finance calculator, enquiry, test-drive, trade-in and reservation requests, and a new Store → Vehicle Leads page.
* New: Restaurant Management — floor plan and tables, menu with add-ons and dish details, waiter order screen with courses and seats, kitchen display by station, bills with service charge, tips, discounts, rounding and split payments (each payment is a normal POS sale), table reservations with a walk-in waitlist, QR table ordering, online table booking, online orders sent to the kitchen, a restaurant dashboard with sales, dish, void, staff, kitchen and reservation reports, and a Guide page with a live setup checklist.
* Added a Restaurant online-store theme: the homepage is your menu, with dish add-ons, dietary filters and online table booking.
* Rental Management: choose your business type (vehicles, equipment, properties, other) in Rental Settings — the item form, categories and filters then show only those types, with each type's own properties (transmission, seats, fuel… / power source, capacity, delivery…), plus one-click suggested categories.
* Added a Rental online-store theme: your rental items with live availability calendars and instant price quotes, filters and booking forms that match your business type (driver and pick-up details for vehicles, delivery and site details for equipment), booking requests that land in Rental › Reservations to confirm or convert, email updates and a booking status page for customers, and "My bookings" in the customer account.
* Booking Management upgraded: business types with their own booking fields, services with length, buffers, seats and staff, bookable staff with working hours, days off and HRM leave, a Requests inbox to confirm, decline or suggest another time, a Day view with "Complete & charge" through the POS, booking emails, reminders and WhatsApp messages, online deposits (Stripe, PayPal, Paystack, Flutterwave, Moyasar, MyFatoorah), a dashboard and reports, CRM capture, "My bookings" in the client portal and a Guide page.
* Added a Booking online-store theme: a booking form on the home page with live free times, "Pick a time" or "Send a request", services, team and opening hours, deposit payment, a booking status page for customers and "My appointments" in the customer account.
* New: Printful print-on-demand integration — import your Printful products (variants and mockups, always in stock), send web orders to Printful automatically when confirmed or paid, or by hand (as drafts or confirmed), offer Printful shipping rates at checkout, and get status, costs and tracking back through webhooks.
* Fixed — confirming a web order no longer fails with "Insufficient stock" for products set to Always in stock.
* Fixed — pulling products from Salla no longer fails.
* New: manager approval to remove POS items — when switched on in POS Settings, cashiers need a manager's PIN to remove an item, lower a quantity, clear the cart or delete a held draft; managers set their PIN on their profile, and every removal is listed in Sales › POS Voided Items.
* New: weighing-scale settings — System Settings › Weighing scale lets you describe the labels your counter scales print (prefix, item code, digits to skip, weight or price, decimals, check digit), with several label formats side by side, a live test box, and a Scale PLU field on products; used by the POS, Create Sale and Create Transfer. Price labels are charged exactly as printed. Existing setups keep reading labels exactly as before.
* Fixed — product lists on Create Sale, Create Transfer and Print Barcode showed the base-unit price instead of the sale-unit price for products without a discount.
* Fixed — in the POS, clicking a product after scanning a weighed item no longer adds it with the scanned weight.
* New: POS Devices (plan feature "POS Devices (Card Terminals)") — connect card terminals and fiscal cash registers to the POS, starting with the Ingenico IDE280. Add each device in Sales › POS Devices with its payment method, merchant and terminal IDs, and either a local bridge on the cashier computer or your provider's cloud API; on the POS, "Charge on device" sends the amount (or the full fiscal receipt with VAT departments, and installments) and the sale completes only once the device approves. Charges are voided automatically if the checkout is closed, and the Transactions tab lists every payment, refund and void, flags cards charged without a sale, and refunds or voids them.
* Added Guest Checkout: customers can now place orders without creating an account.
* Fixed — Error Logs: the report now shows real application errors from the server logs, with a full details and stack-trace view.
* New Security Settings under System Settings.
* Login CAPTCHA.
* Failed-attempt lockout.
* Inactivity auto-logout.
* One active session per user.
* Sign-in activity log.
* New Courier Integration module.
* Courier Accounts management.
* Courier connection testing and default account selection.
* Manual / Other Courier support.
* Courier settings for returns, COD, charges and status checks.
* New courier management permissions.
* Courier booking and tracking.
* Bulk courier booking.
* AfterShip and 17TRACK tracking integration.
* New Courier Shipments page.
* Shipment tracking timeline.
* Manual shipment status and COD updates.
* Printable shipping labels with tracking barcodes.
* Returned parcel review and automatic restocking.
* Automatic COD payout and courier charge recording.
* Pakistan courier integrations: PostEx, Leopards, TCS, Trax, M&P, Call Courier and Daewoo FastEx.
* Pakistan courier airway bills and shipping slips.
* Automatic COD settlement detection for supported Pakistan couriers.
* Courier webhook and scheduled status updates.
* BlueEx tracking support.
* Shipping aggregator integrations.
* EasyPost and Shippo integration.
* Shiprocket integration.
* Sendcloud integration.
* OTO and Torod integration.
* EasyParcel integration.
* Biteship integration.
* Shiplogic integration.
* Sendbox integration.
* Courier rate comparison.
* Default courier selection by cheapest, fastest or preferred carrier.
* Full postal address support.
* Default parcel size configuration.
* Carrier labels and label voiding.
* Automatic customs details for international shipments.
* Shipping aggregator webhook status updates.
* Gulf and Egypt courier integrations.
* Aramex, SMSA Express, J&T Express, Naqel Express, Quiqup, Bosta and Mylerz integration.
* Courier city, area and zone selection.
* Courier booking cancellation.
* Bangladesh, India and Southeast Asia courier integrations.
* Steadfast, Pathao, RedX, eCourier, Delhivery, Shadowfax, Ninja Van, Flash Express and Lalamove integration.
* Courier area selection for supported couriers.
* Courier webhook status updates.
* Automatic COD payout detection for supported couriers.
* Courier labels for supported couriers.
* Courier booking cancellation for supported couriers.
* Map-point delivery with latitude and longitude.
* Lalamove instant delivery price quotes.
* DHL Express, FedEx and UPS integration.
* International shipping rates and customs labels.
* Courier tracking and cancellation support.
* FedEx and UPS push status updates.
* Customer shipment notifications via SMS, email and WhatsApp.
* Customizable customer notification messages.
* Online store shipment tracking page.
* Courier performance reports.
* Customer return-risk badge.
* New Courier Logs page.
* New Courier Guide page.
* Courier setup checklist and webhook information.
* Courier Integration enabled for existing plans.
* Configurable Courier Account limits per plan.
* Plan-based courier log retention.
* Returned parcel re-shipping support.
* Enhanced tracking page privacy.
* Webhook signature validation.
* Protection against backward shipment status updates.
* Automatic COD payout detection after delivery.
* Duplicate timeline prevention.
* Duplicate booking prevention.
* Duplicate restocking prevention.
* New: Angola AGT e-invoicing (FR/FT invoices, NC credit notes, AGT series, QR code).
* New: DR Congo DGI facture normalisée (NIF/RCCM/Id. Nat. on invoices; e-MCF certification pending DGI API access).
* Invoices communicated to AGT or certified by the DGI can no longer be edited or deleted.
* New: Shiip courier integration (Nigeria) — book GIG, DHL, Kwik, Redstar and more, paid from your Shiip wallet.
* New: Shipbubble courier integration (Nigeria) — compare and book couriers paid from your Shipbubble wallet, with cash on delivery and live status updates.
* New: live shipping rates at checkout can come from Shipbubble as well as Shippo — choose the provider and account per shipping method.
* New: Topship courier integration (Nigeria) — domestic and international services paid from your Topship wallet, drop-off or pickup, Topship labels; also available for live shipping rates at checkout.
* New: Custom Domain — tenants on a plan with the "Custom Domains" feature can connect their own domain (shop.example.com) from Settings › Custom Domain: add the domain, follow the CNAME / A / TXT instructions shown, click Check DNS, then set it as primary; the number of domains per plan is set by the new "Custom Domains" plan limit.
* New: Supplier Wallet
* New: Customer Wallet
* Different Prices per Store
* New: Software License Keys — sell third-party keys (upload or CSV/Excel import, one key per unit sold, delivered by email, My Licenses page, client portal and optionally the POS receipt) or generate your own with expiry, activation limits, domain/device locks, renew/suspend/revoke and a signed licensing API.
