<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Models\Traits\Revisionable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Topic extends Model
{
    use HasFactory, HasUniqueSlug, Revisionable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'slug',
        'status',
    ];

    /** Being written: not listed, not reachable. New courses start here. */
    public const DRAFT = 'draft';

    /** Not listed, but reachable by its link, in embeds and in Moodle. */
    public const PRIVATE = 'private';

    /** Listed and open. */
    public const PUBLISHED = 'published';

    /** Listed, but locked with a note that it is being revised. */
    public const REVISION = 'revision';

    /** Outdated: not listed or offered any more, still readable with a note. */
    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::PRIVATE, self::PUBLISHED, self::REVISION, self::ARCHIVED];

    /** Shown in the course overview. */
    public const LISTED = [self::PUBLISHED, self::REVISION];

    /** Offered to Moodle when a teacher picks content. */
    public const OFFERED = [self::PUBLISHED, self::PRIVATE];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::PUBLISHED,
    ];

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isInRevision(): bool
    {
        return $this->status === self::REVISION;
    }

    public function isArchived(): bool
    {
        return $this->status === self::ARCHIVED;
    }

    /** Whether its content can be read at all. */
    public function isReadable(): bool
    {
        return ! $this->isDraft() && ! $this->isInRevision();
    }

    /**
     * @return Builder<static>
     */
    protected function slugSiblings(): Builder
    {
        return static::query();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('sort_order');
    }

    /**
     * Get all sections through chapters.
     *
     * @return HasManyThrough<Section, Chapter, $this>
     */
    public function sections(): HasManyThrough
    {
        return $this->hasManyThrough(Section::class, Chapter::class);
    }
}
