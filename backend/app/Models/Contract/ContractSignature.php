<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Contract\ContractParty;
use Illuminate\Database\Eloquent\Model;

/**
 * One party's signature, with the evidence that it was theirs.
 *
 * A signature is worth only as much as what surrounds it: when the signer opened
 * the document, when they signed, from which address, from where. All of that
 * hangs here rather than on the contract, because two signers produce two sets
 * of it and a column on the contract could hold only one — which is precisely
 * how the older single-signature design lost the first signer's evidence to the
 * second.
 *
 * A row appears as soon as a party OPENS the contract, so the existence of a row
 * is not a signature. `signed_at` is.
 */
class ContractSignature extends Model
{
    use BelongsToTenant;

    protected $table = 'contract_signatures';

    protected $fillable = [
        'tenant_id', 'contract_id', 'signer_party',
        'signer_name', 'signer_email', 'user_id',
        'method', 'image',
        'viewed_at', 'viewed_ip',
        'signed_at', 'signed_ip', 'user_agent',
        'latitude', 'longitude', 'location_label',
        'certificate_no',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'latitude'  => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    protected $appends = ['party', 'party_label', 'is_signed', 'place'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * `signer_party` under the name every client already reads it by.
     *
     * Three surfaces hand out this same field: the admin list serialises the
     * model as-is (signer_party), while the sign portal and the party portal
     * both hand-map it to `party`. Two of the three screens matched on `party`,
     * so on the admin list `find(s => s.party === 'company')` matched nothing
     * and BOTH signature chips sat on the pending clock for ever -- on a
     * contract whose status was already Active. Nothing threw, nothing logged,
     * and the row simply lied. Appending it means the two spellings agree
     * wherever a signature is serialised.
     */
    public function getPartyAttribute(): ?string
    {
        return $this->signer_party;
    }

    public function getPartyLabelAttribute(): string
    {
        return ContractParty::label($this->signer_party);
    }

    public function getIsSignedAttribute(): bool
    {
        return $this->signed_at !== null;
    }

    /**
     * Where they signed, in words if we have them and coordinates if not.
     *
     * Null is a real answer: the signer refused the browser's location prompt,
     * and the signature is valid regardless. The PDF prints "not provided"
     * rather than leaving a blank that reads like missing data.
     */
    public function getPlaceAttribute(): ?string
    {
        if ($this->location_label) {
            return $this->location_label;
        }

        return $this->latitude !== null && $this->longitude !== null
            ? sprintf('%.5f, %.5f', $this->latitude, $this->longitude)
            : null;
    }
}
