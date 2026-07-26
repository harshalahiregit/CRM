<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single voter's thumbs up/down on a KB article (BUG-06).
 *
 * Exists purely to enforce one-vote-per-voter; the aggregate counters live on
 * kb_articles and stay the read path.
 */
class KbArticleVote extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_article_votes';

    protected $fillable = ['tenant_id', 'article_id', 'fingerprint', 'vote'];

    public function article()
    {
        return $this->belongsTo(KbArticle::class, 'article_id');
    }
}
