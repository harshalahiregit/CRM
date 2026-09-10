<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — a link to an article in the EXISTING Helpdesk knowledge base.
 *
 * `kb_article_id` is a logical link to kb_articles, following the house rule of
 * no cross-module foreign keys. SIRE has no KB of its own and must not grow one:
 * an article written here is found by KB search and read by Helpdesk agents,
 * which is the entire reason for linking rather than duplicating.
 */
class KbLink extends Model
{
    use BelongsToSireTenant;

    public const TYPE_RESOLUTION      = 'resolution';
    public const TYPE_KNOWN_ISSUE     = 'known_issue';
    public const TYPE_PREVENTION      = 'prevention';
    public const TYPE_TROUBLESHOOTING = 'troubleshooting';
    public const TYPE_REFERENCE       = 'reference';

    public const TYPES = [
        self::TYPE_RESOLUTION, self::TYPE_KNOWN_ISSUE, self::TYPE_PREVENTION,
        self::TYPE_TROUBLESHOOTING, self::TYPE_REFERENCE,
    ];

    public const LABELS = [
        self::TYPE_RESOLUTION      => 'Resolution',
        self::TYPE_KNOWN_ISSUE     => 'Known issue',
        self::TYPE_PREVENTION      => 'Prevention',
        self::TYPE_TROUBLESHOOTING => 'Troubleshooting',
        self::TYPE_REFERENCE       => 'Reference',
    ];

    protected $table = 'sire_kb_links';

    protected $guarded = ['id'];

    protected $casts = ['created_from_issue' => 'boolean'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'created_by');
    }

    /**
     * Deliberately NOT an Eloquent relation to Helpdesk's Article model. Reaching
     * across a module boundary in a relation invites eager-loading a table SIRE
     * does not own; the service fetches through SireKnowledgeProvider instead.
     */
    public function articleId(): int
    {
        return (int) $this->kb_article_id;
    }
}
