<?php

namespace App\Services\Access;

use App\Exceptions\BusinessException;
use App\Models\Access\Department;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The departments an admin maintains from Settings.
 *
 * Nothing in use is deleted — a department with people in it would leave those
 * people pointing at nothing.
 *
 * This class used to maintain staff ROLES as well, in access_roles. That was a
 * second catalogue beside staff_roles: both wrote users.internal_role, only one
 * carried permissions, and so the other could never be the authority. Roles
 * moved to staff_roles, which owns the slug, the permissions and the scope
 * together. See the note where they were.
 *
 * What remains here is the same duplication one table over —
 * access_departments (0 rows) beside hr_departments (read by 21 files) — and is
 * deliberately untouched, because that is its own cleanup with its own risks.
 */
class AccessCatalogService
{
    /*
     | ROLES WERE REMOVED FROM HERE.
     |
     | This maintained a second staff-role catalogue in access_roles beside the
     | live one in staff_roles. Both wrote users.internal_role and only one
     | carried permissions, so this one could never be the authority: it was a
     | role manager that granted nothing, with a routed screen and no rows.
     |
     | staff_roles owns the vocabulary now — it holds the slug AND the
     | permissions AND (since the scope foundation) the width of the view. The
     | rules this class enforced are enforced there: StaffRole's docblock fixes
     | the slug at creation for the same reason, and StaffRoleService::delete()
     | refuses to remove a role people still hold.
     |
     | The two slugs that existed ONLY here — `hr` and `manager`, which
     | routes/sangoetrack.php gates on — are now vocabulary-only entries in
     | StaffRoleTemplate, so that gate keeps working with no change to it.
     |
     | The access_roles table is deliberately still there. Removing the way to
     | create rows is what stops a second source of authority; dropping data
     | this environment cannot inspect is a separate, later decision.
     */

    /* ── Departments ────────────────────────────────────────────────────── */

    public function departments(int $tenantId)
    {
        return Department::forTenant($tenantId)->with('head:id,name')->orderBy('name')->get()
            ->map(fn (Department $d) => $d->toArray() + ['user_count' => $d->user_count]);
    }

    public function createDepartment(int $tenantId, array $data): Department
    {
        if (Department::forTenant($tenantId)->where('name', $data['name'])->exists()) {
            throw new BusinessException("A department called \"{$data['name']}\" already exists.", 422);
        }

        return Department::create([
            'tenant_id'    => $tenantId,
            'name'         => $data['name'],
            'code'         => $data['code'] ?? null,
            'description'  => $data['description'] ?? null,
            'head_user_id' => $this->headFor($tenantId, $data['head_user_id'] ?? null),
            'is_active'    => $data['is_active'] ?? true,
        ]);
    }

    public function updateDepartment(Department $dept, array $data): Department
    {
        $newName = $data['name'] ?? $dept->name;

        if ($newName !== $dept->name
            && Department::forTenant($dept->tenant_id)->where('name', $newName)->exists()) {
            throw new BusinessException("A department called \"{$newName}\" already exists.", 422);
        }

        $dept->update([
            'name'         => $newName,
            'code'         => array_key_exists('code', $data) ? $data['code'] : $dept->code,
            'description'  => array_key_exists('description', $data) ? $data['description'] : $dept->description,
            'head_user_id' => array_key_exists('head_user_id', $data)
                ? $this->headFor($dept->tenant_id, $data['head_user_id'])
                : $dept->head_user_id,
            'is_active'    => $data['is_active'] ?? $dept->is_active,
        ]);

        return $dept->fresh('head');
    }

    public function deleteDepartment(Department $dept): void
    {
        $count = $dept->user_count;
        if ($count > 0) {
            throw new BusinessException(
                "{$count} ".($count === 1 ? 'person is' : 'people are')." in this department. Move them first.",
                422,
            );
        }

        $dept->delete();
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function slugFor(string $value): string
    {
        return Str::of($value)->lower()->replace(['-', ' '], '_')->replaceMatches('/[^a-z0-9_]/', '')->toString();
    }

    /** A head must be a real user of this tenant, or nobody. */
    private function headFor(int $tenantId, $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        return User::where('tenant_id', $tenantId)->whereKey($userId)->exists() ? (int) $userId : null;
    }
}
