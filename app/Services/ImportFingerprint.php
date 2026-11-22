<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers stock-moving imports so the same file uploaded twice asks first.
 *
 * The hash is taken over the PARSED rows (plus the warehouse), not the file
 * bytes, so re-saving the same sheet still matches. A match only counts while
 * the document that import created still exists — deleting it re-arms the file.
 * Everything is a no-op until the import_fingerprints migration has run.
 */
class ImportFingerprint
{
    /** document_type → its table */
    private const DOCUMENTS = [
        'adjustment' => 'adjustments',
        'purchase' => 'purchases',
    ];

    public static function hash(string $kind, ?int $warehouseId, array $rows): string
    {
        return hash('sha256', $kind.'|'.(int) $warehouseId.'|'.json_encode(array_values($rows)));
    }

    /**
     * The earlier import of the same rows whose document still exists, as
     * ['ref', 'date', 'user'] — or null.
     */
    public static function previous(string $kind, string $hash): ?array
    {
        if (! Schema::hasTable('import_fingerprints')) {
            return null;
        }

        $rows = DB::table('import_fingerprints')->where('kind', $kind)->where('hash', $hash)->orderByDesc('id')->get();
        foreach ($rows as $row) {
            $table = self::DOCUMENTS[$row->document_type] ?? null;
            $doc = $table ? DB::table($table)->where('id', $row->document_id)->whereNull('deleted_at')->first(['Ref']) : null;
            if ($doc) {
                $user = $row->user_id ? DB::table('users')->where('id', $row->user_id)->value('username') : null;

                return ['ref' => $doc->Ref, 'date' => (string) $row->created_at, 'user' => $user];
            }
        }

        return null;
    }

    public static function record(string $kind, string $hash, ?int $warehouseId, string $documentType, int $documentId, ?string $fileName): void
    {
        if (! Schema::hasTable('import_fingerprints')) {
            return;
        }

        DB::table('import_fingerprints')->insert([
            'kind' => $kind,
            'hash' => $hash,
            'warehouse_id' => $warehouseId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'file_name' => $fileName ? mb_substr($fileName, 0, 191) : null,
            'user_id' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The response the import pages turn into a "import it again?" prompt. */
    public static function duplicateResponse(array $previous)
    {
        return response()->json([
            'status' => false,
            'duplicate' => true,
            'previous' => $previous,
            'message' => __('messages.ImportDuplicateFile', [
                'ref' => $previous['ref'],
                'date' => $previous['date'],
                'user' => $previous['user'] ?: '—',
            ]),
        ]);
    }
}
