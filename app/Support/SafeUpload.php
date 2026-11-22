<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Safe stored names for files written under public/ (public/images/...).
 *
 * Everything under public/ is served straight by the web server, so the
 * EXTENSION a file is saved with decides what it becomes: ".php" runs as
 * code, ".html"/".svg"/".xhtml" run as pages on the app's origin. The name
 * the browser sent is attacker-controlled, so:
 *
 *  - images: the extension comes from the file's CONTENT (guessExtension)
 *    and must be a raster image type — an image renamed "x.html" or
 *    "x.php" is stored as ".png";
 *  - documents: the client extension must be on an allowlist of inert
 *    office/archive types (deny by default — no blacklist).
 *
 * The original name is never used on disk; keep it in the DB if needed.
 */
class SafeUpload
{
    public const IMAGE = ['jpg', 'png', 'gif', 'webp', 'bmp'];

    public const DOCUMENT = [
        'jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif',
        'pdf', 'txt', 'csv', 'rtf', 'json', 'eml', 'msg',
        'doc', 'docx', 'docm', 'xls', 'xlsx', 'xlsm', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'odg',
        'zip', 'rar', '7z',
        'mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm', 'mov',
    ];

    /**
     * Content-derived extension restricted to $allowed (e.g. ['png','jpg','svg']
     * for flags, ['jpg','png','webp','pdf'] for payment proofs). The client
     * name never decides the stored extension.
     */
    public static function contentExtension(UploadedFile $file, array $allowed, string $field = 'file'): string
    {
        $ext = strtolower((string) $file->guessExtension());
        $ext = ['jpeg' => 'jpg', 'jpe' => 'jpg'][$ext] ?? $ext;

        if (! in_array($ext, $allowed, true)) {
            self::refuse($file, $field);
        }

        return $ext;
    }

    /** Content-derived image extension; refuses anything that is not a raster image. */
    public static function imageExtension(UploadedFile $file, string $field = 'file'): string
    {
        $ext = strtolower((string) $file->guessExtension());
        $ext = ['jpeg' => 'jpg', 'jpe' => 'jpg'][$ext] ?? $ext;

        if (! in_array($ext, self::IMAGE, true)) {
            self::refuse($file, $field);
        }

        return $ext;
    }

    /** Allow-listed document extension; refuses executable/markup types and empty extensions. */
    public static function documentExtension(UploadedFile $file, string $field = 'file', ?array $allowed = null): string
    {
        $ext = strtolower(trim((string) $file->getClientOriginalExtension()));

        if ($ext === '' || ! in_array($ext, $allowed ?? self::DOCUMENT, true)) {
            self::refuse($file, $field);
        }

        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    /** Random, filesystem-safe name with the given (already validated) extension. */
    public static function name(string $extension, string $prefix = ''): string
    {
        return $prefix . Str::random(24) . '.' . $extension;
    }

    /** Human-readable original name, safe to show/store (no paths, no control chars). */
    public static function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit($name, 191, '');
    }

    private static function refuse(UploadedFile $file, string $field): never
    {
        throw ValidationException::withMessages([
            $field => 'The file "' . self::displayName($file) . '" is not an allowed file type.',
        ]);
    }
}
