<?php

namespace Tests\Unit\Tpv;

use App\Http\Requests\Tpv\SubmitOnboardingRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SubmitOnboardingRequestTest extends TestCase
{
    private function rules(): array
    {
        return (new SubmitOnboardingRequest())->rules();
    }

    public function test_declaration_is_required_and_accepted(): void
    {
        $this->assertSame(['declaration' => 'required|accepted'], $this->rules());
    }

    public function test_validation_passes_when_declaration_true(): void
    {
        $v = Validator::make(['declaration' => true], $this->rules());
        $this->assertFalse($v->fails());
    }

    /**
     * @dataProvider rejectedValues
     */
    public function test_validation_fails_when_declaration_missing_or_false($payload): void
    {
        $v = Validator::make($payload, $this->rules());
        $this->assertTrue($v->fails());
    }

    public static function rejectedValues(): array
    {
        return [
            'missing' => [[]],
            'false'   => [['declaration' => false]],
            'zero'    => [['declaration' => 0]],
            'empty'   => [['declaration' => '']],
        ];
    }
}
