# Security Audit — web_erp_saas

- **Date:** 2026-09-24
- **Scope:** Full repository at commit `fb20574` (`app/`, `routes/`, `config/`, `database/`, `resources/`, `public/`, `bootstrap/`, `deploy/`, root files, the committed `erp_saas_after_install.zip`, and a signature scan of `vendor/`)
- **Method:** Pattern search for each indicator, then a manual read of every hit to confirm or dismiss it

## Indicators searched

| # | Indicator | Result |
|---|-----------|--------|
| 1 | Backdoor | **No intentional backdoor found.** Several upload endpoints let a user drop a web shell (see Web Shell). |
| 2 | Hidden Login | **Not found.** The only "login as" feature is super-admin impersonation. It uses a single-use, 64-char, SHA-256-hashed token with a TTL and an audit log. |
| 3 | Hardcoded Credentials | **Found:** secrets in the committed zip, a default seeder password, and a hard-coded central super-admin account |
| 4 | Admin Bypass | **No auth bypass found.** One unauthenticated data-modifying route. A tenant can trigger the platform-wide updater. The Zapier middleware falls back to the first user. |
| 5 | RCE | **Found:** file-upload RCE, a remote auto-updater, and a shell command built from unescaped config values |
| 6 | Web Shell | **No existing shell files found.** 8 upload endpoints accept `.php` into the web root. |
| 7 | Obfuscated Code | **Not found.** All `base64_decode` calls are legitimate (certs, JSON config, signatures). No `eval`, `gzinflate`, `str_rot13` or packed JS in first-party code. |
| 8 | Remote File Inclusion | **Not found.** No `include`/`require` on user input. The two `require $file` calls load local translation files. |
| 9 | External Callback | **Only legitimate integrations** (payment gateways, Google, Xero, Salla, Shopify, Woo, Telegram, Slack, FX rates). One vendor phone-home: `update-stocky.ui-lib.com`. |
| 10 | Malware | **Not found** in first-party code, cached views, public JS or `vendor/` (signature scan, section 7) |
| 11 | Auto Data Delete | **Found:** scheduled backup-purge every minute, scheduled tenant deletion, a latent `migrate:fresh` command, and `db:wipe` inside re-provisioning |

Severity scale: **Critical**, then **High**, then **Medium**, then **Low**, then **Info**.

---

## 1. Hardcoded credentials / secret leakage

### 1.1 [CRITICAL] Production `.env` and Passport private key committed to git inside a ZIP
- **File:** `erp_saas_after_install.zip` (repo root, 146 MB, tracked in commit `fb20574`)
- **Contents found (values not reproduced here):**
  - `.env` has `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD` and `APP_SUPER_ADMIN_EMAIL` / `APP_SUPER_ADMIN_PASSWORD`. The super-admin password is a weak numeric value starting with `123`.
  - `storage/oauth-private.key` / `storage/oauth-public.key` (Laravel Passport signing keys)
  - `storage/logs/laravel.log` (133 KB), `error_log` (shows the server path `/home/websuppo/weberp.websupportbd.com`)
- **Why it's dangerous:**
  - Anyone with repo access, or anyone who downloads the zip, gets the live DB and mail credentials and the super-admin login.
  - `APP_KEY` lets an attacker decrypt and forge cookies and encrypted values.
  - With `oauth-private.key` an attacker can **mint valid API access tokens for any user**, which is effectively a full authentication bypass.
  - The root `.htaccess` only blocks `.env`, `.md`, `.lock` and similar. The zip file itself is not blocked from direct download.
- **Fix:**
  1. Rotate **all** of these: `APP_KEY`, the DB password, the mail password and the super-admin password. Regenerate the Passport keys with `php artisan passport:keys --force`, which invalidates every issued token.
  2. Delete the zip from the repo **and from git history** (`git filter-repo` / BFG), then force-push.
  3. Add `*.zip`, `error_log` and `storage/*.key` to `.gitignore`.

### 1.2 [HIGH] Hard-coded default super-admin account
- **File:** `database/seeders/Central/CentralUsersSeeder.php:36-42`
- **Code:** `'email' => 'superadmin@stockysaas.site', 'password' => Hash::make('password')`
- **Explanation:** On a fresh central install with no `APP_SUPER_ADMIN_*` env values, the seeder creates a super admin with a publicly known email and the password `password`. Every copy of this CodeCanyon product ships the same pair.
- **Fix:** Refuse to seed without env values, or generate a random password and print it once. Confirm this account does not exist in production.

