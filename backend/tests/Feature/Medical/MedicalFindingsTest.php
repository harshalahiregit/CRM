<?php

namespace Tests\Feature\Medical;

use App\Support\Medical\MedicalFindings;
use Tests\TestCase;

/**
 * Naming what an examination found, and grouping people by it.
 *
 * The thing this makes possible: a doctor sees fourteen people in a morning and
 * cannot tell, from fourteen certificates, whether the same thing is wrong with
 * six of them. Six workers off one gang with the same hearing loss is a noise
 * problem; found one certificate at a time it is six coincidences.
 */
class MedicalFindingsTest extends TestCase
{
    /* ── Naming a finding ───────────────────────────────────────────────── */

    public function test_high_blood_pressure_is_named_and_graded(): void
    {
        $mild = MedicalFindings::of(['bp_systolic' => 146, 'bp_diastolic' => 92]);
        $this->assertSame('bp_stage1', $mild[0]['key']);
        $this->assertSame('serious', $mild[0]['severity']);
        $this->assertSame('146/92 mmHg', $mild[0]['detail'], 'the reading is carried, not just the label');

        $severe = MedicalFindings::of(['bp_systolic' => 178, 'bp_diastolic' => 104]);
        $this->assertSame('bp_stage2', $severe[0]['key']);
        $this->assertSame('critical', $severe[0]['severity']);
    }

    public function test_a_normal_examination_finds_nothing(): void
    {
        $findings = MedicalFindings::of([
            'fitness_status' => 'Fit',
            'bp_systolic' => 118, 'bp_diastolic' => 76, 'pulse_bpm' => 72, 'spo2' => 98,
            'temperature_c' => 36.8, 'height_cm' => 172, 'weight_kg' => 68,
            'vision_left' => '6/6', 'vision_right' => '6/6',
            'colour_vision' => 'Normal', 'hearing' => 'Normal', 'health_score' => 9.1,
        ]);

        $this->assertSame([], $findings, 'a healthy person must not be given a finding');
    }

    public function test_declared_conditions_each_become_their_own_finding(): void
    {
        // The whole point of grouping: "Diabetes" is a group people can share,
        // not a row inside an indistinct "has a declared condition".
        $findings = MedicalFindings::of([
            'medical_history' => ['conditions' => ['Diabetes', 'Asthma']],
        ]);

        $keys = array_column($findings, 'key');
        $this->assertContains('condition:diabetes', $keys);
        $this->assertContains('condition:asthma', $keys);
    }

    public function test_the_same_condition_spelt_differently_is_one_group(): void
    {
        $a = MedicalFindings::of(['medical_history' => ['conditions' => ['Diabetes']]]);
        $b = MedicalFindings::of(['medical_history' => ['conditions' => ['  diabetes ']]]);

        $this->assertSame($a[0]['key'], $b[0]['key']);
    }

    public function test_saying_none_is_not_a_finding(): void
    {
        // Otherwise every healthy person joins a group called "None".
        $findings = MedicalFindings::of([
            'medical_history' => ['conditions' => ['None'], 'habits' => ['nil']],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_a_normal_investigation_result_is_not_a_finding(): void
    {
        $clear = MedicalFindings::of([
            'investigations' => [['name' => 'Chest X-ray', 'result' => 'NAD']],
        ]);
        $this->assertSame([], $clear);

        $abnormal = MedicalFindings::of([
            'investigations' => [['name' => 'Chest X-ray', 'result' => 'Opacity right upper zone']],
        ]);
        $this->assertSame('investigation:chest_x_ray', $abnormal[0]['key']);
        $this->assertSame('Opacity right upper zone', $abnormal[0]['detail']);
    }

    public function test_json_text_is_read_as_well_as_an_array(): void
    {
        // Records written before the cast was added hold JSON text, and a row
        // read straight from the query builder is text either way.
        $findings = MedicalFindings::of([
            'medical_history' => json_encode(['conditions' => ['Hypertension']]),
        ]);

        $this->assertSame('condition:hypertension', $findings[0]['key']);
    }

    public function test_vision_is_read_as_a_fraction_not_compared_as_a_string(): void
    {
        $this->assertSame([], MedicalFindings::of(['vision_left' => '6/6', 'vision_right' => '6/9']));

        $poor = MedicalFindings::of(['vision_left' => '6/18', 'vision_right' => '6/6']);
        $this->assertSame('vision_reduced', $poor[0]['key']);
        $this->assertStringContainsString('left 6/18', $poor[0]['detail']);
    }

    public function test_an_unfit_verdict_is_the_most_severe_finding(): void
    {
        $findings = MedicalFindings::of([
            'fitness_status' => 'Unfit',
            'medical_history' => ['habits' => ['Tobacco']],
        ]);

        // Sorted worst-first, so the thing that stops somebody working is read
        // before the thing that is merely noted.
        $this->assertSame('unfit', $findings[0]['key']);
        $this->assertSame('critical', $findings[0]['severity']);
    }

    /* ── Grouping people ────────────────────────────────────────────────── */

    public function test_people_are_grouped_by_what_they_share(): void
    {
        $result = MedicalFindings::group([
            ['id' => 1, 'name' => 'Ramesh', 'exam' => ['bp_systolic' => 150, 'bp_diastolic' => 95]],
            ['id' => 2, 'name' => 'Suresh', 'exam' => ['bp_systolic' => 148, 'bp_diastolic' => 91]],
            ['id' => 3, 'name' => 'Mahesh', 'exam' => ['bp_systolic' => 120, 'bp_diastolic' => 78]],
        ]);

        $shared = collect($result['groups'])->firstWhere('key', 'bp_stage1');

        $this->assertSame(2, $shared['count']);
        $this->assertTrue($shared['shared']);
        $this->assertSame(['Ramesh', 'Suresh'], array_column($shared['people'], 'name'));
        $this->assertSame(['Mahesh'], array_column($result['clear'], 'name'));
    }

    public function test_the_most_shared_finding_comes_first(): void
    {
        // Because a thing four people have is the reason the screen exists, and
        // a thing one person has is on their own record.
        $result = MedicalFindings::group([
            ['id' => 1, 'name' => 'A', 'exam' => ['hearing' => 'Impaired', 'spo2' => 90]],
            ['id' => 2, 'name' => 'B', 'exam' => ['hearing' => 'Impaired']],
            ['id' => 3, 'name' => 'C', 'exam' => ['hearing' => 'Impaired']],
        ]);

        $this->assertSame('hearing', $result['groups'][0]['key']);
        $this->assertSame(3, $result['groups'][0]['count']);
        // Even though the oxygen finding is more severe, only one person has it.
        $this->assertFalse(collect($result['groups'])->firstWhere('key', 'spo2_low')['shared']);
    }

    public function test_somebody_never_examined_is_listed_separately(): void
    {
        // They are not "clear" — nothing is known about them, and they are the
        // ones a doctor should see first.
        $result = MedicalFindings::group([
            ['id' => 1, 'name' => 'Seen', 'exam' => ['fitness_status' => 'Fit']],
            ['id' => 2, 'name' => 'Never seen', 'exam' => null],
        ]);

        $this->assertSame(['Never seen'], array_column($result['unexamined'], 'name'));
        $this->assertSame(['Seen'], array_column($result['clear'], 'name'));
        $this->assertSame(1, $result['examined']);
    }

    public function test_grouping_nobody_is_an_empty_answer_not_an_error(): void
    {
        $result = MedicalFindings::group([]);

        $this->assertSame([], $result['groups']);
        $this->assertSame(0, $result['examined']);
    }
}
