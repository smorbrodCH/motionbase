<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Section;
use App\Services\LtiContent;
use App\Services\LtiService;
use App\Services\MoodleHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the MotionBase plugin for Moodle reads: the catalogue a teacher picks
 * from, and lessons, chapters and tasks for the book chapters, pages and
 * assignments made from them - fetched again each time one is opened.
 *
 * Only a Moodle registered here as an LTI platform gets in. Its plugin signs
 * a short-lived token with the site's own LTI key, and that key is checked
 * against the key set the platform already publishes.
 */
class MoodleController extends Controller
{
    public function __construct(
        private LtiService $ltiService,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $this->authenticate($request);

        return response()->json(['topics' => LtiContent::catalog()]);
    }

    public function task(Request $request, Section $section, MoodleHtml $html): JsonResponse
    {
        $this->authenticate($request);

        $chapter = $section->chapter;

        abort_unless($section->task_submission && $section->is_published && $chapter?->is_published && $chapter->topic?->is_published, 404);

        return response()->json([
            'id' => $section->id,
            'title' => $section->title,
            'topic' => $chapter->topic->title,
            'chapter' => $chapter->title,
            'submission' => $section->task_submission,
            'html' => $html->render($section),
        ]);
    }

    /**
     * Any published lesson, as Moodle's MotionBase filter shows it each time
     * a book chapter, page or assignment made from it is opened.
     */
    public function lesson(Request $request, Section $section, MoodleHtml $html): JsonResponse
    {
        $this->authenticate($request);

        $chapter = $section->chapter;

        abort_unless($section->is_published && $chapter?->is_published && $chapter->topic?->is_published, 404);

        return response()->json([
            'id' => $section->id,
            'title' => $section->title,
            'chapter_id' => $chapter->id,
            'html' => $html->render($section, embed: $request->boolean('embed'), source: false),
        ]);
    }

    /**
     * A chapter's published lessons in order - what a Moodle book made from it
     * holds, checked each time the book is opened. With ?html=1 each lesson
     * comes with its content, for a new book.
     *
     * Tasks are left out: in Moodle they are assignments of their own, and
     * a book chapter besides would put each one in the course twice.
     */
    public function chapter(Request $request, Chapter $chapter, MoodleHtml $html): JsonResponse
    {
        $this->authenticate($request);

        abort_unless($chapter->is_published && $chapter->topic?->is_published, 404);

        $withHtml = $request->boolean('html');

        return response()->json([
            'id' => $chapter->id,
            'title' => $chapter->title,
            'topic' => $chapter->topic->title,
            'lessons' => $chapter->sections()->where('is_published', true)->whereNull('task_submission')->orderBy('sort_order')->get()
                ->map(fn (Section $section) => array_filter([
                    'id' => $section->id,
                    'title' => $section->title,
                    'html' => $withHtml ? $html->render($section, source: false) : null,
                ], fn ($value) => $value !== null))
                ->values(),
        ]);
    }

    private function authenticate(Request $request): void
    {
        $token = $request->bearerToken();

        // The address as configured, not as this request arrived: behind a
        // proxy the two can differ in scheme, and the plugin signs the former.
        $audience = rtrim(config('app.url'), '/').'/moodle';

        abort_unless($token && $this->ltiService->decodePlatformToken($token, $audience), 401, 'Unknown or unverified Moodle site.');
    }
}