### 1.3 [MEDIUM] Super-admin password stored in plain text in `.env`
- **File:** `app/Http/Controllers/SetupController.php:264-265`, `database/seeders/Central/CentralUsersSeeder.php:17-18`
- **Explanation:** The setup wizard writes `APP_SUPER_ADMIN_PASSWORD` into `.env` and leaves it there after install. That is how it ended up in the leaked zip (1.1).
- **Fix:** Remove the key from `.env` after the first seed.

### 1.4 [LOW] Static test password
- **File:** `database/seeders/TestDataSeeder.php:339` sets `Hash::make('test1234')`
- **Explanation:** This only matters if `TestDataSeeder` is ever run on production.

### 1.5 [INFO] Demo tokens (not real)
- `database/seeders/ShopifyDemoSeeder.php:223,225,267` contain placeholder strings named `..._not_a_real_token`. No action needed.

---

## 2. RCE / Web Shell (file upload into web root)

The site serves `public/` directly and Apache executes `*.php` there. Neither `public/.htaccess` nor the root `.htaccess` disables PHP in upload folders. Any upload that keeps a client-controlled extension under `public/` is therefore a web-shell drop point. The stored path is returned in the API response, so the attacker also learns the URL.

| Sev | File:Line | Who can reach it | Problem |
|-----|-----------|------------------|---------|
| **CRITICAL** | `app/Http/Controllers/Api/Portal/PortalRequestsController.php:84-88` | **External portal customers** (`routes/portal.php:47,49`) | `$ext = getClientOriginalExtension()` then `Str::random(24).'.'.$ext`, moved to `public/images/tenants/{id}/protocol/`. Validation is only `file\|max:10240` (lines 145, 191). Uploading `x.php` gives an executable shell. |
| HIGH | `app/Http/Controllers/ExpensesController.php:235-238` | Tenant staff | Filename is `time_rand_<original name>` in `public/images/expense_documents/`. Validation is `file\|max:10240`, so `.php` is kept. |
| HIGH | `app/Http/Controllers/hrm/PayrollController.php:304-307` | Tenant staff | Same pattern, `public/images/payroll_documents/` |
| HIGH | `app/Http/Controllers/PurchasesController.php:2472-2475` | Tenant staff | Same pattern, `public/images/tenants/{id}/purchase_documents/` |
| HIGH | `app/Http/Controllers/SalesController.php:3960-3963` | Tenant staff | Same pattern, `public/images/tenants/{id}/sale_documents/` |
| HIGH | `app/Http/Controllers/MeetingController.php:58-59` | Tenant staff | `Str::random(20).'.'.getClientOriginalExtension()` into `public/images/meetings/` |
| HIGH | `app/Http/Controllers/ProtocolController.php:116-118` | Tenant staff | Same as the portal controller (validation `file\|max:10240`, lines 333, 426) |
| HIGH | `app/Http/Controllers/ContractController.php:443-445` | Tenant staff | `$file->store(..., 'public')` names the file by the **guessed MIME extension**. A PHP payload is detected as `text/x-php`, which is saved as `.php` in `storage/app/public`. That folder is web-reachable once `storage:link` exists. |
| MEDIUM | `app/Http/Controllers/DocumentArchiveController.php:38-41` | Tenant staff | Uses a blacklist, not an allowlist. It misses `.pht`, `.phtm`, `.shtml`, `.html` and `.svg` (stored XSS), plus server-specific handlers. |

**Also:** the expense, payroll and meeting folders are **not tenant-scoped** (`public_path('images/...')`). Files from every tenant land in the same public folder.

**Fix (all of the above):**
1. Validate with an **allowlist**: `mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt`.
2. Never reuse the client extension. Derive it from the validated MIME type.
3. Store uploads outside `public/` (the `local` disk) and serve them through a controller that checks authorization.
4. Add `public/images/.htaccess` (and the same for `storage/app/public`) with:
   ```apache
   <FilesMatch "\.(php\d?|phtml|phar|pht|shtml)$">
       Require all denied
   </FilesMatch>
   php_flag engine off
   ```
5. Check the live server for existing `*.php` files under `public/images/**` and `storage/app/public/**`.

---

## 3. RCE / External callback — auto-updater

