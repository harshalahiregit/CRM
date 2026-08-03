<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'subject', 'description', 'status', 'priority',
        'assigned_to', 'created_by', 'customer_id', 'department_id', 'service_id', 'project_id', 'due_date', 'source',
        'requester_name', 'requester_email',
        'merged_into_id', 'ai_summary', 'ai_summary_at', 'email_token',
        'first_responded_at', 'resolved_at', 'last_reply_at', 'sla_paused_at', 'sla_paused_seconds',
    ];

    protected static function booted(): void
    {
        // Every ticket gets an unforgeable email-threading token so inbound
        // replies can be mapped back to it. See the add_email_token migration.
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->email_token)) {
                $ticket->email_token = \Illuminate\Support\Str::random(32);
            }
        });
    }

    /** Reply-To local part that ties an inbound email back to this ticket. */
    public function emailThreadRef(): string
    {
        return "{$this->id}-{$this->email_token}";
    }

    /**
     * Plus-addressed Reply-To that routes a customer's email reply back to this
     * ticket: support+{id}-{token}@domain.
     *
     * This lives on the model because every threaded helpdesk mail needs it. It
     * used to be a free function defined at the bottom of TicketReceivedMail.php,
     * which meant it only existed if that class had been autoloaded in the current
     * request — so replying to a ticket (a request that never loads the
     * acknowledgment mail) threw "undefined function threadedReplyTo()" and the
     * reply email silently never sent.
     */
    public function threadedReplyTo(): string
    {
        $base = (string) config('helpdesk.inbound_address');

        return str_contains($base, '@')
            ? preg_replace('/@/', '+'.$this->emailThreadRef().'@', $base, 1)
            : $base;
    }

    protected $casts = [
        'due_date'           => 'datetime',
        'ai_summary_at'      => 'datetime',
        'first_responded_at' => 'datetime',
        'resolved_at'        => 'datetime',
        'last_reply_at'      => 'datetime',
        'sla_paused_at'      => 'datetime',
        'sla_paused_seconds' => 'integer',
    ];

    // Computed SLA snapshot attached to every serialized ticket.
    protected $appends = ['sla'];

    public function getSlaAttribute(): array
    {
        return app(\App\Services\Helpdesk\SlaService::class)->compute($this);
    }

    /* ── Scopes ─────────────────────────────────────────────────── */
    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopePriority($query, ?string $priority)
    {
        return $priority ? $query->where('priority', $priority) : $query;
    }

    /* ── Relationships ──────────────────────────────────────────── */
    public function replies()
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at');
    }

    public function feedback()
    {
        return $this->hasOne(TicketFeedback::class);
    }

    // Assignee is a shared auth user (staff/agent), so a real relation is fine.
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tags()
    {
        return $this->belongsToMany(TicketTag::class, 'ticket_tag_pivot', 'ticket_id', 'tag_id')->withTimestamps();
    }

    public function service()
    {
        return $this->belongsTo(TicketService::class, 'service_id');
    }

    // NOTE: no customer() relation — customers belong to Zafar's module. Resolve
    // customer data through CustomerServiceContract, never an Eloquent join here.
}
