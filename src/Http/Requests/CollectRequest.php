<?php

namespace SergeyBruhin\Analytics\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class CollectRequest extends FormRequest
{
    /**
     * Anonymous, unauthenticated endpoint by design — protected by throttle,
     * origin checking, and this request's own strict shape validation
     * instead of Laravel authorization.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visitor_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'landing' => ['sometimes', 'boolean'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm' => ['sometimes', 'array'],
            'utm.source' => ['nullable', 'string', 'max:191'],
            'utm.medium' => ['nullable', 'string', 'max:191'],
            'utm.campaign' => ['nullable', 'string', 'max:191'],
            'utm.content' => ['nullable', 'string', 'max:191'],
            'utm.term' => ['nullable', 'string', 'max:191'],
            'click_ids' => ['sometimes', 'array'],
            'identify' => ['sometimes', 'array'],
            'identify.external_id' => ['required_with:identify', 'string', 'max:191'],
            'identify.traits' => ['nullable', 'array'],

            // A batch cap of 20 keeps a single request's insert/queue-payload
            // work bounded regardless of how long the client has been idle.
            'hits' => ['required', 'array', 'min:1', 'max:20'],
            'hits.*.type' => ['required', 'in:pageview,event'],
            'hits.*.dedup_key' => ['nullable', 'string', 'max:64'],
            'hits.*.url' => ['required_if:hits.*.type,pageview', 'nullable', 'string', 'max:2048'],
            'hits.*.path' => ['nullable', 'string', 'max:2048'],
            'hits.*.title' => ['nullable', 'string', 'max:512'],
            'hits.*.duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            // Lowercase letters, digits, dot/underscore/hyphen only — same
            // shape as a metric/event name in any common analytics tool.
            'hits.*.name' => ['required_if:hits.*.type,event', 'nullable', 'regex:/^[a-z0-9_.-]{1,191}$/'],
            'hits.*.value' => ['nullable', 'numeric', 'between:-999999999.99,999999999.99'],
            'hits.*.properties' => ['nullable', 'array'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            foreach ((array) $this->input('hits', []) as $index => $hit) {
                $encoded = json_encode($hit['properties'] ?? []);

                // Protects the jsonb column and the queued job's payload size
                // from a single malformed/malicious hit, not from legitimate use.
                if ($encoded !== false && strlen($encoded) > 4096) {
                    $validator->errors()->add("hits.{$index}.properties", 'Event properties payload is too large.');
                }
            }
        });
    }
}
