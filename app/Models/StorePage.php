<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class StorePage extends Model
{
    use HasTranslations;

    /** Customer-facing copy that can be translated per locale. */
    public const TRANSLATABLE = ['title', 'content', 'seo_title', 'seo_description'];

    protected $fillable = [
        'title', 'slug', 'content', 'seo_title', 'seo_description', 'published',
        'title_translations', 'content_translations',
        'seo_title_translations', 'seo_description_translations',
    ];

    protected $casts = [
        'published' => 'boolean',
        'title_translations' => 'array',
        'content_translations' => 'array',
        'seo_title_translations' => 'array',
        'seo_description_translations' => 'array',
    ];

    protected static function booted(): void
    {
        // Page content is rendered raw on the public storefront ({!! !!}), so
        // it is cleaned on every write path (API, seeders, imports).
        static::saving(function (StorePage $page) {
            if ($page->isDirty('content')) {
                $page->content = \App\Support\HtmlSanitizer::clean($page->content);
            }
            if ($page->isDirty('content_translations')) {
                $page->content_translations = \App\Support\HtmlSanitizer::cleanMap($page->content_translations);
            }
        });
    }
}
