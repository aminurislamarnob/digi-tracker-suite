<?php

namespace App\Http\Requests\Ingest;

use App\Http\Requests\Ingest\Concerns\ResolvesProjectByHash;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Somebody declined the opt-in dialog.
 *
 * The thinnest payload the protocol has -- a hash and a flag -- and that
 * is deliberate on the SDK's part: a refusal must not carry a URL, an
 * email or an environment. This request must never grow to accept them.
 */
class TrackingSkippedRequest extends FormRequest
{
    use ResolvesProjectByHash;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hash' => ['required', 'uuid'],
        ];
    }

    /**
     * Form encoding turns false into an empty string, so this can never be
     * a `boolean` rule -- see TrackRequest for the full explanation.
     */
    public function previouslySkipped(): bool
    {
        return filter_var($this->input('previously_skipped'), FILTER_VALIDATE_BOOL);
    }
}
