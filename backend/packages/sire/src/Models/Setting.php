<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SIRE — one configuration value for one tenant.
 *
 * The fallback that lets SIRE install into a host with no settings system. A
 * host that has one implements SireSettingsProvider and this table stays empty.
 *
 * Not audited, deliberately: settings are configuration rather than events, and
 * an audit row per dashboard render — every read touches the same handful of
 * keys — would drown the trail that matters.
 */
class Setting extends Model
{
    use BelongsToSireTenant;

    protected $table = 'sire_settings';

    protected $fillable = ['tenant_id', 'key', 'value'];

    /** Never expose the raw store through an API response. */
    protected $hidden = ['id'];
}
