<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\LtiResourceLink;
use App\Models\LtiSession;
use App\Models\Section;
use App\Models\Topic;
use Illuminate\Support\Collection;

/**
 * What a Moodle activity shows: a whole topic, one chapter, a single lesson or
 * a topic's assistant.
 *
 * Resolved by id wherever an id is known. Links made before ids travelled
 * with them carry only slugs, and a slug changes when an author renames a
 * chapter - the activity would then point at nothing.
 */
class LtiContent
{
    public const TYPES = ['topic', 'chapter', 'section', 'chat'];

    private ?Collection $sections = null;

    private function __construct(
        public readonly string $type,
        public readonly Topic $topic,
        public readonly ?Chapter $chapter = null,
        public readonly ?Section $section = null,
    ) {}

    /**
     * From a picker choice such as "chapter:12". Only published content can
     * be chosen, and only content that has something to show.
     */
    public static function fromChoice(string $choice): ?self
    {
        [$type, $id] = array_pad(explode(':', $choice, 2), 2, null);

        if (! in_array($type, self::TYPES, true) || ! ctype_digit((string) $id)) {
            return null;
        }

        $content = match ($type) {
            'topic', 'chat' => ($topic = Topic::find($id)) ? new self($type, $topic) : null,
            'chapter' => ($chapter = Chapter::where('is_published', true)->find($id))
                ? new self('chapter', $chapter->topic, $chapter)
                : null,
            'section' => ($section = Section::where('is_published', true)->find($id))
                && $section->chapter?->is_published
                ? new self('section', $section->chapter->topic, $section->chapter, $section)
                : null,
        };

        // A draft shows nothing, whichever part of it was chosen
        if (! $content || ! $content->topic || $content->topic->isDraft()) {
            return null;
        }

        return $content->type === 'chat' || $content->sections()->isNotEmpty() ? $content : null;
    }

    /**
     * What a launch should show: the choice made through Deep Linking, carried
     * in the activity's custom parameters, or else the one made later by a
     * teacher opening the activity.
     */
    public static function forSession(LtiSession $session): ?self
    {
        $custom = $session->claims['https://purl.imsglobal.org/spec/lti/claim/custom'] ?? [];

        if ($content = self::fromCustom($custom)) {
            return $content;
        }

        $link = self::binding($session);

        if (! $link) {
            return null;
        }

        $id = match ($link->content_type) {
            'chapter' => $link->chapter_id,
            'section' => $link->section_id,
            default => $link->topic_id,
        };

        return self::fromChoice($link->content_type.':'.$id);
    }

    public static function binding(LtiSession $session): ?LtiResourceLink
    {
        if (! $session->resource_link_id) {
            return null;
        }

        return LtiResourceLink::where('lti_platform_id', $session->lti_platform_id)
            ->where('resource_link_id', $session->resource_link_id)
            ->first();
    }

    private static function fromCustom(array $custom): ?self
    {
        $type = $custom['content_type'] ?? null;

        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $idKey = ['topic' => 'topic_id', 'chat' => 'topic_id', 'chapter' => 'chapter_id', 'section' => 'section_id'][$type];

        if (! empty($custom[$idKey]) && ctype_digit((string) $custom[$idKey])) {
            return self::fromChoice($type.':'.$custom[$idKey]);
        }

        // Older links: slugs only
        $topic = Topic::where('slug', $custom['topic_slug'] ?? '')->first();

        if (! $topic) {
            return null;
        }

        $id = match ($type) {
            'chapter' => $topic->chapters()->where('slug', $custom['chapter_slug'] ?? '')->value('id'),
            'section' => Section::whereIn('chapter_id', $topic->chapters()->pluck('id'))
                ->where('slug', $custom['section_slug'] ?? '')->value('id'),
            default => $topic->id,
        };

        return $id ? self::fromChoice($type.':'.$id) : null;
    }