### 3.1 [HIGH] Tenant users can trigger a platform-wide code replacement
- **Routes:** `routes/tenant_api.php:1483-1485` (`one_click_update`, `update/preflight`, `update/progress`); also `routes/api.php:1437-1439`
- **Controller:** `app/Http/Controllers/AutoUpdateController.php:17-19`
- **Authorization:** `SettingPolicy::update` (`app/Policies/SettingPolicy.php:52-57`). Any **tenant** role with `setting_system` passes, which in practice is every tenant admin.
- **Explanation:** The codebase is shared by all tenants. A tenant admin can therefore:
  - put the **whole SaaS** into maintenance mode (`Artisan::call('down')`, line 54)
  - download and extract a ZIP over `base_path()`
  - run `migrate`, or `migrate:rollback` on failure (lines 246, 252)
  - run seeders (lines 1027-1030)

  This is a cross-tenant denial-of-service at minimum. If the update feed is ever compromised, it becomes RCE on every tenant.
- **Fix:** Remove these routes from `tenant_api.php`. Keep updates only under `routes/central.php:141` (`auth.central` plus the super-admin check in `UpdateController::assertSuperAdmin`).

### 3.2 [MEDIUM] Remote code download with optional integrity checks
- **File:** `app/Http/Controllers/AutoUpdateController.php:299`, `:1125` (phone-home to `https://update-stocky.ui-lib.com/stocky_version.json`); `:325-358` (download); `:84-94` (checksum is only checked **if present**); `:368` (signature is only checked if `UPDATE_PUBLIC_KEY` is set)
- **Explanation:** Whoever controls `update-stocky.ui-lib.com` (the original CodeCanyon author's domain, or anyone who compromises it) can push arbitrary PHP to your server. A feed with no `checksum` skips verification entirely. `downloadFile()` uses `follow_location => 1`, so the host allow-list (line 72) only checks the first URL, not redirects. The server also contacts that third-party domain on every version check.
- **Fix:** Disable or remove the Stocky one-click updater, since you maintain this fork yourself. If you keep it, make both the signature and the checksum **mandatory**.

### 3.3 [MEDIUM] Shell command built from unescaped values
- **File:** `app/Console/Commands/DatabaseBackUp.php:62-64`, executed at `:69`
- **Code:** `$dumpPath.' --user='.$db_user." --password='".$db_pass."' --host=".$db_host.' '.$db_name.' > '.$outputPath`
- **Explanation:** None of the values go through `escapeshellarg()`. A DB password containing `'`, or any `;`/`$()` in `DUMP_PATH`, user, host or DB name, breaks out into shell command execution. Today these come from config, but tenant DB credentials are editable from the super-admin UI (`TenantController`), so the chain is realistic. `CentralDatabaseBackup.php:37-47` does this correctly with `escapeshellarg`; copy that pattern.
- **Related:** `AutoUpdateController.php:1218-1221` builds `/bin/sh -c 'mysql' --user=...`. Only the first argument reaches `sh -c`, so the restore silently runs `mysql` with no arguments. This is broken rollback logic, not an injection.

### 3.4 [LOW] Pre-install DB tester: SQL injection and `LOCAL_INFILE`
- **File:** `app/Http/Controllers/TestDbController.php:79` runs `"CREATE DATABASE IF NOT EXISTS $db_name"` (raw input); `:73,78` set `PDO::MYSQL_ATTR_LOCAL_INFILE => true` against a user-supplied host, which enables a rogue-MySQL-server file-read attack.
- **Reachable:** only while `storage/app/public/installed` is missing (`routes/web.php:177-230`, `routes/central.php:48-66`).
- **Related risk:** the "installed" marker sits on the **public** disk. If that file is ever deleted (disk cleanup, a mistaken backup restore, a file-delete bug), the whole setup wizard reopens to the internet, and `lastStep` rewrites `.env`.
- **Fix:** Escape or validate `$db_name` (`^[A-Za-z0-9_]+$`), drop `LOCAL_INFILE`, and move the marker to `storage/app/installed` (the private disk).

---

## 4. Auto data delete

### 4.1 [HIGH] Backup job runs every minute and deletes all older backups
- **Schedule:** `app/Console/Kernel.php:33` has `$schedule->command('database:backup');` with **no frequency**. Laravel defaults this to `* * * * *`, i.e. **every minute**.
- **Purge:** `app/Console/Commands/DatabaseBackUp.php:46-49` runs `foreach (glob($backupDir.'/*')) @unlink(...)` and deletes **every** existing file in the backup folder before each dump.
- **Effect:**
  - Only one backup ever exists. It is overwritten every minute, so a corrupted state replaces the last good backup within 60 seconds.
  - `mysqldump` runs 1,440 times a day.
  - `Get_Backup` / `Delete_Backup` in `app/Http/Controllers/BackupController.php` show at most one file.
- **Fix:** Use `->dailyAt('01:00')` and keep N days of backups (delete only files older than N days).

### 4.2 [MEDIUM] Automatic tenant deletion (scheduled daily)
- **Schedule:** `app/Console/Kernel.php:37` runs `tenant:cleanup-inactive` daily
- **Code:** `app/Console/Commands/CleanupInactiveTenants.php:115-123`. This deletes subscriptions, payments and domains, then `$tenant->delete()`, which **drops the tenant database** through stancl/tenancy.
- **Trigger:** status is suspended, cancelled or failed **and** there has been no activity for 90 days (`--delete-after-days=90`). Suspension itself is automatic 7 days after expiry (`:40-75`).
- **Explanation:** This is documented behavior, not malware, but it is irreversible customer-data destruction with no backup, confirmation or email beforehand. A customer whose card fails is suspended after 7 days and permanently erased 90 days after their last activity.
- **Fix:** Back up the tenant DB before dropping it, notify the owner, and require super-admin confirmation (or soft-delete first).

### 4.3 [MEDIUM] Latent "wipe the whole database" command
- **File:** `app/Console/Commands/Migrate.php:15` (signature `auto:Migrate`, description *"Migrate tables after 1 hour"*); `:41` runs `\Artisan::call('migrate:fresh --force');`
- **Explanation:** This is a leftover CodeCanyon **demo-reset** command. It is auto-registered by `$this->load(__DIR__.'/Commands')` (`Kernel.php:92`). It is not scheduled today, but a single `php artisan auto:Migrate`, or a future cron entry, **drops every table in the central DB**.
- **Fix:** Delete this file.

### 4.4 [MEDIUM] `db:wipe` inside tenant provisioning
- **File:** `app/Jobs/ProvisionTenantWorkspace.php:153` runs `Artisan::call('db:wipe', ['--force' => true]);` inside `$tenant->run()`
- **When:** Every time the job runs for a tenant whose status is not `active` (`:44`), or with `forceReprovision=true`.
- **Call sites:**
  - `app/Http/Controllers/Central/Super/TenantController.php:970` is the super-admin **Re-provision** button. It wipes all tenant data.
  - `app/Http/Controllers/Central/Super/TenantController.php:676` is inside `verifyPendingPayment()`, called from `paymentPreparing()` (`:583`). If a **suspended** tenant has a pending gateway payment (a renewal created by `BillingApiController.php:342`), opening this page can dispatch provisioning, and that wipes the existing tenant DB. *(Plausible; confirm against the renewal flow.)*
- **Fix:** Only `db:wipe` when the DB was just created (`$dbCreated === true`). Add a confirmation step for re-provisioning, and never provision a tenant that already has data.

### 4.5 [LOW] Unauthenticated data-modifying endpoint
- **Routes:** `routes/tenant_api.php:62`, `routes/api.php:62` expose `GET /api/products_clean_names`
- **Controller:** `app/Http/Controllers/ProductsController.php:4129-4147`
- **Explanation:** With **no auth**, any visitor can make the app rewrite every product name containing `<` or `>`, on every tenant domain. It is not destructive in the data-loss sense, but it is an unauthenticated write plus a cheap DoS (full-table scan).
- **Fix:** Move the route inside the `auth:api` group, or delete it (it was a one-off migration).

---

## 5. Admin bypass / authorization notes

| Sev | File:Line | Note |
|-----|-----------|------|
| MEDIUM | `app/Http/Middleware/ZapierAuth.php:38-39` | If `zapier_settings.user_id` is empty, it falls back to `User::orderBy('id')->first()`, usually the tenant owner or admin. The Zapier key then runs with full admin rights. Require an explicit acting user. |
| LOW | `routes/tenant_api.php:63` (`POST getAccessToken`) | Password login issues a Passport token with **no throttle** (brute-force). Add `throttle:5,1`. |
| INFO | `app/Http/Controllers/ImpersonationController.php:35-106`, `Central/Super/TenantUserController.php:115-168` | Impersonation looks secure: a 64-char random token stored as a SHA-256 hash, single use, TTL, tenant-bound, and audited. No hidden login. |
| INFO | `app/Http/Middleware/CheckCentralPermission.php`, `app/Policies/*` | No hard-coded user-ID or email checks and no `Gate::before` bypass. The `return true` lines in policies are inside permission checks. |

---

## 6. Other exposure

| Sev | File:Line | Note |
|-----|-----------|------|
| MEDIUM | `app/Console/Commands/DatabaseBackUp.php:41` | The central SQL dump is written to `storage/app/public/backup/backup-YYYY-MM-DD.sql`. That is the **public** disk (`config/filesystems.php:86`). After `php artisan storage:link` it can be downloaded unauthenticated from `/storage/backup/backup-<date>.sql`, a predictable filename. Tenant backups are suffixed per tenant (`config/tenancy.php:65`) and are not exposed. Move backups to `storage/app/backups` (private). |
| LOW | `.htaccess` (root), `error_log`, `bash.exe.stackdump`, `.rnd` | Stray files in the web root. `error_log` reveals the hosting path and PHP version. The root `.htaccess` does not block `*.zip`, `*.sql`, `*.log` or `error_log`. |
| INFO | `app/Http/Controllers/UpdateController.php:446`, `database/seeders/TranslationSeeder.php:23` | `require $file` loads files from `database/seeders/translations/` only. This is not RFI. |
| INFO | `routes/tenant_api.php:1482` | The route `UpdateController@get_version_info` points to a method that does not exist (dead route). |

---

## 7. Malware / obfuscation scan results

- **First-party PHP** (`app`, `routes`, `config`, `database`, `bootstrap`, `resources/views`): no `eval`, `assert`, `create_function`, `preg_replace /e`, `gzinflate`, `str_rot13`, hex-escaped strings, `$_GET`/`$_POST` variable functions, or lines over 2,000 characters.
- **`exec()` use:** only 3 places (backup, central backup, updater restore), all covered above. No `system`, `passthru`, `shell_exec`, `proc_open` or `popen`.
- **Stray PHP:** the only PHP file under `public/` is `public/index.php` (stock Laravel). There is no `.php` in upload folders locally. Compiled views in `storage/framework/views` are clean.
- **Frontend** (`resources/src`, `public/js`): no `eval(atob(...))`, `new Function`, miner scripts or hidden iframes. The only external `<script src>` tags are Stripe (`js.stripe.com`) and Razorpay (`checkout.razorpay.com`). `document.write` is used only for print pop-ups (`PosPage.vue:8180+`).
- **Composer/npm hooks:** only the stock Laravel `post-autoload-dump` / `key:generate` hooks. No `postinstall` scripts in `package.json`.
- **Outbound domains in code:** all map to documented integrations (payment gateways, Google, Xero, Salla, Shopify, WooCommerce, Telegram, Slack webhook with a `starts_with:https://hooks.slack.com/` rule, FX APIs `open.er-api.com` / `frankfurter.app`, ZATCA, FBR). No unknown exfiltration endpoints.
- **`vendor/` signature scan** (`eval(base64_decode|gzinflate|gzuncompress|str_rot13|$_POST/$_GET/$_REQUEST/$_COOKIE…)`, `assert($_…)`, `preg_replace /e`): **0 matches**. The only top-level vendor PHP files are the standard Composer autoloader files and `vendor/bin/jp.php` (the jmespath CLI from the AWS SDK). No sign of a tampered dependency. (This is a signature scan only. For full assurance, run `composer install` fresh from `composer.lock` and diff against the current `vendor/`.)
- **`storage/app/backups/central/.htaccess`** contains `Deny from all`, which is correct.

---

## 8. Priority action list

1. **Today:** rotate every secret from 1.1. Regenerate the Passport keys. Purge `erp_saas_after_install.zip` from git history.
2. **Today:** fix the upload endpoints in section 2, starting with the portal (customer-facing). Add a "no PHP" `.htaccess` in the upload dirs. Check the live server for planted `.php` files.
3. Remove the auto-update routes from `tenant_api.php` (3.1). Disable the Stocky remote updater (3.2).
4. Change `database:backup` to daily with retention (4.1). Move backups off the public disk (section 6). Escape the shell args (3.3).
5. Delete `app/Console/Commands/Migrate.php` (4.3). Guard `db:wipe` (4.4). Add backup and confirmation before tenant auto-delete (4.2).
6. Protect `products_clean_names` (4.5). Throttle `getAccessToken`. Fix the Zapier fallback user. Change or remove the default super-admin (1.2).
