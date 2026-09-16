<?php

namespace App\Services\Contract;

use App\Exceptions\BusinessException;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractSignature;
use App\Support\Contract\ContractParty;
use App\Support\Contract\ContractStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signing a contract, and keeping the evidence that it was signed.
 *
 * Both parties sign, and neither can overwrite the other: one row per party,
 * enforced by a unique index rather than by whichever service happens to be
 * writing. A signature that can be replaced silently is not evidence of
 * anything.
 */
class ContractSigningService
{
    /**
     * Record that a party OPENED the contract.
     *
     * The brief asks for the exact time of viewing as well as signing, and they
     * are different facts — "I saw it on Monday and signed on Thursday" is the
     * sort of thing a dispute turns on. Only the FIRST view is kept: re-reading
     * a contract does not change when you first saw it.
     */
    public function recordView(Contract $contract, string $party, ?string $ip): ContractSignature
    {
        $this->assertParty($party);

        $row = $this->rowFor($contract, $party);

        if (! $row->viewed_at) {
            $row->forceFill(['viewed_at' => now(), 'viewed_ip' => $ip])->save();
        }

        return $row;
    }

    /**
     * Sign as one party.
     *
     * @param  array  $sig  name, email, method, image, latitude, longitude, location_label
     */
    public function sign(Contract $contract, string $party, array $sig, ?int $userId, ?string $ip, ?string $userAgent): Contract
    {
        $this->assertParty($party);

        if (empty($sig['name'])) {
            throw new BusinessException("A signature needs the signer's name.", 422);
        }

        $method = $sig['method'] ?? 'draw';
        if (! in_array($method, ContractParty::METHODS, true)) {
            throw new BusinessException('Unknown signature method.', 422);
        }

        // A drawn or uploaded signature must carry an image; a typed one is the
        // name itself and a stamp is generated. Without this an empty canvas
        // still marks the contract signed.
        if (in_array($method, ['draw', 'upload'], true) && empty($sig['image'])) {
            throw new BusinessException('The signature image is missing — draw or upload it again.', 422);
        }

        // A cancelled or expired agreement is over; signing it would bring a
        // dead contract back to life without anybody deciding to.
        if (in_array($contract->status, ContractStatus::CLOSED, true)) {
            throw new BusinessException("This contract is {$contract->status} and can no longer be signed.", 422);
        }

        return DB::transaction(function () use ($contract, $party, $sig, $userId, $ip, $userAgent, $method) {
            // Lock the row so two parties signing at the same moment cannot both
            // read "not yet fully signed" and leave fully_signed_at unset.
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $row = $this->rowFor($locked, $party);

            if ($row->signed_at) {
                throw new BusinessException(
                    ContractParty::label($party).' has already signed this contract on '
                    .$row->signed_at->format('d M Y H:i').'.', 422
                );
            }

            $row->forceFill([
                'signer_name'    => $sig['name'],
                'signer_email'   => $sig['email'] ?? null,
                'user_id'        => $userId,
                'method'         => $method,
                'image'          => $sig['image'] ?? null,
                'signed_at'      => now(),
                'signed_ip'      => $ip,
                'user_agent'     => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'latitude'       => $sig['latitude'] ?? null,
                'longitude'      => $sig['longitude'] ?? null,
                'location_label' => $sig['location_label'] ?? null,
                'certificate_no' => $this->mintCertificate($locked, $party),
                // Somebody who signs without a view ever being recorded still
                // viewed it at that moment. Leaving it null would read as
                // "signed without looking", a stronger claim than we can make.
                'viewed_at'      => $row->viewed_at ?? now(),
                'viewed_ip'      => $row->viewed_ip ?? $ip,
            ])->save();

            $this->refreshState($locked);

            return $locked->fresh(['signatures', 'pages', 'category']);
        });
    }

    /** Both parties signed? This is what "in force" means. */
    public function isFullySigned(Contract $contract): bool
    {
        $signed = $contract->signatures()->whereNotNull('signed_at')->pluck('signer_party')->all();

        return count(array_intersect(ContractParty::ALL, $signed)) === count(ContractParty::ALL);
    }

    /**
     * Keep the contract's own summary honest.
     *
     * A contract becomes SIGNED only when both sides have put their names to it;
     * one signature is an offer, not an agreement. It becomes ACTIVE when it is
     * also in force by date — signed in March for an April start is signed but
     * not yet active, and anything asking "may we work under this" means active.
     */
    private function refreshState(Contract $contract): void
    {
        if (! $this->isFullySigned($contract)) {
            return;
        }

        $inForce = $contract->start_date === null || ! $contract->start_date->isFuture();

        $contract->forceFill([
            'fully_signed_at' => $contract->fully_signed_at ?? now(),
            'status' => in_array($contract->status, ContractStatus::CLOSED, true)
                ? $contract->status
                : ($inForce ? ContractStatus::ACTIVE : ContractStatus::SIGNED),
        ])->save();
    }

    /**
     * The issuance certificate number for this party's signature.
     *
     * Built from the contract reference and the party so it is readable and
     * reproducible — somebody holding a printed page can quote it back — with a
     * random tail so it cannot be derived from the reference alone.
     */
    private function mintCertificate(Contract $contract, string $party): string
    {
        return strtoupper(sprintf(
            '%s-%s-%s',
            $contract->reference_no ?: 'CTR-'.$contract->id,
            $party === ContractParty::COMPANY ? 'CO' : 'PT',
            Str::random(6),
        ));
    }

    /** The row for this party, created on first touch. */
    private function rowFor(Contract $contract, string $party): ContractSignature
    {
        return ContractSignature::firstOrCreate(
            ['contract_id' => $contract->id, 'signer_party' => $party],
            ['tenant_id' => $contract->tenant_id],
        );
    }

    private function assertParty(string $party): void
    {
        if (! ContractParty::isValid($party)) {
            throw new BusinessException('Unknown signing party.', 422);
        }
    }
}
