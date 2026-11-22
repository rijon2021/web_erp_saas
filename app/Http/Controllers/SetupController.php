<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TestDbController;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\ClientRepository;

class SetupController extends Controller
{

    public function changeEnv($data = array())
    {
        if (count($data) > 0) {

            // Read .env-file
            $env = file_get_contents(base_path() . '/.env');

            // Split string on every " " and write into array
            $env = preg_split('/(\r\n|\n|\r)/', $env);

            // Loop through given data
            foreach ((array) $data as $key => $value) {

                // Loop through .env-data
                foreach ($env as $env_key => $env_value) {

                    // Turn the value into an array and stop after the first split
                    // So it's not possible to split e.g. the App-Key by accident
                    $entry = explode("=", $env_value, 2);

                    // Check, if new key fits the actual .env-key
                    if ($entry[0] == $key) {
                        // If yes, overwrite it with the new one
                        if ($value !== null) {

                            $env[$env_key] = $key . "=" . $value;
                        }
                    } else {
                        // If not, keep the old one
                        $env[$env_key] = $env_value;
                    }
                }
            }

            // Turn the array back to an String
            $env = implode("\n", $env);

            // And overwrite the .env with the new data
            file_put_contents(base_path() . '/.env', $env);

            return true;
        } else {
            return false;
        }
    }


    public function viewStep1()
    {

        $data = array(
            "APP_NAME" => session('env.APP_NAME') ? str_replace('"', '', session('env.APP_NAME')) : str_replace('"', '', config('app.name')),
            "APP_ENV" => session('env.APP_ENV') ? session('env.APP_ENV') : config('app.env'),
            "APP_DEBUG" => session('env.APP_DEBUG') ? session('env.APP_DEBUG') : config('app.debug'),
            "APP_KEY" => session('env.APP_KEY') ? session('env.APP_KEY') : config('app.key'),
        );

        return view('setup.step1', compact('data'));
    }

    public function viewCheck()
    {
        return view('setup.check');
    }

    public function viewStep2()
    {
        // The licence step must have been answered (activated, or skipped
        // after ui-lib.com could not be reached).
        if (! session('setup.license') && ! session('setup.license_skipped')) {
            return redirect('/setup/license');
        }

        $connection = session('env.DB_CONNECTION') ?: config('database.default');
        $db = config("database.connections.{$connection}", []);

        $data = array(
            "DB_CONNECTION" => session('env.DB_CONNECTION') ? session('env.DB_CONNECTION') : config("database.default"),
            "DB_HOST" => session('env.DB_HOST') ?: ($db['host'] ?? ''),
            "DB_PORT" => session('env.DB_PORT') ?: ($db['port'] ?? ''),
            "DB_DATABASE" => session('env.DB_DATABASE') ?: ($db['database'] ?? ''),
            "DB_USERNAME" => session('env.DB_USERNAME') ?: ($db['username'] ?? ''),
            "DB_PASSWORD" => session('env.DB_PASSWORD') ? str_replace('"', '', session('env.DB_PASSWORD')) : str_replace('"', '', (string) ($db['password'] ?? '')),
        );

        return view('setup.step2', ["data" => $data]);
    }

    public function viewStep3()
    {
        // An install sealed without an answer on the licence step locks at once.
        if (! session('setup.license') && ! session('setup.license_skipped')) {
            return redirect('/setup/license');
        }

        $dbtype = session('env.DB_CONNECTION') ?: config('database.default');
        $db = config("database.connections.{$dbtype}", []);
        $dbDatabase = session('env.DB_DATABASE');

        $data = array(
            "APP_NAME" => str_replace('"', '', session('env.APP_NAME')) == str_replace('"', '', config('app.name')) ? 'old' : str_replace('"', '', session('env.APP_NAME')),
            "APP_ENV" => session('env.APP_ENV') == config('app.env') ? 'old' : session('env.APP_ENV'),
            "APP_DEBUG" => session('env.APP_DEBUG') == config('app.debug') ? 'old' : session('env.APP_DEBUG'),
            "APP_KEY" => session('env.APP_KEY') == config('app.key') ? 'old' : session('env.APP_KEY'),
            "DB_CONNECTION" => session('env.DB_CONNECTION') == config("database.default") ? 'old' : session('env.DB_CONNECTION'),
            "DB_HOST" => session('env.DB_HOST') == ($db['host'] ?? '') ? 'old' : session('env.DB_HOST'),
            "DB_PORT" => session('env.DB_PORT') == ($db['port'] ?? '') ? 'old' : session('env.DB_PORT'),
            "DB_DATABASE" => $dbDatabase == ($db['database'] ?? '') ? 'old' : session('env.DB_DATABASE'),
            "DB_USERNAME" => session('env.DB_USERNAME') == ($db['username'] ?? '') ? 'old' : session('env.DB_USERNAME'),
            "DB_PASSWORD" => str_replace('"', '', session('env.DB_PASSWORD') ?? '') == str_replace('"', '', (string) ($db['password'] ?? '')) ? 'old' : str_replace('"', '', session('env.DB_PASSWORD') ?? ''),

        );

        $count = 0;

        foreach ($data as $mydata) {

            $mydata !== 'old' ? $count++ : false;
        }

        $view = view('setup.step3', compact('data'));

        return $view;
    }

