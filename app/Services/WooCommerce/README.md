# WooCommerce Integration

Two-way sync between Stocky (POS) and a WooCommerce store: **products, stock, categories,
brands, customers and orders**. This document is the reference for how it works, how to run
the queue workers correctly, and how to troubleshoot the common failure modes.

> TL;DR for ops: WooCommerce sync runs in **batched queue jobs** on the fixed queues
> `woocommerce-sync` and `woocommerce-stock`. A worker **must** process those queues or
> syncs hang at `queued_next_batch`. See [Queue & worker setup](#queue--worker-setup).

---

## 1. Components

| File | Role |
|------|------|
| [`Client.php`](Client.php) | Thin signed HTTP client for the Woo REST API (`wc/v3`) + WordPress media. Handles retries, timeouts, and a per-store `fingerprint()`. |
| [`SyncService.php`](SyncService.php) | All business logic: push/pull products, stock, categories, brands, customers, orders; SKU matching; the remote product index. |
| [`../../Jobs/WooCommerceProductsSyncJob.php`](../../Jobs/WooCommerceProductsSyncJob.php) | Push products Stocky → Woo, in batches. Re-dispatches itself for the next batch. |
| [`../../Jobs/WooCommerceProductsPullJob.php`](../../Jobs/WooCommerceProductsPullJob.php) | Pull products Woo → Stocky, in batches (cursor = page/index). |
| [`../../Jobs/WooCommerceStockSyncJob.php`](../../Jobs/WooCommerceStockSyncJob.php) | Push stock quantities Stocky → Woo, in batches. |
| [`../../Http/Controllers/WooCommerceSyncController.php`](../../Http/Controllers/WooCommerceSyncController.php) | All `woocommerce/*` API endpoints: start syncs, progress polling, stats, resets, logs. |
| [`../../Http/Controllers/SyncJobController.php`](../../Http/Controllers/SyncJobController.php) | DB-backed job status/cancel endpoints (`sync/status/{id}`, `woo-sync/*`). |
| [`../../Console/Commands/WooCommerceSync.php`](../../Console/Commands/WooCommerceSync.php) | `php artisan woocommerce:sync` — server-side sync that runs its own inline worker. |

State is stored in two places:

- **`SyncJob` model** (DB table `sync_jobs`) — durable per-run record: status, stage,
  counts, percentage, `worker_heartbeat_at`. Drives the `sync/status/{id}` UI.
- **File cache** (`Cache::store('file')`), keyed by a **token** (e.g. `woo_products_sync_<uniqid>`) —
  fine-grained live progress. The file store is used deliberately so the web request and the
  queue worker (separate processes) share the same state.

---

## 2. Sync flows

### Products push (Stocky → Woo)
`POST woocommerce/sync/products` (default `mode=push`) → creates a `SyncJob`, a progress token,
and dispatches `WooCommerceProductsSyncJob` on queue **`woocommerce-sync`**.

1. Job builds/loads the **remote index** (see §4), then processes up to *Products per batch* (per-tenant tuning)
   products ordered by `id`, starting after the saved cursor `last_product_id`.
2. After the batch it counts remaining products (`id > last_product_id`). If any remain it sets
   stage `queued_next_batch` and **re-dispatches itself** on `woocommerce-sync`.
3. When none remain it marks the `SyncJob` `completed` / stage `finished`.

### Products pull (Woo → Stocky)
`POST woocommerce/sync/products?mode=pull` → dispatches `WooCommerceProductsPullJob` on
**`woocommerce-sync`**. Same batching pattern, but the cursor is `cursor_page` / `cursor_index`
over the remote catalog. Re-dispatches until `done`.

### Stock push (Stocky → Woo)
`POST woocommerce/sync/stock` (alias `woocommerce/sync-stock`) → dispatches
`WooCommerceStockSyncJob` on **`woocommerce-stock`**. Batches of *Products per stock batch* (per-tenant tuning),
re-dispatching until no products remain.

### Automatic sync (opt-in, Stocky → Woo)
Two switches on the Settings tab, `woocommerce_settings.auto_sync_products` / `auto_sync_stock`
(both off by default), handled by [`AutoSync.php`](AutoSync.php) +
[`../../Jobs/WooCommerceAutoSyncJob.php`](../../Jobs/WooCommerceAutoSyncJob.php):

- **Products:** `ProductsController` store / update / duplicate call `AutoSync::productSaved()`.
  The product is pushed (`pushProducts(..., $productIds)`, which skips the remote index and
  looks each SKU up directly), then its stock. Imports are not hooked.
- **Stock:** the `product_warehouse` observer calls `AutoSync::stockChanged()` on every qty
  change (sales, POS, returns, purchases, adjustments, transfers). Combos built from a moved
  component are added; products without a `woocommerce_id` are dropped.
  Pushed with `syncStock(null, $productIds)` (quantities only, no media).

Changes are collected per request and flushed once: in a web request the job runs **after the
response** in the same process (no worker or cron needed); on the CLI / inside a job it goes on
`woocommerce-stock`. Jobs re-read the switches; `flush()` checks the plan still has `woocommerce`.
Each job adds its ids to a per-store pending list (file cache) and drains it in rounds of 50
only if no other job is running (non-blocking lock) — so pushes never overlap, never wait,
and are never dropped. If a round fails completely and the store doesn't answer a ping, the ids
go back on the list and auto-sync pauses 5 minutes. Woo → Stocky pulls (products pull job, order import)
run inside `AutoSync::muted()` so they don't bounce back. Results are logged under action
`auto_sync`. Bulk query-builder updates fire no model events and are not seen.

### Synchronous flows (no queue)
Categories, brands, customers and orders run **inline inside the request** via `SyncService`
(no job/queue): `woocommerce/sync/categories`, `woocommerce/sync/brands`,
`woocommerce/sync/customers`, `woocommerce/sync/orders`.

---

## 3. Queue & worker setup

> **This is the #1 cause of "sync stuck after a few products".**

Product/stock syncs process in small batches; each batch **re-dispatches the next batch** onto a
fixed queue:

- products push & pull → **`woocommerce-sync`**
- stock → **`woocommerce-stock`**

A worker **must** consume those queues. There are three supported ways to run them — pick one
(they can coexist). Full step-by-step instructions for all three are in
[`deploy/QUEUE_WORKER_SETUP.md`](../../../deploy/QUEUE_WORKER_SETUP.md); summary below:

### Option A — Persistent worker (recommended, Supervisor)
Use [`deploy/supervisor/stocky-queue-worker.conf`](../../../deploy/supervisor/stocky-queue-worker.conf).
It runs:

```bash
php artisan queue:work database \
  --queue=woocommerce-sync,woocommerce-stock,default \
  --sleep=1 --tries=1 --timeout=1200 --max-time=3600
```

Increase `numprocs` for more parallel batches. Restart workers after every deploy
(`supervisorctl restart stocky-queue-worker:*`).

### Option B — Cron (shared hosting, no Supervisor)
A single cron entry running the Laravel scheduler every minute:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler ([`app/Console/Kernel.php`](../../Console/Kernel.php)) runs each minute:

```bash
queue:work database --stop-when-empty --max-time=50 \
  --queue=woocommerce-sync,woocommerce-stock,default ...
```

i.e. it drains all pending batches (up to ~50s) then exits, resuming next minute. Slower than a
persistent worker but needs no extra services.

### Option C — One-shot CLI
`php artisan woocommerce:sync --scope=all` runs a sync **and its own inline worker loop** until
finished. Good for manual runs or a dedicated cron. Scopes: `products`, `stock`, `all`.
Add `--only-unsynced` to push only products without a `woocommerce_id`.

### "No-cron" browser tick (fallback, automatic)
If neither a worker nor the scheduler is running, the **progress-polling endpoints** run one
batch inline per poll (`tickProductsQueueOnce` / `tickStockQueueOnce`, guarded by a cache lock).
This makes "Sync now" work with no infrastructure — **but only while the browser tab stays open
and keeps polling.** Closing the tab stalls the sync at `queued_next_batch`. Prefer Option A or B
for unattended/large syncs.

> ⚠️ Queue connection is **`database`** (the `jobs` table). Each tenant has its own database, so
> the fixed queue names are safe — they don't collide across tenants.

---

## 4. Remote product index (performance)

To match local SKUs to existing remote products without an API call per product, `pushProducts`
builds a **remote index** (`SKU → wooId` and external-id → wooId) by paging the entire remote
catalog once. Because pushes run in small batches, the index is **cached per store** across the
batches of a run:

- Key: `woo_remote_index:<client fingerprint>` in the file cache.
- TTL: `WOO_REMOTE_INDEX_TTL` seconds (default 900, capped at 3600; set `0` to disable caching
  and rebuild every batch).
- Built by `buildRemoteIndex()`, wrapped by `getRemoteIndexCached()`.

**Invalidation** (`SyncService::forgetRemoteIndexCache()`) happens automatically when:
- a new products sync starts (UI `syncProducts`, or `php artisan woocommerce:sync`), and
- mappings are reset (`reset-sync`, `reset-products-sync`).

This prevents a stale index from creating **duplicate** remote products after a reset.

---

## 5. Progress, stages & stuck detection

Stages reported during a run: `queued` → `running` → (`start`/`media`/`variants`/`done` per
product) → `queued_next_batch` (between batches) → `finished` (or `failed` / `cancelled`).

The status/progress endpoints implement **stuck detection** based on `worker_heartbeat_at`:

- Normal stall threshold: `WOO_SYNC_STUCK_SECONDS` (default 600s).
- While `stage` starts with `queued`, the threshold is raised to `WOO_SYNC_QUEUE_WAIT_SECONDS`
  (default 1800s) to allow time for a worker tick.
- During `media`, raised to at least `WOO_WP_MEDIA_UPLOAD_TIMEOUT` + 60s.

If the heartbeat is older than the effective threshold the run is force-failed with
`stuck: no worker heartbeat for Ns` so the UI doesn't hang forever. **If you see this and a
worker is configured, the worker isn't consuming the woo queues** — re-check §3.

### Cancelling
- `POST woocommerce/sync/products/stop` / `.../stock/stop` (by token), or
- `POST woo-sync/{id}/cancel` (by SyncJob id).

Both set a cancel flag the job checks at each product boundary; queued jobs for the run are deleted.

---

## 6. Reset operations

| Endpoint | Clears |
|----------|--------|
| `POST woocommerce/reset-sync` | All mappings (products, variants, categories, brands, clients) + logs + last-sync; cancels running syncs; invalidates remote index. |
| `POST woocommerce/reset-products-sync` | Product + variant mappings; cancels running syncs; invalidates remote index. |
| `POST woocommerce/reset-categories-sync` | Category mappings. |
| `POST woocommerce/reset-brands-sync` | Brand mappings. |
| `POST woocommerce/reset-customers-sync` | Client mappings. |
| `POST woocommerce/reset-stock-sync` | Stock-related logs only. |

Changing the store URL/key/secret in `saveSettings` also clears all mappings (the data now points
at a different store).

---

## 7. Configuration

### Credentials (per tenant, stored in DB via the Settings UI, not `.env`)
`store_url`, `consumer_key`, `consumer_secret`, and optional `wp_username` / `wp_app_password`
(WordPress application password, required for image/media uploads).

### Sync tuning (per tenant — WooCommerce settings tab → "Sync tuning")
Depends on each tenant's own store (catalogue size, WordPress host speed), so each tenant sets it.
Stored as columns on `woocommerce_settings`; read with `WooCommerceSetting::tuning($settings)`,
which clamps every value to `config('woocommerce.tenant_limits')`. No `.env` fallback.

| Setting (column) | Default | Purpose |
|------------------|--------:|---------|
| `products_per_job` | `2` | Products processed per push/pull batch (the jobs also clamp push 1–100, pull 1–200). |
| `stock_products_per_job` | `3` | Products per stock batch. |
| `remote_index_page_cap` | `500` | Max pages (×100) when building the remote index. Raise for very large stores. |
| `sku_search_page_cap` | `10` | Page cap for per-SKU remote searches. |
| `allow_alt_sku_on_lookup_conflict` | `false` | On a SKU clash with an unrelated Woo product, push under an alternate SKU instead of skipping. |

### Platform limits (server-wide — `config/woocommerce.php`, set via `.env`)
Depend on the host (PHP time limits, worker timeout), so they apply to every tenant. Always read
them with `config('woocommerce.…')` — never `env()` at runtime, which returns null once
`php artisan config:cache` has run.

| Var | Config key | Default | Purpose |
|-----|------------|--------:|---------|
| `QUEUE_WORKER_TIMEOUT` | `queue.worker_timeout` (config/queue.php) | `1200` | Per-job timeout (s) for inline/scheduled workers. Must exceed the slowest single batch. |
| `WOO_TIMEOUT_MS_BATCH` | `timeout_ms_batch` | `30000` | Timeout (ms) for Woo batch create/update calls. |
| `WOO_HTTP_RETRIES` / `WOO_HTTP_RETRY_BASE_MS` | `http_retries` / `http_retry_base_ms` | `2` / `300` | Client retry count and backoff base. |
| `WOO_MAX_CONSECUTIVE_FAILURES` | `max_consecutive_failures` | `7` | Abort a push after this many consecutive Woo API failures. |
| `WOO_SYNC_STUCK_SECONDS` | `sync_stuck_seconds` | `600` | Heartbeat staleness before a run is marked stuck. |
| `WOO_SYNC_QUEUE_WAIT_SECONDS` | `sync_queue_wait_seconds` | `1800` | Extra grace while between batches (`queued*` stages). |
| `WOO_POLL_TICK_MAX_SECONDS` | `poll_tick_max_seconds` | `300` | Max time a no-cron browser tick may run a batch inline. |
| `WOO_WP_MEDIA_SEARCH_TIMEOUT` | `wp_media.search_timeout` | `15` | Timeout (s) for existing-media lookups. |
| `WOO_WP_MEDIA_UPLOAD_TIMEOUT` | `wp_media.upload_timeout` | `240` | Timeout (s) for WordPress media uploads. |
| `WOO_WP_MEDIA_RETRIES` / `WOO_WP_MEDIA_RETRY_SLEEP_MS` | `wp_media.retries` / `wp_media.retry_sleep_ms` | `2` / `500` | Media retry behaviour. |
| `WOO_REMOTE_INDEX_TTL` | `remote_index_ttl` | `900` | Remote index cache TTL (s); capped at 3600. `0` disables caching. |
| `WOO_PULL_STATS_PAGE_CAP` / `WOO_AUTOLINK_PAGE_CAP` | `pull_stats_page_cap` / `autolink_page_cap` | `200` | Page caps for pull-stats and auto-link scans. |
| `WOO_PULL_ORDER_NOTES_MAX` | `pull_order_notes_max` | `25` | Max Woo order notes pulled per order. |
| `WOO_{VARIATIONS_LIST,PULL_VARIATIONS,DELETE_VARIATIONS}_PAGE_CAP` | `variations_list_page_cap` … | `100` / `50` / `50` | Page caps for variation list/pull/delete. |
| `WOO_MAX_PRODUCTS_PER_JOB`, `WOO_MAX_STOCK_PRODUCTS_PER_JOB`, `WOO_MAX_REMOTE_INDEX_PAGE_CAP`, `WOO_MAX_SKU_SEARCH_PAGE_CAP` | `tenant_limits.*` | `50` / `100` / `2000` / `50` | Upper bounds for the per-tenant tuning above. |
| `WOO_API_BASE_URL` | `api_base_url` | — | **Developer only.** Sends every tenant's API calls to this address (tunnel/proxy), keeping the real `Host` header. Never set in production. |

### Console
`php artisan tenants:run woocommerce:sync --option="scope=stock"` runs a sync for each tenant with
its own credentials and tuning. (The old `woocommerce:push-products` / `woocommerce:sync-stock`
commands were removed: they read one global store from `.env` and would have pushed every tenant's
catalogue into it.)

---

## 8. API endpoints

All under the tenant API prefix and gated by `tenant.feature:woocommerce`
(see [`routes/tenant_api.php`](../../../routes/tenant_api.php)). Key ones:

- **Settings/connection:** `GET|POST woocommerce/settings`, `POST woocommerce/test-connection`
- **Start sync:** `POST woocommerce/sync/products` (`?mode=push|pull&only_unsynced=1`),
  `POST woocommerce/sync/stock`, `POST woocommerce/sync/{categories|brands|customers|orders}`
- **Progress:** `GET woocommerce/sync/products/progress?token=…`,
  `GET woocommerce/sync/stock/progress?token=…`, `GET sync/status/{id}`, `GET woo-sync/latest`
- **Stop/cancel:** `POST woocommerce/sync/products/stop`, `POST woo-sync/{id}/cancel`
- **Stats:** `GET woocommerce/{products|categories|brands}/pull-stats`,
  `GET woocommerce/customers/stats`, `GET woocommerce/*/unsynced-count`, `GET woocommerce/stock-metrics`
- **Maintenance:** `woocommerce/reset-*`, `woocommerce/products/{fix-categories,auto-link}`,
  `GET|DELETE woocommerce/logs`

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Imports a few products then stuck at `queued_next_batch` | No worker consuming `woocommerce-sync` / `woocommerce-stock` | Configure a worker (§3 Option A or B). The first batch runs via `dispatchAfterResponse`, so a few products always import even with no worker — that's the tell. |
| `stuck: no worker heartbeat for Ns` in the UI | Worker not running, or watching the wrong queues | Confirm the worker's `--queue` includes both woo queues; check `php artisan queue:work` is alive. |
| Very slow large pushes / Woo rate-limit errors | Remote index rebuilt too often, or batch too small | Ensure `WOO_REMOTE_INDEX_TTL > 0`; raise *Products per batch* (Settings tab → Sync tuning). |
| Duplicate products created on Woo after a reset | Stale remote index | Already handled (reset invalidates the index). If it recurs, set `WOO_REMOTE_INDEX_TTL=0` temporarily. |
| Images not uploading | Missing/invalid `wp_username` + `wp_app_password`, or media timeout | Set a WordPress application password; raise `WOO_WP_MEDIA_UPLOAD_TIMEOUT`. |
| Sync only advances while the browser tab is open | Relying on the no-cron tick | Set up a worker/scheduler (§3). |

Logs: `GET woocommerce/logs` (UI) or the `woocommerce_logs` table; worker output in
`storage/logs/`.

---

## 10. History / design notes

- Jobs previously dispatched to **dynamic per-sync queues** (`woocommerce-sync-{id}`), which no
  static worker could subscribe to — the root cause of the `queued_next_batch` hangs. Now fixed to
  the static `woocommerce-sync` / `woocommerce-stock` names.
- The remote index was previously rebuilt on **every** batch (full catalog download per ~5
  products); it is now cached per store across a run.
