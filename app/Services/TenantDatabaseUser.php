<?php

namespace App\Services;

use App\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A dedicated MySQL account per auto-provisioned tenant.
 *
 * Without it every auto-provisioned (VPS) workspace connects with the central
 * .env account, so the tenant page shows that account (e.g. "stocky_saas")
 * and every tenant can reach every other tenant's database. Here each tenant
 * gets its own user, named after its database, with privileges on that one
 * database only. Its credentials are stored on the tenant like admin-provided
 * ones (so ConfigureTenantDatabaseConnection applies them), plus the
 * tenancy_dedicated_db_user flag that marks the account as ours to drop.
 *
 * Needs the central account to hold CREATE USER + GRANT OPTION. When it does
 * not (typical shared hosting), create() logs why and returns false, and the
 * tenant keeps using the central account exactly as before.
 */
class TenantDatabaseUser
{
    /** MySQL 5.7+/8 user names are limited to 32 characters. */
    private const MAX_USERNAME = 32;

    /** Why the last create() failed, for the super-admin flash message. */
    public ?string $lastError = null;

    /** The account name used for a tenant database. */
    public static function usernameFor(string $database): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $database);

        if (strlen($name) <= self::MAX_USERNAME) {
            return $name;
        }

        // Long ids (UUIDs): keep a readable head plus a hash so names stay unique.
        return substr($name, 0, self::MAX_USERNAME - 9) . '_' . substr(md5($database), 0, 8);
    }

    /**
     * Create (or repair) the tenant's own account on its database and switch
     * the tenant to it. Idempotent: an existing dedicated account keeps its
     * password and only gets its grant re-applied.
     */
    public function create(Tenant $tenant, string $database): bool
    {
        $this->lastError = null;

        // Admin-provided credentials (shared hosting) are not ours to replace.
        if ($tenant->hasDatabaseCredentials() && ! $tenant->usesDedicatedDatabaseUser()) {
            $this->lastError = 'The tenant uses admin-provided database credentials.';

            return false;
        }

        $central = DB::connection('central');
        $host = (string) config('database.connections.central.host', '127.0.0.1');
        $port = (int) config('database.connections.central.port', 3306);

        $username = $tenant->usesDedicatedDatabaseUser()
            ? (string) $tenant->tenancy_db_username
            : static::usernameFor($database);
        $password = $tenant->usesDedicatedDatabaseUser()
            ? (string) $tenant->tenancy_db_password
            : Str::random(40);

        try {
            $account = $this->accountHost();
            $pdo = $central->getPdo();
            $user = $pdo->quote($username) . '@' . $pdo->quote($account);
            $secret = $pdo->quote($password);
            $db = '`' . str_replace('`', '``', $database) . '`';

            $central->statement("CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$secret}");
            // Re-set the password too: an account left over from an earlier
            // run would otherwise keep a password we no longer know.
            $central->statement("ALTER USER {$user} IDENTIFIED BY {$secret}");
            $central->statement("GRANT ALL PRIVILEGES ON {$db}.* TO {$user}");

            $this->verify($host, $port, $database, $username, $password);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning("TenantDatabaseUser: could not set up a dedicated database user for tenant {$tenant->id}; it keeps the central account. {$e->getMessage()}");

            // A half-created account (e.g. GRANT refused) is useless — remove it,
            // but never one the tenant is already using.
            if (! $tenant->usesDedicatedDatabaseUser()) {
                $this->dropAccount($username);
            }

            return false;
        }

        // Not "db_…": stancl copies every tenancy_db_* attribute into the
        // connection config.
        $tenant->setInternal('dedicated_db_user', true);
        $tenant->setDatabaseCredentials($host, $database, $username, $password, $port);

        Log::info("TenantDatabaseUser: tenant {$tenant->id} now connects as '{$username}'.");

        return true;
    }

    /** Drop the tenant's dedicated account (tenant deletion). Best-effort. */
    public function drop(Tenant $tenant): void
    {
        if (! $tenant->usesDedicatedDatabaseUser() || empty($tenant->tenancy_db_username)) {
            return;
        }

        $this->dropAccount((string) $tenant->tenancy_db_username);
    }

    /**
     * Host part for new accounts: the same one the central account matched,
     * so the tenant connects from this app server exactly as central does
     * (e.g. 'localhost' or '%').
     */
    protected function accountHost(): string
    {
        $row = DB::connection('central')->selectOne('SELECT CURRENT_USER() AS u');
        $current = (string) ($row->u ?? '');
        $at = strrpos($current, '@');

        return $at === false ? 'localhost' : substr($current, $at + 1);
    }

    protected function verify(string $host, int $port, string $database, string $username, string $password): void
    {
        $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password, [
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
    }

    protected function dropAccount(string $username): void
    {
        try {
            $central = DB::connection('central');
            $pdo = $central->getPdo();
            $central->statement('DROP USER IF EXISTS ' . $pdo->quote($username) . '@' . $pdo->quote($this->accountHost()));
        } catch (\Throwable $e) {
            Log::warning("TenantDatabaseUser: could not drop database user '{$username}': {$e->getMessage()}");
        }
    }
}
