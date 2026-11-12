<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Section;
use App\Models\Topic;
use App\Services\LtiContent;
use App\Services\LtiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class LtiEmbedController extends Controller
{
    public function __construct(
        private LtiService $ltiService
    ) {}

    public function topic(Request $request, Topic $topic)
    {
        $session = $this->validateSession($request);
        abort_unless($topic->is_published, 404);

        $topic->loadMissing([
            'chapters' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')
                ->with(['sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order')]),
        ]);

        $firstSection = $topic->chapters->first()?->sections->first();

        return View::make('lti.embed.topic', [
            'topic' => $topic,
            'activeSection' => $firstSection,
            'session' => $session,
        ] + $this->context($session));
    }

    public function lesson(Request $request, Topic $topic, Section $section)
    {
        $session = $this->validateSession($request);
        abort_unless($topic->is_published, 404);
        $chapter = $section->chapter;

        abort_unless($section->is_published && $chapter?->is_published && $chapter->topic_id === $topic->id, 404);

        return View::make('lti.embed.chapter', [
            'topic' => $topic,
            'chapter' => $chapter,
            'activeSection' => $section,
            'lessonOnly' => true,
            'session' => $session,
        ] + $this->context($session));
    }

    public function section(Request $request, Topic $topic, Section $section)
    {
        $session = $this->validateSession($request);
        abort_unless($topic->is_published, 404);

        abort_unless($section->is_published && $section->chapter?->is_published && $section->chapter->topic_id === $topic->id, 404);

        // Load topic navigation
        $topic->loadMissing([
            'chapters' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')
                ->with(['sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order')]),
        ]);

        return View::make('lti.embed.topic', [
            'topic' => $topic,
            'activeSection' => $section,
            'session' => $session,
        ] + $this->context($session));
    }

    public function chapter(Request $request, Topic $topic, Chapter $chapter)
    {
        $session = $this->validateSession($request);
        abort_unless($topic->is_published, 404);

        // Ensure chapter belongs to topic
        if ($chapter->topic_id !== $topic->id) {
            abort(404);
        }

        // Load only this chapter with its sections
        $chapter->loadMissing([
            'sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order'),
        ]);

        $firstSection = $chapter->sections->first();

        // Redirect to first section
        if ($firstSection) {
            return redirect()->route('lti.embed.chapter.section', [
                'topic' => $topic->slug,
                'chapter' => $chapter->slug,
                'section' => $firstSection->slug,
                'lti_session' => $session->session_token,
            ]);
        }

        return View::make('lti.embed.chapter', [
            'topic' => $topic,
            'chapter' => $chapter,
            'activeSection' => null,
            'session' => $session,
        ] + $this->context($session));
    }

    public function chapterSection(Request $request, Topic $topic, Chapter $chapter, Section $section)
    {
        $session = $this->validateSession($request);
        abort_unless($topic->is_published, 404);

        // Ensure chapter belongs to topic
        if ($chapter->topic_id !== $topic->id) {
            abort(404);
        }

        // Ensure section belongs to chapter
        if ($section->chapter_id !== $chapter->id || ! $section->is_published) {
            abort(404);
        }

        // Load chapter with all its sections for navigation
        $chapter->loadMissing([
            'sections' => fn ($sq) => $sq->where('is_published', true)->orderBy('sort_order'),
        ]);

        // Find previous and next sections
        $sections = $chapter->sections;
        $currentIndex = $sections->search(fn ($s) => $s->id === $section->id);
        $prevSection = $currentIndex > 0 ? $sections[$currentIndex - 1] : null;
        $nextSection = $currentIndex < $sections->count() - 1 ? $sections[$currentIndex + 1] : null;

        return View::make('lti.embed.chapter', [
            'topic' => $topic,
            'chapter' => $chapter,
            'activeSection' => $section,
            'prevSection' => $prevSection,
            'nextSection' => $nextSection,
            'session' => $session,
        ] + $this->context($session));
    }

    public function chat(Request $request, Topic $topic)
    {
        $session = $this->validateSession($request);
        abort_unless($topic->is_published, 404);

        return View::make('lti.embed.chat', [
            'topic' => $topic,
            'session' => $session,
        ] + $this->context($session));
    }

    /**
     * What every embedded page needs to know about the launch: whether a
     * teacher is looking, and what the activity shows.
     */
    private function context($session): array
    {
        $content = LtiContent::forSession($session);

        return [
            'isInstructor' => $this->ltiService->isInstructor($session),
            'activity' => $content,
            // Only an activity chosen here - not through "Inhalt auswählen" - can be changed here
            'canRebind' => $content && ! empty(LtiContent::binding($session))
                && empty($session->claims['https://purl.imsglobal.org/spec/lti/claim/custom']['content_type']),
        ];
    }

    private function validateSession(Request $request)
    {
        $token = $request->query('lti_session');
        if (! $token) {
            abort(403, 'Missing LTI session');
        }

        $session = $this->ltiService->getSessionByToken($token);
        if (! $session) {
            abort(403, 'Invalid or expired LTI session');
        }

        return $session;
    }
}
