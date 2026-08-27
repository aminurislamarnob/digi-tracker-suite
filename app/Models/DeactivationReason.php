<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeactivationReason extends Model
{
    use BelongsToAccount, HasFactory;

    /**
     * The seven the SDK ships, in the order it renders them.
     *
     * Seeded per project on creation so a stock integration needs no
     * configuration, and so an author can reword or reorder them without
     * breaking historical reason_ids -- the id is the key, the label is
     * only presentation.
     */
    public const DEFAULTS = [
        ['reason_id' => 'could-not-understand', 'label' => "Couldn't understand", 'placeholder' => 'Would you like us to assist you?'],
        ['reason_id' => 'found-better-plugin', 'label' => 'Found a better plugin', 'placeholder' => 'Which plugin?'],
        ['reason_id' => 'not-have-that-feature', 'label' => 'Missing a specific feature', 'placeholder' => 'Could you tell us more about that feature?'],
        ['reason_id' => 'is-not-working', 'label' => 'Not working', 'placeholder' => "Could you tell us a bit more what's not working?"],
        ['reason_id' => 'looking-for-other', 'label' => 'Not what I was looking for', 'placeholder' => 'Could you tell us a bit more?'],
        ['reason_id' => 'did-not-work-as-expected', 'label' => "Didn't work as expected", 'placeholder' => 'What did you expect?'],
        ['reason_id' => 'other', 'label' => 'Others', 'placeholder' => 'Could you tell us a bit more?'],
    ];

    /**
     * Sent when the dialog is dismissed without picking anything. Not one
     * of the seven, but it arrives on the wire and needs a label.
     */
    public const NONE = 'none';

    protected $fillable = [
        'account_id', 'project_id', 'reason_id', 'label',
        'placeholder', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
