<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Public share link for a document sent to a customer or supplier.
 *
 * The link is /api/share/{SHARE_TYPE}/{token}: the random token is the only
 * key, so the link cannot be guessed from the document id. The using model
 * declares `const SHARE_TYPE`, which must match a key of
 * DocumentShareController::TYPES.
 */
trait HasShareToken
{
    /** Keep the token out of every JSON payload the model ends up in. */
    public function initializeHasShareToken(): void
    {
        $this->makeHidden('share_token');
    }

    /**
     * The document's share token, created on first use. The conditional update
     * keeps two concurrent sends from minting different tokens and breaking
     * the link that went out first.
     */
    public function shareToken(): string
    {
        if (empty($this->share_token)) {
            $this->newQueryWithoutScopes()
                ->whereKey($this->getKey())
                ->whereNull('share_token')
                ->toBase()
                ->update(['share_token' => Str::random(48)]);

            $this->share_token = $this->newQueryWithoutScopes()
                ->whereKey($this->getKey())
                ->value('share_token');
            $this->syncOriginalAttribute('share_token');
        }

        return $this->share_token;
    }

    public function shareUrl(): string
    {
        return url('/api/share/'.static::SHARE_TYPE.'/'.$this->shareToken());
    }
}
