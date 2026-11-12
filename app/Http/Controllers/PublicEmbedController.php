<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Section;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class PublicEmbedController extends Controller
{
    /**
     * Embed a complete topic (redirects to first section)
     */
    public function topic(Topic $topic)
    {
        if ($locked = $this->courseLock($topic)) {
            return $locked;
        }

        $topic->loadMissing([
            'chapters' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')
                ->with(['sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order')]),
        ]);

        $firstSection = $topic->chapters
            ->flatMap(fn (Chapter $chapter) => $chapter->sections)
            ->first();

        // A topic is only embeddable once it has at least one published section.
        if (! $firstSection) {
            abort(404);
        }

        return View::make('embed.topic', [
            'topic' => $topic,
            'activeSection' => $firstSection,
        ]);
    }

    /**
     * Embed a single chapter (shows navigation for all sections in the chapter)
     */
    public function chapter(Chapter $chapter)
    {
        // Only allow published chapters
        if (! $chapter->is_published) {
            abort(404);
        }

        if ($locked = $this->courseLock($chapter->topic)) {
            return $locked;
        }

        $chapter->loadMissing([
            'topic',
            'sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order'),
        ]);

        $firstSection = $chapter->sections->first();

        // Redirect to first section if available
        if ($firstSection) {
            return redirect()->route('embed.chapter.section', [
                'chapter' => $chapter->id,
                'section' => $firstSection->id,
            ]);
        }

        return View::make('embed.chapter', [
            'topic' => $chapter->topic,
            'chapter' => $chapter,
            'activeSection' => null,
            'prevSection' => null,
            'nextSection' => null,
        ]);
    }

    /**
     * Embed a chapter with a specific section
     */
    public function chapterSection(Chapter $chapter, Section $section)
    {
        // Only allow published content
        if (! $chapter->is_published) {
            abort(404);
        }

        // Ensure section belongs to chapter
        if ($section->chapter_id !== $chapter->id) {
            abort(404);
        }

        // Only allow published sections
        if (! $section->is_published) {
            abort(404);
        }

        if ($locked = $this->courseLock($chapter->topic)) {
            return $locked;
        }

        $chapter->loadMissing([
            'topic',
            'sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order'),
        ]);

        // Find previous and next sections
        $sections = $chapter->sections;
        $currentIndex = $sections->search(fn ($s) => $s->id === $section->id);
        $prevSection = $currentIndex > 0 ? $sections[$currentIndex - 1] : null;
        $nextSection = $currentIndex < $sections->count() - 1 ? $sections[$currentIndex + 1] : null;

        return View::make('embed.chapter', [
            'topic' => $chapter->topic,
            'chapter' => $chapter,
            'activeSection' => $section,
            'prevSection' => $prevSection,
            'nextSection' => $nextSection,
        ]);
    }

    /**
     * Embed a single section directly
     */
    public function section(Section $section)
    {
        // Only allow published sections
        if (! $section->is_published) {
            abort(404);
        }

        $section->loadMissing(['chapter.topic']);

        // Only allow if chapter is published
        if (! $section->chapter->is_published) {
            abort(404);
        }

        if ($locked = $this->courseLock($section->chapter->topic)) {
            return $locked;
        }

        return View::make('embed.section', [
            'topic' => $section->chapter->topic,
            'chapter' => $section->chapter,
            'section' => $section,
        ]);
    }
}
