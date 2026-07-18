<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single reader's 1-5 star rating (+ optional comment) on a KB article (REQ-11).
 *
 * Sits alongside kb_article_votes (thumbs); the two are independent feedback
 * signals. One row per reader is enforced by the unique (article_id, fingerprint)
 * index — a re-submission updates this row rather than adding another.
 *
 * The table carries only created_at (a rating has no meaningful "updated" moment
 * for the reader), so UPDATED_AT is disabled.
 */
class KbArticleFeedback extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_article_feedback';

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'article_id', 'public_slug', 'rating', 'comment', 'fingerprint'];

    protected $casts = [
        'rating'     => 'integer',
        'created_at' => 'datetime',
    ];

    public function article()
    {
        return $this->belongsTo(KbArticle::class, 'article_id');
    }
}
