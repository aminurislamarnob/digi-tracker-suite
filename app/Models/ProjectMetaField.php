<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMetaField extends Model
{
    use BelongsToAccount, HasFactory;

    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_FLOAT = 'float';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_DATE = 'date';

    public const DATATYPES = [
        self::TYPE_STRING,
        self::TYPE_INTEGER,
        self::TYPE_FLOAT,
        self::TYPE_BOOLEAN,
        self::TYPE_DATE,
    ];

    protected $fillable = ['account_id', 'project_id', 'key', 'datatype', 'label'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Coerce a whitelisted value to its declared type.
     *
     * Everything arrives as a string because the payload is form-encoded,
     * so without this an `integer` field would be charted as text and a
     * `boolean` would be true for the string "0".
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = (string) $value;

        return match ($this->datatype) {
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_FLOAT => (float) $value,
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL),
            self::TYPE_DATE => ($ts = strtotime($value)) ? date('Y-m-d', $ts) : null,
            default => mb_substr($value, 0, 255),
        };
    }
}
