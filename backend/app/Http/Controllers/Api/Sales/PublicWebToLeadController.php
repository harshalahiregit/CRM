<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\WebToLeadService;
use Illuminate\Http\Request;

class PublicWebToLeadController extends Controller
{
    public function __construct(private WebToLeadService $service)
    {
    }

    /** GET /api/public/web-to-lead/{formKey} — render data for the public form. */
    public function show(string $formKey)
    {
        $form = $this->service->resolvePublicForm($formKey);
        return response()->json($this->service->publicPayload($form));
    }

    /** POST /api/public/web-to-lead/{formKey} — create a lead (throttled). */
    public function submit(Request $request, string $formKey)
    {
        $form = $this->service->resolvePublicForm($formKey);

        // NOTE: reCAPTCHA enforcement is deferred — there is no captcha
        // verification infrastructure in this codebase yet (same posture as the
        // deferred proposal-OTP). Spam is mitigated by the route-level
        // throttle:10,1. The form's recaptcha_enabled flag is stored for when
        // that infrastructure lands.

        // Base validation on the standard lead columns.
        $data = $request->validate([
            'name'    => 'nullable|string|max:255',
            'email'   => 'nullable|email|max:255',
            'phone'   => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'title'   => 'nullable|string|max:255',
            'website' => 'nullable|string|max:500',
            'city'    => 'nullable|string|max:100',
            'state'   => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip'     => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'industry'=> 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        // Enforce required flags against the RAW input — a required custom
        // field (a key outside the standard columns above) is stripped by
        // validate(), so checking $data would make it fail even when supplied.
        $extras = [];
        foreach (($form->form_data ?: []) as $field) {
            $key = $field['key'] ?? null;
            if (! $key) continue;
            $value = $request->input($key);
            if (!empty($field['required']) && (is_null($value) || $value === '')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "The {$field['label']} field is required.",
                ], 422);
            }
            // Preserve answers to non-standard (custom) fields instead of
            // silently dropping them.
            if (! array_key_exists($key, $data) && !is_null($value) && $value !== '') {
                $extras[$field['label'] ?? $key] = is_scalar($value) ? $value : json_encode($value);
            }
        }

        $this->service->submit($form, $data, $extras);

        return response()->json([
            'status'  => 'success',
            'message' => $form->success_message ?: 'Thank you! We will get back to you shortly.',
            'redirect_url' => $form->redirect_url,
        ], 201);
    }
}
