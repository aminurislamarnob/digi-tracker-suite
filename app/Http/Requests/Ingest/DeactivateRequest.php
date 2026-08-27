<?php

namespace App\Http\Requests\Ingest;

/**
 * A deactivation carries the entire insights payload plus the reason, so
 * it validates exactly as a heartbeat does with two fields added.
 *
 * Note reason_id is nullable: dismissing the dialog without choosing
 * anything sends the literal string 'none', and an older client may send
 * nothing at all. Losing the churn event because the feedback was blank
 * would be the wrong trade -- the event matters more than the reason.
 */
class DeactivateRequest extends TrackRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'reason_id' => ['nullable', 'string', 'max:64'],
            'reason_info' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
