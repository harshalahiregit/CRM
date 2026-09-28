<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\DriverProfile;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * STOS-FLEET — the driver, acting on their OWN record from the phone app.
 *
 * Everything here is SCOPE_OWN: it resolves the signed-in user to the one driver
 * they are, and only ever reads or writes that driver's data. A user who is not
 * a driver (an office login, say) is refused. This is the door the Sangoé Driver
 * app uses for the driver's profile and their own documents; the admin's verify
 * and cross-driver views stay on the role-gated fleet endpoints.
 */
class DriverSelfService
{
    public function __construct(private DriverDocumentService $documents)
    {
    }

    /**
     * Which driver is this login? Returns the directory reference and the
     * profile, or refuses if the user is not a registered driver.
     *
     * @return array{source:string, source_id:int, person:object, profile:DriverProfile}
     */
    public function resolve(int $companyId, int $userId): array
    {
        $person = DB::table('stos_drivers')
            ->where('company_id', $companyId)->where('user_id', $userId)
            ->whereNull('deleted_at')->first();

        if (! $person) {
            throw new BusinessException('This account is not a driver profile.', 403);
        }

        $profile = DriverProfile::where('company_id', $companyId)
            ->where('source', 'stos')->where('source_id', $person->id)->first();

        if (! $profile) {
            throw new BusinessException('Your driver profile is not set up yet. Contact the office.', 409);
        }

        return ['source' => 'stos', 'source_id' => (int) $person->id, 'person' => $person, 'profile' => $profile];
    }

    /** The driver's own profile + documents + whether they are cleared to drive. */
    public function myProfile(int $companyId, int $userId): array
    {
        $me = $this->resolve($companyId, $userId);
        $docs = $this->documents->forDriver($companyId, $me['source'], $me['source_id']);

        return [
            'profile' => [
                'name'           => $me['person']->name,
                'phone'          => $me['person']->phone,
                'licence_number' => $me['profile']->licence_number,
                'licence_class'  => $me['profile']->licence_class,
                'licence_expiry' => optional($me['profile']->licence_expiry)->toDateString(),
                'status'         => $me['profile']->status,
            ],
            'documents'   => $docs['documents'],
            'gating'      => $docs['gating'],
            'types'       => $docs['types'],
            'eligibility' => $this->eligibility($docs['gating']),
        ];
    }

    /** File a document the driver photographed/picked, as PENDING for the office. */
    public function uploadDocument(int $companyId, int $userId, array $data, $actor = null): array
    {
        $me = $this->resolve($companyId, $userId);

        return $this->documents->file($companyId, $me['source'], $me['source_id'], $data['document_type'], $data, $actor);
    }

    /**
     * Is the driver cleared to take a trip? Only when every dispatch-gating
     * document is verified. Anything still pending or missing keeps them blocked
     * and is named so the app can tell them exactly what to do.
     */
    private function eligibility(array $gating): array
    {
        $blocking = [];
        foreach ($gating as $g) {
            if (empty($g['verified'])) {
                $blocking[] = [
                    'label'    => $g['label'],
                    'awaiting' => ! empty($g['awaiting']), // uploaded, waiting on the office
                ];
            }
        }

        return [
            'eligible'  => count($blocking) === 0,
            'blocking'  => $blocking,
            'message'   => count($blocking) === 0
                ? 'You are cleared to drive.'
                : 'Upload and get these approved before you can start a trip.',
        ];
    }
}