    public function choice(): string
    {
        return $this->type.':'.match ($this->type) {
            'chapter' => $this->chapter->id,
            'section' => $this->section->id,
            default => $this->topic->id,
        };
    }

    /** Published lessons in scope, in reading order. */
    public function sections(): Collection
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        return $this->sections = match ($this->type) {
            'chat' => collect(),
            'section' => collect([$this->section]),
            'chapter' => $this->chapter->sections()->where('is_published', true)->orderBy('sort_order')->get(),
            'topic' => $this->topic->chapters()->where('is_published', true)->orderBy('sort_order')
                ->with(['sections' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')])
                ->get()->flatMap->sections->values(),
        };
    }

    /**
     * Everything a teacher can choose, with how many lessons each holds.
     * Empty chapters and topics are left out - choosing one would show the
     * class nothing.
     */
    public static function catalog(): array
    {
        // Only published and private courses can be chosen - not drafts, nor
        // courses being revised or archived
        $topics = Topic::whereIn('status', Topic::OFFERED)->with(['chapters' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')
            ->with(['sections' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')])])
            ->orderBy('title')
            ->get();

        return $topics->map(function (Topic $topic) {
            $chapters = $topic->chapters
                ->filter(fn (Chapter $chapter) => $chapter->sections->isNotEmpty())
                ->map(function (Chapter $chapter) {
                    $sections = $chapter->sections->map(fn (Section $section) => [
                        'id' => $section->id,
                        'title' => $section->title,
                        // Set when Moodle can take the lesson over as an assignment
                        'task' => $section->task_submission,
                    ])->values();

                    return [
                        'id' => $chapter->id,
                        'title' => $chapter->title,
                        'lessons' => $sections->count(),
                        'sections' => $sections->all(),
                    ];
                })->values();

            return [
                'id' => $topic->id,
                'title' => $topic->title,
                'lessons' => $chapters->sum('lessons'),
                'chapters' => $chapters->all(),
            ];
        })->filter(fn ($topic) => $topic['lessons'] > 0)->values()->all();
    }

    public function title(): string
    {
        return match ($this->type) {
            'chapter' => $this->topic->title.' – '.$this->chapter->title,
            'section' => $this->topic->title.' – '.$this->section->title,
            'chat' => $this->topic->title.' – KI-Assistent',
            default => $this->topic->title,
        };
    }

    /** One line for the activity description in Moodle. */
    public function summary(): string
    {
        if ($this->type === 'chat') {
            return 'KI-Assistent, der Fragen zu '.$this->topic->title.' beantwortet.';
        }

        $lessons = $this->sections()->count();

        return 'MotionBase: '.($lessons === 1 ? '1 Lektion' : $lessons.' Lektionen').'.';
    }

    /** Stored with the activity in Moodle and handed back on every launch. */
    public function customParams(): array
    {
        return array_filter([
            'content_type' => $this->type,
            'topic_id' => (string) $this->topic->id,
            'chapter_id' => $this->chapter ? (string) $this->chapter->id : null,
            'section_id' => $this->section ? (string) $this->section->id : null,
            'topic_slug' => $this->topic->slug,
            'chapter_slug' => $this->chapter?->slug,
            'section_slug' => $this->section?->slug,
        ], fn ($value) => $value !== null);
    }

    /** Where a launch lands, by the current slugs. */
    public function url(string $sessionToken): string
    {
        $session = ['lti_session' => $sessionToken];

        return match ($this->type) {
            'chat' => route('lti.embed.chat', ['topic' => $this->topic->slug] + $session),
            'section' => route('lti.embed.lesson', ['topic' => $this->topic->slug, 'section' => $this->section->slug] + $session),
            'chapter' => route('lti.embed.chapter', ['topic' => $this->topic->slug, 'chapter' => $this->chapter->slug] + $session),
            default => route('lti.embed.topic', ['topic' => $this->topic->slug] + $session),
        };
    }
}
