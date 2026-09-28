<?php

namespace App\Support\Purchase;

use App\Exceptions\BusinessException;
use App\Models\TenantSetting;

/**
 * The prequalification questionnaire, as a thing an admin can edit.
 *
 * It lived in config/purchase_prequalification.php — 280 lines of PHP array,
 * identical for every workspace and changeable only by a developer with a
 * deploy. Two issues came out of that and they are the same issue:
 *
 *  • "Admin should have an option to set pre-qualification questions from the
 *    admin panel itself" — there was no such option anywhere.
 *  • "The form has too many drop-downs — how do I set the drop-down pointers
 *    from settings?" — every drop-down IS a question in this file, and the
 *    answer was that you could not, at all.
 *
 * So the config file stays as the DEFAULT — a workspace that has never edited
 * anything keeps exactly the questionnaire it has today — and a tenant that
 * saves its own gets that instead. Nothing is migrated and nothing changes for
 * anyone until they choose to change it.
 *
 * Stored as one JSON blob in tenant_settings rather than as tables of sections,
 * questions and options. The shape is a tree that is read whole and written
 * whole, never queried across, and three tables with two levels of ordering
 * would buy nothing but joins.
 */
final class PrequalificationCatalogue
{
    public const GROUP = 'purchase';

    public const KEY = 'prequalification_catalogue';

    /** The questionnaire for a tenant: its own if it has one, else the default. */
    public static function forTenant(int $tenantId): array
    {
        $stored = TenantSetting::where('tenant_id', $tenantId)
            ->where('group', self::GROUP)->where('key', self::KEY)
            ->value('value');

        if (! $stored) {
            return self::defaults();
        }

        $decoded = json_decode($stored, true);

        // A corrupt blob must not take the questionnaire down with it. Falling
        // back to the default means the form still works and the admin can
        // re-save; throwing here would break vendor assessment for everyone.
        return is_array($decoded) && $decoded !== [] ? $decoded : self::defaults();
    }

    /** The shipped questionnaire, untouched. */
    public static function defaults(): array
    {
        return config('purchase_prequalification.sections', []);
    }

    /** Has this tenant edited the questionnaire, or is it still the shipped one? */
    public static function isCustomised(int $tenantId): bool
    {
        return TenantSetting::where('tenant_id', $tenantId)
            ->where('group', self::GROUP)->where('key', self::KEY)->exists();
    }

    /**
     * Save a tenant's questionnaire.
     *
     * Validated hard, because a malformed catalogue is not a cosmetic problem:
     * the score is a sum over options divided by the maximum, so a section with
     * no questions, a question with no options or a non-numeric point value
     * turns every vendor's score into nonsense or a division by zero.
     */
    public static function save(int $tenantId, array $sections): array
    {
        $clean = self::sanitise($sections);

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'group' => self::GROUP, 'key' => self::KEY],
            ['value' => json_encode($clean), 'is_encrypted' => false, 'autoload' => false],
        );

        return $clean;
    }

    /** Drop the tenant's version and go back to the shipped questionnaire. */
    public static function reset(int $tenantId): array
    {
        TenantSetting::where('tenant_id', $tenantId)
            ->where('group', self::GROUP)->where('key', self::KEY)->delete();

        return self::defaults();
    }

    /**
     * Check and normalise a catalogue.
     *
     * @throws BusinessException with a message naming the section or question
     */
    private static function sanitise(array $sections): array
    {
        if (! $sections) {
            throw new BusinessException('A questionnaire needs at least one section.', 422);
        }

        $out = [];

        foreach ($sections as $sKey => $section) {
            $sKey = self::key((string) $sKey, 'section');
            $label = trim((string) ($section['label'] ?? ''));

            if ($label === '') {
                throw new BusinessException("Section '{$sKey}' needs a name.", 422);
            }

            $questions = $section['questions'] ?? [];
            if (! is_array($questions) || ! $questions) {
                throw new BusinessException("Section '{$label}' has no questions.", 422);
            }

            $outQuestions = [];

            foreach ($questions as $qKey => $question) {
                $qKey = self::key((string) $qKey, 'question');
                $qLabel = trim((string) ($question['label'] ?? ''));

                if ($qLabel === '') {
                    throw new BusinessException("A question in '{$label}' needs a name.", 422);
                }

                $options = $question['options'] ?? [];
                if (! is_array($options) || count($options) < 2) {
                    // One option is not a question — it scores the same for
                    // everybody and only lengthens the form.
                    throw new BusinessException("'{$qLabel}' needs at least two answers to choose from.", 422);
                }

                $outOptions = [];
                foreach ($options as $oKey => $option) {
                    $oKey = self::key((string) $oKey, 'answer');
                    $oLabel = trim((string) ($option['label'] ?? ''));
                    $points = $option['points'] ?? null;

                    if ($oLabel === '') {
                        throw new BusinessException("An answer under '{$qLabel}' needs a label.", 422);
                    }
                    if (! is_numeric($points) || $points < 0) {
                        throw new BusinessException("The score for '{$oLabel}' must be a number of 0 or more.", 422);
                    }

                    $outOptions[$oKey] = ['label' => $oLabel, 'points' => (int) $points];
                }

                // Every option scoring the same makes the question decorative:
                // it cannot move the outcome either way.
                if (count(array_unique(array_column($outOptions, 'points'))) === 1) {
                    throw new BusinessException("Every answer to '{$qLabel}' scores the same, so the question cannot affect the result.", 422);
                }

                $outQuestions[$qKey] = ['label' => $qLabel, 'options' => $outOptions];
            }

            $out[$sKey] = ['label' => $label, 'questions' => $outQuestions];
        }

        return $out;
    }

    /**
     * Keys are stored, answered against and compared later, so they are
     * normalised rather than taken as typed — a question keyed "Turn Over " and
     * one keyed "turn_over" would otherwise be two different questions holding
     * the same answers.
     */
    private static function key(string $raw, string $what): string
    {
        $key = strtolower(trim($raw));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim((string) $key, '_');

        if ($key === '') {
            throw new BusinessException("Every {$what} needs an identifier.", 422);
        }

        return $key;
    }
}
