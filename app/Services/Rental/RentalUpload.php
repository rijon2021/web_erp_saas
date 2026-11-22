<?php

namespace App\Services\Rental;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Safe file extensions for rental uploads (tenant folders images/tenants/{id}/rental_*).
 *
 * The stored extension is taken from the file's CONTENT (guessExtension),
 * never from the name the browser sent: a file that passes the `image` or
 * `mimes` rule by its content but is named "x.html" must not be saved as
 * .html under public/, where it would run as a page on the app's origin.
 */
class RentalUpload
{
    private const IMAGE = ['jpg', 'png', 'gif', 'webp', 'bmp'];

    private const KINDS = [
        'image' => self::IMAGE,
        'id' => ['jpg', 'png', 'webp', 'pdf'],
        'receipt' => ['jpg', 'png', 'webp', 'pdf'],
        'document' => ['jpg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
    ];

    /** Extension to store the file under; refuses anything outside the kind's list. */
    public static function extension(UploadedFile $file, string $kind = 'image'): string
    {
        $ext = strtolower((string) $file->guessExtension());
        $ext = ['jpeg' => 'jpg', 'jpe' => 'jpg'][$ext] ?? $ext;

        if (! in_array($ext, self::KINDS[$kind] ?? self::IMAGE, true)) {
            throw ValidationException::withMessages([
                'file' => 'The file "' . $file->getClientOriginalName() . '" is not an allowed file type.',
            ]);
        }

        return $ext;
    }
}
