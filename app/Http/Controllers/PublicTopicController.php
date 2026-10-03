<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Chapter;
use App\Models\Section;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicTopicController extends Controller
{
    public function index(Request $request): Response
    {
        $topics = Topic::query()
            ->with([
                'category:id,name',
                'user:id,name',
                'chapters' => fn ($query) => $query
                    ->where('is_published', true)
                    ->orderBy('sort_order')
                    ->with(['sections' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')->limit(1)]),
            ])
            ->when(
                $request->string('category')->isNotEmpty(),
                fn ($query) => $query->where('category_id', $request->integer('category')),
            )
            ->latest('updated_at')
            ->get()
            ->map(fn (Topic $topic) => [
                'id' => $topic->id,
                'slug' => $topic->slug,
                'title' => $topic->title,
                'excerpt' => $this->excerptFromTopic($topic),
                'category' => $topic->category?->only(['id', 'name']),
                'author' => $topic->user?->only(['id', 'name']),
                'updated_at' => $topic->updated_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('public/topics/index', [
            'topics' => $topics,
            'filters' => [
                'category' => $request->input('category'),
            ],
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(Topic $topic, ?Chapter $chapter = null, ?Section $section = null): Response
    {
        $topic->loadMissing([
            'category:id,name',
            'user:id,name',
            'chapters' => fn ($query) => $query
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->with([
                    'sections' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order'),
                ]),
        ]);

        // Validate chapter belongs to topic and is published
        if ($chapter) {
            if (! $chapter->is_published || $chapter->topic_id !== $topic->id) {
                abort(404);
            }
        }

        // Validate section belongs to chapter and is published
        if ($section) {
            if (! $section->is_published) {
                abort(404);
            }
            if (! $chapter || $section->chapter_id !== $chapter->id) {
                abort(404);
            }
        }

        // Default to first published section of first published chapter
        $activeSection = $section;
        if (! $activeSection) {
            $targetChapter = $chapter ?? $topic->chapters->first();
            $activeSection = $targetChapter?->sections->first();
        }

        return Inertia::render('public/topics/show', [
            'topic' => [
                'id' => $topic->id,
                'slug' => $topic->slug,
                'title' => $topic->title,
                'category' => $topic->category?->only(['id', 'name']),
                'author' => $topic->user?->only(['id', 'name']),
                'updated_at' => $topic->updated_at?->toIso8601String(),
                'chapters' => $topic->chapters->map(fn (Chapter $chapter) => [
                    'id' => $chapter->id,
                    'slug' => $chapter->slug,
                    'title' => $chapter->title,
                    'sort_order' => $chapter->sort_order,
                    'sections' => $chapter->sections->map(fn (Section $section) => [
                        'id' => $section->id,
                        'slug' => $section->slug,
                        'title' => $section->title,
                        'sort_order' => $section->sort_order,
                        'is_task' => $section->task_submission !== null,
                    ]),
                ]),
                'activeSection' => $activeSection ? [
                    'id' => $activeSection->id,
                    'slug' => $activeSection->slug,
                    'title' => $activeSection->title,
                    'content' => $activeSection->content,
                    'is_task' => $activeSection->task_submission !== null,
                ] : null,
            ],
        ]);
    }

    private function excerptFromTopic(Topic $topic): ?string
    {
        $firstChapter = $topic->chapters->first();
        if (! $firstChapter) {
            return null;
        }

        $section = $firstChapter->sections->first();
        if (! $section) {
            return null;
        }

        $blocks = $section->content['blocks'] ?? [];

        $firstTextBlock = collect($blocks)->first(function ($block) {
            return is_array($block)
                && isset($block['data']['text'])
                && is_string($block['data']['text'])
                && trim($block['data']['text']) !== '';
        });

        if (! $firstTextBlock) {
            return null;
        }

        $plainText = strip_tags($firstTextBlock['data']['text']);

        return $plainText !== '' ? Str::limit($plainText, 180) : null;
    }
}
