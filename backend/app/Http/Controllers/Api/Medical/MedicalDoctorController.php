<?php

namespace App\Http\Controllers\Api\Medical;

use App\Http\Controllers\Controller;
use App\Models\Medical\MedicalDoctorProfile;
use App\Models\User;
use App\Services\Auth\PasswordSetupLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * The doctor directory — admin-side management of who may examine.
 *
 * Creating a doctor creates two things at once: a login (role `doctor`) and the
 * practising profile that login signs with. They are made together because
 * either one alone is useless — a login that cannot sign, or a licence attached
 * to nobody.
 *
 * Deactivation never deletes: certificates already issued must keep naming the
 * doctor who signed them, so the profile is flagged inactive and the login
 * suspended instead.
 */
class MedicalDoctorController extends Controller
{
    public function __construct(private PasswordSetupLink $links) {}

    public function index(Request $request)
    {
        $rows = MedicalDoctorProfile::forTenant($request->user()->tenant_id)
            ->with('user:id,name,email,phone,status,role')
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', (bool) $request->query('active')))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:160',
            'email'          => 'required|email|max:190|unique:users,email',
            'phone'          => 'nullable|string|max:40',
            'password'       => 'nullable|string|min:8|max:72',
            'license_no'     => 'required|string|max:60',
            'council'        => 'nullable|string|max:160',
            'qualification'  => 'nullable|string|max:160',
            'designation'    => 'nullable|string|max:120',
            'clinic_name'    => 'nullable|string|max:160',
            'clinic_address' => 'nullable|string|max:255',
            'modules'        => 'nullable|array',
            'modules.*'      => ['string', Rule::in(['tpv', 'purchase'])],
            // How the doctor comes by their password. 'invite' is the default
            // and the one to prefer: the doctor sets it themselves and nobody
            // else ever learns it, so a certificate signed by them could not
            // have been signed by the admin who created the account.
            //
            // 'password' stays for sites where email is unreliable, which on a
            // construction site it often is.
            'delivery'       => ['nullable', Rule::in(['invite', 'password'])],
        ], [
            // The default wording for a taken e-mail is "The email has already
            // been taken", which does not say WHERE. The address is the login,
            // so the clash is with any account in the system — a colleague who
            // is already staff, or this doctor entered once before.
            'email.unique'      => 'This email address already has a login. Use a different address, or edit the existing account.',
            'license_no.required' => 'The licence number is required — it is printed on every certificate this doctor signs.',
            'password.min'      => 'A password you set yourself must be at least 8 characters. Leave it blank to have one generated.',
        ]);

        $tenantId = $request->user()->tenant_id;

        // Invite unless an explicit password was asked for. Supplying one is
        // itself a request for the password route — an admin who typed a
        // password means to hand it over.
        $invite = ($data['delivery'] ?? ($data['password'] ?? null ? 'password' : 'invite')) === 'invite';

        // On the invite route the account still needs SOMETHING in the column:
        // a long random string nobody has ever seen, so the only way in is the
        // link. A generated password on the other route is returned once, to
        // the admin who created the account, and never stored in the clear.
        $password = $data['password'] ?? str()->password(12, true, true, false);

        $profile = DB::transaction(function () use ($data, $tenantId, $password) {
            $user = User::create([
                'tenant_id' => $tenantId,
                'name'      => $data['name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? null,
                'password'  => Hash::make($password),
                'role'      => 'doctor',
                'status'    => 'active',
            ]);

            return MedicalDoctorProfile::create([
                'tenant_id'      => $tenantId,
                'user_id'        => $user->id,
                'license_no'     => $data['license_no'],
                'council'        => $data['council'] ?? null,
                'qualification'  => $data['qualification'] ?? null,
                'designation'    => $data['designation'] ?? null,
                'clinic_name'    => $data['clinic_name'] ?? null,
                'clinic_address' => $data['clinic_address'] ?? null,
                'phone'          => $data['phone'] ?? null,
                // Empty means both sides, which is the usual arrangement.
                'modules'        => $data['modules'] ?? null,
                'is_active'      => true,
            ]);
        });

        if ($invite) {
            $sent = $this->links->invite($profile->user, 'doctor login', $request->user()->name);

            return response()->json([
                'message' => $sent
                    ? 'Doctor login created. An invitation to set a password has been emailed.'
                    : 'Doctor login created, but the invitation could not be sent. Use Resend invitation, or set a password instead.',
                'data'    => $profile->load('user:id,name,email,phone,status,role'),
                // Deliberately absent: on this route nobody but the doctor ever
                // learns the password, which is what makes their signature theirs.
                'invited' => $sent,
            ], 201);
        }

        return response()->json([
            'message'            => 'Doctor login created.',
            'data'               => $profile->load('user:id,name,email,phone,status,role'),
            'temporary_password' => $password,
            'invited'            => false,
        ], 201);
    }

    /**
     * Send (or re-send) the invitation to set a password.
     *
     * Needed because an invitation can fail to arrive for reasons that have
     * nothing to do with this system — a wrong address, a full mailbox, an
     * expired link. Without it the only remedy was to reset the password and
     * read it out, which is the very thing the invite route exists to avoid.
     */
    public function invite(Request $request, int $id)
    {
        $profile = $this->find($request, $id);
        $user    = $profile->user;

        abort_unless($user, 404, 'This doctor has no login to invite.');
        abort_if($user->status !== 'active', 422, 'This account is deactivated. Reactivate it before inviting.');

        $sent = $this->links->invite($user, 'doctor login', $request->user()->name);

        return response()->json([
            'message' => $sent
                ? 'Invitation sent. The link can be used once and expires in '.$this->links->expiryMinutes().' minutes.'
                : 'The invitation could not be sent. Check the email address, or set a password instead.',
            'invited' => $sent,
        ], $sent ? 200 : 502);
    }

    /**
     * Make an EXISTING user a doctor.
     *
     * A company doctor who already had a staff login could not become one:
     * creating required an unused email address, and staff management can only
     * set admin or staff. The only way through was a second account on a second
     * address — two logins for one person, and certificates attributed to
     * whichever they happened to be signed in as.
     *
     * No new password and no invitation: they already have a way in. Only the
     * role changes, and the practising profile is attached to it.
     */
    public function promote(Request $request)
    {
        $data = $request->validate([
            'user_id'        => 'required|integer',
            'license_no'     => 'required|string|max:60',
            'council'        => 'nullable|string|max:160',
            'qualification'  => 'nullable|string|max:160',
            'designation'    => 'nullable|string|max:120',
            'clinic_name'    => 'nullable|string|max:160',
            'clinic_address' => 'nullable|string|max:255',
            'modules'        => 'nullable|array',
            'modules.*'      => ['string', Rule::in(['tpv', 'purchase'])],
        ], [
            'license_no.required' => 'The licence number is required — it is printed on every certificate this doctor signs.',
        ]);

        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)->find($data['user_id']);
        abort_unless($user, 404, 'That person is not in this workspace.');

        abort_if(
            MedicalDoctorProfile::forTenant($tenantId)->where('user_id', $user->id)->exists(),
            422,
            'That person is already a doctor.',
        );

        // Portal logins belong to their own registers and are not people of this
        // company; promoting one would put a vendor inside the workspace.
        abort_unless(
            in_array($user->role, ['admin', 'staff'], true),
            422,
            'Only an internal team member can be made a doctor.',
        );

        $profile = DB::transaction(function () use ($user, $data, $tenantId) {
            // An admin keeps their admin role: taking it away to make somebody a
            // doctor would quietly remove their access to everything else.
            if ($user->role === 'staff') {
                $user->forceFill(['role' => 'doctor'])->save();
            }

            return MedicalDoctorProfile::create([
                'tenant_id'      => $tenantId,
                'user_id'        => $user->id,
                'license_no'     => $data['license_no'],
                'council'        => $data['council'] ?? null,
                'qualification'  => $data['qualification'] ?? null,
                'designation'    => $data['designation'] ?? null,
                'clinic_name'    => $data['clinic_name'] ?? null,
                'clinic_address' => $data['clinic_address'] ?? null,
                'phone'          => $user->phone,
                'modules'        => $data['modules'] ?? null,
                'is_active'      => true,
            ]);
        });

        return response()->json([
            'message' => $profile->user->role === 'doctor'
                ? 'This person can now sign in as a doctor with their existing password.'
                : 'Doctor profile added. They keep their admin access and can now examine as well.',
            'data'    => $profile->load('user:id,name,email,phone,status,role'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $profile = $this->find($request, $id);

        $data = $request->validate([
            'name'           => 'sometimes|string|max:160',
            'phone'          => 'nullable|string|max:40',
            'license_no'     => 'sometimes|string|max:60',
            'council'        => 'nullable|string|max:160',
            'qualification'  => 'nullable|string|max:160',
            'designation'    => 'nullable|string|max:120',
            'clinic_name'    => 'nullable|string|max:160',
            'clinic_address' => 'nullable|string|max:255',
            'modules'        => 'nullable|array',
            'modules.*'      => ['string', Rule::in(['tpv', 'purchase'])],
            'is_active'      => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($profile, $data) {
            $profile->update(collect($data)->except('name')->all());

            if (isset($data['name']) || array_key_exists('is_active', $data)) {
                $profile->user?->update(array_filter([
                    'name'   => $data['name'] ?? null,
                    // Suspending the profile suspends the login with it —
                    // otherwise a "deactivated" doctor could still sign in.
                    'status' => array_key_exists('is_active', $data)
                        ? ($data['is_active'] ? 'active' : 'inactive')
                        : null,
                ], fn ($v) => $v !== null));
            }
        });

        return response()->json([
            'message' => 'Doctor updated.',
            'data'    => $profile->fresh()->load('user:id,name,email,phone,status,role'),
        ]);
    }

    /**
     * Issue a new password for a doctor's login.
     *
     * The password set at creation is shown once and stored only as a hash, so
     * an admin who did not write it down had no way back in — and there was no
     * reset here, which made a mislaid password the end of the account. The
     * doctor's identity is not reissued: the same login, the same licence, the
     * same certificates, one new credential.
     */
    public function resetPassword(Request $request, int $id)
    {
        $profile = $this->find($request, $id);
        $user = $profile->user;

        abort_unless($user, 404, 'This doctor has no login to reset.');

        $data = $request->validate([
            'password' => 'nullable|string|min:8|max:72',
        ], [
            'password.min' => 'A password you set yourself must be at least 8 characters. Leave it blank to have one generated.',
        ]);

        $password = $data['password'] ?? str()->password(12, true, true, false);
        $user->update(['password' => Hash::make($password)]);

        // Every token the old password issued is now stale — a reset that left
        // live sessions open would not be a reset.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset. Hand it to the doctor now — it cannot be shown again.',
            'temporary_password' => $password,
        ]);
    }

    /** Deactivate — never delete; issued certificates must keep their author. */
    public function destroy(Request $request, int $id)
    {
        $profile = $this->find($request, $id);

        DB::transaction(function () use ($profile) {
            $profile->update(['is_active' => false]);
            $profile->user?->update(['status' => 'inactive']);
        });

        return response()->json(['message' => 'Doctor deactivated.']);
    }

    private function find(Request $request, int $id): MedicalDoctorProfile
    {
        $profile = MedicalDoctorProfile::forTenant($request->user()->tenant_id)->with('user')->find($id);

        abort_unless($profile, 404, 'Doctor not found.');

        return $profile;
    }
}