    public function lastStep(Request $request)
    {
        // An install sealed without an answer on the licence step locks at once.
        if (! session('setup.license') && ! session('setup.license_skipped')) {
            return redirect('/setup/license');
        }

        // The super admin account is chosen here — no default credentials
        // (seeded superadmin@stockysaas.site, or APP_SUPER_ADMIN_* left in
        // .env) may survive an install.
        // On failure go back to the review page explicitly: it is reached by a
        // POST (step-3), so "back" would land on the database step instead.
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'admin_email' => 'required|email|max:191',
            'admin_password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            return redirect('/setup/step-3')
                ->withErrors($validator)
                ->withInput($request->only('admin_email'));
        }
        $admin = $validator->validated();

        ini_set('max_execution_time', 2000);
        ini_set('memory_limit', '512M');

        try {
            $this->changeEnv([
                'APP_NAME' => session('env.APP_NAME'),
                'APP_ENV' => session('env.APP_ENV'),
                'APP_KEY' => session('env.APP_KEY'),
                'APP_DEBUG' => session('env.APP_DEBUG'),
                'APP_URL' => session('env.APP_URL') ?: $request->getSchemeAndHttpHost(),
                'LOG_CHANNEL' => session('env.LOG_CHANNEL') ?: config('logging.default') ?: 'stack',

                'DB_CONNECTION' => session('env.DB_CONNECTION'),
                'DB_HOST' => session('env.DB_HOST'),
                'DB_PORT' => session('env.DB_PORT'),
                'DB_DATABASE' => session('env.DB_DATABASE'),
                'DB_USERNAME' => session('env.DB_USERNAME'),
                'DB_PASSWORD' => session('env.DB_PASSWORD'),
            ]);

            Artisan::call('config:clear');

            // The app booted with the OLD .env, so config() and the live DB
            // connection still point at the pre-setup database. config:clear only
            // wipes the on-disk cache — it does NOT refresh the running process.
            // Push the just-written credentials into the runtime config and
            // reconnect, otherwise migrate:fresh runs against the stale connection
            // (tables land in the wrong DB while setup still reports success).
            $connection = session('env.DB_CONNECTION') ?: config('database.default');

            config(['database.default' => $connection]);

            // Central migrations, models and seeders are pinned to the 'central'
            // connection, which reads the same DB_* vars — refresh it too, or the
            // central tables get created in the OLD database (e.g. "tenants
            // already exists" when the old DB was a previous install).
            $centralConnection = config('tenancy.database.central_connection', 'central');

            foreach (array_unique([$connection, $centralConnection]) as $name) {
                config([
                    "database.connections.{$name}.host"     => session('env.DB_HOST'),
                    "database.connections.{$name}.port"     => session('env.DB_PORT'),
                    "database.connections.{$name}.database" => session('env.DB_DATABASE'),
                    "database.connections.{$name}.username" => session('env.DB_USERNAME'),
                    "database.connections.{$name}.password" => str_replace('"', '', session('env.DB_PASSWORD') ?? ''),
                ]);

                DB::purge($name);
                DB::reconnect($name);
            }

            // Central DB schema only — no tenant seeding
            $migrateExit = Artisan::call('migrate:fresh', [
                '--force' => true,
            ]);

            if ($migrateExit !== 0) {
                throw new \RuntimeException(
                    'Migration failed (exit ' . $migrateExit . '): ' . trim(Artisan::output())
                );
            }

            // Remove leftover tenant storage from previous installations
            $tenantsPath = storage_path('tenants');
            if (is_dir($tenantsPath)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tenantsPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }

            // Seed super admin + subscription plans
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CentralUsersSeeder',
                '--force' => true,
            ]);

            // Replace the seeded super admin's credentials with the ones
            // entered on the install step (migrate:fresh above leaves exactly
            // the one account the seeder just created).
            $superAdmin = \App\Models\Central\CentralUser::query()->orderBy('id')->first();
            if (! $superAdmin) {
                throw new \RuntimeException('The super admin account could not be created.');
            }
            \App\Models\Central\CentralUser::query()->whereKey($superAdmin->getKey())->update([
                'email' => $admin['admin_email'],
                'password' => \Illuminate\Support\Facades\Hash::make($admin['admin_password']),
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\PlansSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\LandingPageSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CmsTranslationSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\EmailTemplatesSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CentralLanguagesSeeder',
                '--force' => true,
            ]);

            // Passport keys + OAuth clients (tables already created by migrations above)
            Artisan::call('passport:keys', [
                '--force' => true,
            ]);

            $clientRepository = app(ClientRepository::class);
            $appUrl = config('app.url') ?: 'http://localhost';

            $clientRepository->createPersonalAccessClient(
                null,
                'Laravel Personal Access Client',
                $appUrl
            );

            $clientRepository->createPasswordGrantClient(
                null,
                'Laravel Password Grant Client',
                $appUrl
            );

            // Only mark the app installed once the schema is verifiably present.
            // Guards against a silently misdirected migration locking the wizard.
            if (!Schema::connection($connection)->hasTable('migrations')) {
                throw new \RuntimeException('Setup ran but the expected schema is missing; not marking as installed.');
            }

            // Licence activated on the licence step: persist it now that the
            // central table exists. A failure here must not fail the install —
            // the super-admin notice leads to Settings → Licence afterwards.
            $this->persistLicense();

            Storage::disk('public')->put('installed', 'OK');

        } catch (\Throwable $e) {
            report($e);

            // Render the styled installer error page pinned to step 5 so the
            // user can read the failure and retry without losing their settings.
            return view('setup.error', [
                'message' => $e->getMessage(),
                'setupCurrentStep' => 5,
            ]);
        }

        // Only the e-mail chosen on the install step is shown — never a
        // password (this page used to echo APP_SUPER_ADMIN_PASSWORD from .env).
        $superAdminEmail = $admin['admin_email'];

        $appUrl = rtrim(session('env.APP_URL') ?: $request->getSchemeAndHttpHost(), '/');
        $superAdminLoginUrl = $appUrl . '/super/login';

        return view('setup.finishedSetup', compact(
            'superAdminLoginUrl',
            'superAdminEmail'
        ));
    }

    public function getNewAppKey()
    {

        Artisan::call('key:generate', ['--show' => true]);
        $output = (Artisan::output());
        $output = substr($output, 0, -2);
        return $output;
    }

    public function setupStep1(Request $request)
    {
        $request->session()->put('env.APP_ENV', $request->app_env);
        $request->session()->put('env.APP_DEBUG', $request->app_debug);

        if (strlen($request->app_name) > 0) {
            $request->session()->put('env.APP_NAME', '"' . $request->app_name . '"');
        }

        if (strlen($request->app_key) > 0) {
            $request->session()->put('env.APP_KEY', $request->app_key);
        }

        if (strlen($request->app_url) > 0) {
            $request->session()->put('env.APP_URL', rtrim($request->app_url, '/'));
        }

        // A licence is tied to its domain: activated for another address, the
        // token would be refused at install and the install would lock.
        $activated = session('setup.license');
        if ($activated && \UiLibLicenseClient::normalizeDomain((string) session('env.APP_URL')) !== ($activated['domain'] ?? null)) {
            session()->forget('setup.license');
        }

        return redirect('/setup/license');
    }

    // ------------------------------------------------------------------
    // Licence step (UI Lib). Activation does not need the database: the
    // answer is checked with ui-lib.com here, kept in the session, and
    // written to the central licence table by lastStep once it exists.
    // ------------------------------------------------------------------

    public function viewLicense()
    {
        return $this->renderLicense([]);
    }

    public function setupLicense(Request $request)
    {
        $action = (string) $request->input('action', 'activate');

        if ($action === 'keep' && session('setup.license')) {
            return redirect('/setup/step-2');
        }

        if ($action === 'change') {
            session()->forget(['setup.license', 'setup.license_skipped']);

            return redirect('/setup/license');
        }

        if ($action === 'skip') {
            // Offered only after ui-lib.com could not be reached (renderLicense
            // sets the flag): install goes on, the platform runs for the
            // grace period and the super-admin notice asks for activation. A
            // hand-made "skip" without such an answer is refused.
            if (! session('setup.license_skip_allowed')) {
                return $this->renderLicense(['error' => 'Activate your licence to continue.']);
            }
            session()->forget('setup.license');
            session()->put('setup.license_skipped', true);

            return redirect('/setup/step-2');
        }

        $license = trim((string) $request->input('license'));
        $email = trim((string) $request->input('email'));
        $needsEmail = \UiLibLicenseClient::needsEmail($license);
        if (! $needsEmail) {
            $email = '';
        }

        if ($license === '') {
            return $this->renderLicense(['error' => 'Enter your licence key or purchase code.', 'license' => $license, 'email' => $email]);
        }
        if ($needsEmail && ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return $this->renderLicense(['error' => 'Enter the email address to use with your Envato purchase code.', 'license' => $license, 'email' => $email]);
        }

        $client = app(\UiLibLicenseClient::class);
        $siteUrl = $this->setupSiteUrl();

        // "Deactivate there": release another domain of this licence first,
        // then fall through to a fresh activation attempt here.
        if ($action === 'release') {
            $domain = trim((string) $request->input('domain'));
            $released = $domain !== '' ? $client->deactivate($license, $email, $domain) : ['ok' => false, 'network_error' => false, 'code' => 'invalid_request', 'message' => null];
            if (! $released['ok'] && $released['code'] !== 'not_activated') {
                return $this->renderLicense([
                    'license' => $license, 'email' => $email,
                    'error' => $released['network_error'] ? null : ($released['message'] ?: 'The domain could not be released.'),
                    'networkError' => (bool) $released['network_error'],
                ]);
            }
        }

        $instanceId = session('setup.license_instance_id') ?: (string) Str::uuid();
        session()->put('setup.license_instance_id', $instanceId);

        $result = $client->activate($license, $email, $siteUrl, [
            'version' => app(LicenseService::class)->version(),
            'instance_id' => $instanceId,
        ]);

        if ($result['ok']) {
            $info = $result['data']['license'] ?? [];
            session()->forget(['setup.license_skipped', 'setup.license_skip_allowed']);
            session()->put('setup.license', [
                'license' => $license,
                'email' => $email,
                'token' => $result['token'],
                'instance_id' => $instanceId,
                'domain' => $result['data']['domain'] ?? '',
                'local' => (bool) ($result['data']['local'] ?? false),
                'product_name' => $info['product_name'] ?? '',
                'type' => $info['type'] ?? '',
                'hint' => $info['hint'] ?? '',
            ]);

            return redirect('/setup/step-2');
        }

        if ($result['network_error']) {
            return $this->renderLicense(['license' => $license, 'email' => $email, 'networkError' => true]);
        }

        if ($result['code'] === 'activation_limit') {
            return $this->renderLicense([
                'license' => $license, 'email' => $email,
                'limit' => ['message' => $result['message'], 'domains' => $result['data']['domains'] ?? []],
            ]);
        }

        // The host itself is unusable as a domain (e.g. Laragon's folder-named
        // "my_shop.test" — underscores are not allowed). No licence can ever be
        // tied to it, and an unlicensed install is locked, so the install
        // waits until the host (step 1's Application URL) is fixed.
        if ($result['code'] === 'invalid_domain') {
            return $this->renderLicense(['license' => $license, 'email' => $email, 'invalidDomain' => true]);
        }

        return $this->renderLicense([
            'license' => $license, 'email' => $email,
            'error' => $result['message'] ?: 'The licence could not be activated ('.($result['code'] ?? 'error').').',
        ]);
    }

    /** The URL the licence is bound to — step 1's Application URL, i.e. the future APP_URL. */
    private function setupSiteUrl(): string
    {
        return (string) (session('env.APP_URL') ?: request()->getSchemeAndHttpHost());
    }

    private function renderLicense(array $vars)
    {
        $siteUrl = $this->setupSiteUrl();

        // Only an unreachable ui-lib.com unlocks "Continue without activating":
        // the platform then runs for the grace period and locks until it is
        // activated. An unusable host name has to be fixed first.
        if (! empty($vars['networkError'])) {
            session()->put('setup.license_skip_allowed', true);
        } else {
            // Any real answer since then takes the offer back.
            session()->forget('setup.license_skip_allowed');
        }

        return view('setup.license', array_merge([
            'siteUrl' => $siteUrl,
            'domain' => \UiLibLicenseClient::normalizeDomain($siteUrl),
            'activated' => session('setup.license'),
            'license' => '',
            'email' => '',
            'error' => null,
            'networkError' => false,
            'invalidDomain' => false,
            'limit' => null,
        ], $vars));
    }

    /** Write the licence activated on the licence step into the migrated central database. */
    private function persistLicense(): void
    {
        $saved = session('setup.license');
        if (! is_array($saved) || empty($saved['token'])) {
            if (session('setup.license_skipped')) {
                try {
                    app(LicenseService::class)->recordInstallSkip();
                } catch (\Throwable $e) {
                    \Log::warning('Setup: could not record the licence skip - '.$e->getMessage());
                }
                session()->forget(['setup.license_skipped', 'setup.license_skip_allowed', 'setup.license_instance_id']);
            }

            return;
        }

        try {
            // The token is bound to the URL the licence step activated with
            // (the APP_URL just written). The running process still carries
            // the pre-install config('app.url'), so pass that URL explicitly
            // and align the live config for the rest of this request.
            $siteUrl = $this->setupSiteUrl();
            config(['app.url' => $siteUrl]);
            // Step 1 may have generated a new APP_KEY: it is in .env now, but
            // this process still encrypts with the old one. Re-key the
            // encrypter so the stored licence key decrypts after reboot.
            $appKey = (string) session('env.APP_KEY');
            if ($appKey !== '' && $appKey !== config('app.key')) {
                config(['app.key' => $appKey]);
                app()->forgetInstance('encrypter');
                \Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
            }

            $stored = app(LicenseService::class)->importActivation(
                (string) $saved['license'],
                (string) ($saved['email'] ?? ''),
                (string) $saved['token'],
                $saved['instance_id'] ?? null,
                $siteUrl
            );
            if (! $stored) {
                \Log::warning('Setup: the licence token did not verify for '.$siteUrl.' — activate again under Settings → Licence.');
            }
        } catch (\Throwable $e) {
            \Log::warning('Setup: could not store the licence activation — '.$e->getMessage());
        }

        // The key and token have no business staying in the session once stored.
        session()->forget(['setup.license', 'setup.license_skipped', 'setup.license_skip_allowed', 'setup.license_instance_id']);
    }

    public function setupStep2(Request $request)
    {

        if (strlen($request->db_password) > 0) {
            $request->session()->put('env.DB_PASSWORD', '"' . $request->db_password . '"');
        }
        $request->session()->put('env.DB_CONNECTION', $request->db_connection);
        $request->session()->put('env.DB_HOST', $request->db_host);
        $request->session()->put('env.DB_PORT', $request->db_port);
        $request->session()->put('env.DB_DATABASE', $request->db_database);
        $request->session()->put('env.DB_USERNAME', $request->db_username);

        if ($request->db_connection == 'sqlite') {
            TestDbController::testSqLite();
        }

        return $this->viewStep3();
    }

    /**
     * Optional step 4 handler (form may post here in some flows). Advances to summary.
     */
    public function setupStep3(Request $request)
    {
        return $this->viewStep3();
    }

    /**
     * Optional step 5 handler (form may post here in some flows). Advances to summary.
     */
    public function setupStep4(Request $request)
    {
        return $this->viewStep3();
    }
}
