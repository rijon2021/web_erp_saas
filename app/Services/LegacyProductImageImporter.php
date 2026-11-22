<?php

namespace App\Services;

use App\utils\helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Attach images from an old POS to a tenant's existing products.
 *
 * Input CSV (header row required): old_id,code,name,images
 *   images = one or more filenames separated by "|" (first one becomes the main image).
 *
 * Matching: products.code first (trimmed, case-insensitive, spaces collapsed), then
 * products.name. A key that matches more than one product, or that maps to different
 * images in the old data, is skipped and reported instead of guessed.
 *
 * plan() and attach() must be called while the target tenant is initialised.
 * Used by `products:import-legacy-images` and /super/tools/legacy-images.
 */
class LegacyProductImageImporter
{
    public const STATUSES = [
        'would_import', 'imported', 'skipped_has_image', 'missing_file', 'bad_file',
        'no_match', 'ambiguous_new', 'ambiguous_old', 'duplicate_old', 'error',
    ];

    /** Only real image files are ever read or copied into public/. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @return array<int,array{old_id:string,code:string,name:string,images:string[]}> */
    public function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $head = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($fh) ?: []);
        $rows = [];
        while (($line = fgetcsv($fh)) !== false) {
            if ($line === [null]) {
                continue;
            }
            $r = array_combine($head, array_pad(array_slice($line, 0, count($head)), count($head), ''));
            $images = array_values(array_filter(array_map('trim', explode('|', (string) ($r['images'] ?? $r['image'] ?? '')))));
            if ($images === []) {
                continue;
            }
            $rows[] = [
                'old_id' => (string) ($r['old_id'] ?? ''),
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'images' => $images,
            ];
        }
        fclose($fh);

        return $rows;
    }

    /** @return array<string,string> lowercase filename => full path */
    public function indexFolder(string $dir): array
    {
        $files = [];
        foreach (File::files($dir) as $f) {
            if (in_array(strtolower($f->getExtension()), self::IMAGE_EXTENSIONS, true)) {
                $files[strtolower($f->getFilename())] = $f->getPathname();
            }
        }

        return $files;
    }

    /**
     * Decide what happens to every old row. Writes nothing.
     *
     * @param  string[]  $matchOrder  e.g. ['code', 'name']
     * @return array<int,array{old_id:string,old_code:string,old_name:string,images:string,status:string,matched_by:string,product_id:?int,product_code:string,product_name:string,detail:string,sources:string[]}>
     */
    public function plan(array $oldRows, array $files, array $matchOrder, bool $overwrite): array
    {
        $byCode = [];
        $byName = [];
        $products = DB::table('products')->whereNull('deleted_at')->get(['id', 'code', 'name', 'image']);
        foreach ($products as $p) {
            if (($k = $this->key($p->code)) !== '') {
                $byCode[$k][] = (int) $p->id;
            }
            if (($k = $this->key($p->name)) !== '') {
                $byName[$k][] = (int) $p->id;
            }
        }
        $productById = $products->keyBy('id');
        $galleryCount = Schema::hasTable('product_images')
            ? DB::table('product_images')->select('product_id', DB::raw('COUNT(*) c'))->groupBy('product_id')->pluck('c', 'product_id')->all()
            : [];

        // Old rows that share a code/name but point at different images can't be trusted.
        $oldImagesByKey = ['code' => [], 'name' => []];
        foreach ($oldRows as $r) {
            foreach (['code', 'name'] as $field) {
                if (($k = $this->key($r[$field])) !== '') {
                    $oldImagesByKey[$field][$k][$this->fileKey($r['images'][0])] = true;
                }
            }
        }

        $done = []; // product_id => old_id
        $out = [];
        foreach ($oldRows as $r) {
            [$status, $by, $pid, $detail, $sources] = ['no_match', '', null, '', []];

            foreach ($matchOrder as $field) {
                $k = $this->key($r[$field]);
                if ($k === '') {
                    continue;
                }
                $ids = $field === 'code' ? ($byCode[$k] ?? []) : ($byName[$k] ?? []);
                if ($ids === []) {
                    continue;
                }
                $by = $field;
                if (count($oldImagesByKey[$field][$k] ?? []) > 1) {
                    [$status, $detail] = ['ambiguous_old', "old {$field} used by rows with different images"];
                } elseif (count($ids) > 1) {
                    [$status, $detail] = ['ambiguous_new', count($ids)." products share this {$field}: ".implode(' ', $ids)];
                } else {
                    [$status, $pid] = ['matched', $ids[0]];
                }
                break;
            }

            $hadImage = false;
            if ($status === 'matched') {
                $product = $productById[$pid];
                $hadImage = $this->hasImage($product->image, $galleryCount[$pid] ?? 0);
                $missing = [];
                $bad = [];

                if (isset($done[$pid])) {
                    [$status, $detail] = ['duplicate_old', 'product already handled by old_id '.$done[$pid]];
                } elseif ($hadImage && ! $overwrite) {
                    [$status, $detail] = ['skipped_has_image', (string) $product->image];
                } else {
                    foreach ($r['images'] as $name) {
                        $real = $files[$this->fileKey($name)] ?? null;
                        if ($real !== null && in_array($real, $sources, true)) {
                            continue; // same file listed twice (e.g. "products/x.png" and "x.png")
                        }
                        if ($real === null) {
                            $missing[] = $name;
                        } elseif (! $this->isReadableImage($real)) {
                            $bad[] = $name; // empty / corrupt file
                        } else {
                            $sources[] = $real;
                        }
                    }

                    $notes = array_filter([
                        $missing ? 'missing: '.implode('|', $missing) : '',
                        $bad ? 'unreadable: '.implode('|', $bad) : '',
                    ]);
                    $detail = implode('; ', $notes);

                    if ($sources !== []) {
                        $done[$pid] = $r['old_id'];
                        $status = 'would_import';
                    } else {
                        $status = $bad ? 'bad_file' : 'missing_file';
                    }
                }
            }

            $p = $pid ? $productById[$pid] : null;
            $out[] = [
                'old_id' => $r['old_id'],
                'old_code' => $r['code'],
                'old_name' => $r['name'],
                'images' => implode('|', $r['images']),
                'status' => $status,
                'matched_by' => $by,
                'product_id' => $pid,
                'product_code' => (string) ($p->code ?? ''),
                'product_name' => (string) ($p->name ?? ''),
                'detail' => $detail,
                'sources' => $sources,
                'had_image' => $hadImage,
            ];
        }

        return $out;
    }

    /** True when the product already shows an image (re-checked right before writing). */
    public function productHasImage(int $productId): bool
    {
        $image = DB::table('products')->where('id', $productId)->value('image');
        $gallery = Schema::hasTable('product_images')
            ? DB::table('product_images')->where('product_id', $productId)->count()
            : 0;

        return $this->hasImage($image, $gallery);
    }

    /** Copy (and optionally resize) files, then point products.image + product_images at them. */
    public function attach(int $productId, array $sources, bool $resize): void
    {
        $outDir = upload_public_path('products');
        File::ensureDirectoryExists($outDir);
        $hasGallery = Schema::hasTable('product_images');

        $written = [];
        try {
            foreach ($sources as $src) {
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    $ext = 'jpg';
                }
                do {
                    $name = rand(11111111, 99999999).'.'.$ext;
                } while (is_file($outDir.'/'.$name));

                if ($resize) {
                    helpers::apply_product_image_resize(Image::make($src))->save($outDir.'/'.$name);
                } else {
                    File::copy($src, $outDir.'/'.$name);
                }
                $written[] = $name;
            }

            DB::transaction(function () use ($productId, $written, $hasGallery) {
                if ($hasGallery) {
                    DB::table('product_images')->where('product_id', $productId)->delete();
                    $now = now();
                    foreach ($written as $i => $name) {
                        DB::table('product_images')->insert([
                            'product_id' => $productId,
                            'image_path' => $name,
                            'is_main' => $i === 0,
                            'sort_order' => $i,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
                DB::table('products')->where('id', $productId)->update(['image' => $written[0], 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            foreach ($written as $name) {
                @unlink($outDir.'/'.$name);
            }
            throw $e;
        }
    }

    public function countWithoutImage(): int
    {
        return DB::table('products')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('image')->orWhere('image', '')->orWhere('image', 'no-image.png'))
            ->count();
    }

    /** @param array<int,array> $planRows */
    public function writeReport(string $path, array $planRows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $out = fopen($path, 'w');
        fputcsv($out, ['old_id', 'old_code', 'old_name', 'images', 'status', 'matched_by', 'product_id', 'product_code', 'product_name', 'detail']);
        foreach ($planRows as $r) {
            unset($r['sources'], $r['had_image']);
            fputcsv($out, array_values($r));
        }
        fclose($out);
    }

    /** @param array<int,array> $planRows @return array<string,int> */
    public function stats(array $planRows): array
    {
        $stats = [];
        foreach ($planRows as $r) {
            $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
        }
        ksort($stats);

        return $stats;
    }

    private function hasImage($image, int $galleryCount): bool
    {
        return ($image && $image !== 'no-image.png') || $galleryCount > 0;
    }

    /**
     * Lookup key for an old image name. The old POS stores some names with a folder
     * prefix ("products/100055.png") while the exported folder is flat, so match on
     * the lowercase file name only.
     */
    private function fileKey(string $name): string
    {
        return strtolower(basename(str_replace('\\', '/', trim($name))));
    }

    private function isReadableImage(string $path): bool
    {
        return @filesize($path) > 0 && @getimagesize($path) !== false;
    }

    private function key($value): string
    {
        $v = trim((string) $value);
        // preg_replace returns null on invalid UTF-8; fall back to the raw value.
        $collapsed = preg_replace('/\s+/u', ' ', $v);

        return mb_strtolower($collapsed ?? preg_replace('/\s+/', ' ', $v));
    }
}
