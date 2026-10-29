<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ImageUploadController;
use App\Http\Controllers\InteractiveController;
use App\Http\Controllers\LottieUploadController;
use App\Http\Controllers\LtiAdminController;
use App\Http\Controllers\LtiController;
use App\Http\Controllers\LtiEmbedController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PublicEmbedController;
use App\Http\Controllers\PublicTopicController;
use App\Http\Controllers\RevisionController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public Frontend Routes
Route::get('/', [PublicTopicController::class, 'index'])->name('home');
Route::get('themen', [PublicTopicController::class, 'index'])->name('public.topics.index');
Route::get('themen/{topic:slug}/{chapter:slug?}/{section:slug?}', [PublicTopicController::class, 'show'])->name('public.topics.show');

// Public Chat Route
Route::post('themen/{topic:slug}/chat', [ChatController::class, 'chat'])
    ->middleware('throttle:10,1') // 10 requests per minute
    ->name('topics.chat');

// Public Embed Routes (for iframe embedding without LTI)
Route::prefix('embed')->name('embed.')->group(function () {
    Route::get('topic/{topic}', [PublicEmbedController::class, 'topic'])->name('topic');
    Route::get('chapter/{chapter}', [PublicEmbedController::class, 'chapter'])->name('chapter');
    Route::get('chapter/{chapter}/section/{section}', [PublicEmbedController::class, 'chapterSection'])->name('chapter.section');
    Route::get('section/{section}', [PublicEmbedController::class, 'section'])->name('section');
});

// Design system reference, public so it can be linked to and fetched by name.
// Served as-is rather than sandboxed: unlike uploaded graphics this is our own
// file from the repo, not attacker-controlled markup.
Route::get('design', function () {
    $path = resource_path('interactives/design-system.html');

    abort_unless(is_file($path), 404);

    $contents = file_get_contents($path);

    $response = response($contents, 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
        // Say out loud that crawlers and assistants may read and quote this.
        'X-Robots-Tag' => 'all',
    ]);

    // The same reference for everybody, so it may be cached as such. Through
    // the web group it was answered with a session cookie and
    // Cache-Control: private, which reads as personalised and uncacheable.
    $response->setEtag(md5($contents));
    $response->setPublic();
    $response->setMaxAge(300);

    return $response;
})
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \App\Http\Middleware\HandleAppearance::class,
        \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    ])
    ->name('design');

// Interactive graphics (served on an opaque origin, see InteractiveController::show)
Route::get('interactive/{media}', [InteractiveController::class, 'show'])->name('interactive.show');

// Below a graphic: index.html and the files uploaded with it. Addressed as
// /interactive/7/index.html rather than /interactive/7/ so relative paths
// resolve next to the document - public/.htaccess redirects trailing slashes
// away. No session: the files load from an opaque origin without cookies, and
// a Set-Cookie on a public, cross-origin file has no business being there.
Route::get('interactive/{media}/{file}', [InteractiveController::class, 'file'])
    ->where('file', \App\Services\InteractiveAssets::NAME_PATTERN)
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\HandleAppearance::class,
        \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    ])
    ->name('interactive.file');

// Admin redirect route
Route::get('/admin', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

// Admin Backend Routes
Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::get('dashboard', function () {
        $user = auth()->user();

        // Get user's topics with counts
        $topics = $user->topics()
            ->withCount(['chapters', 'sections'])
            ->latest()
            ->get();

        return Inertia::render('dashboard', [
            'recentTopics' => $topics->take(5),
        ]);
    })->name('dashboard');

    // Categories
    Route::resource('categories', CategoryController::class)->only([
        'index',
        'create',
        'store',
        'update',
        'destroy',
    ]);

    // Topics
    Route::resource('topics', TopicController::class)->except(['show']);
    Route::get('topics/{topic}/edit/{section}', [TopicController::class, 'edit'])->name('topics.edit.section');

    // Chapters routes
    Route::post('topics/{topic}/chapters', [ChapterController::class, 'store'])->name('topics.chapters.store');
    Route::post('topics/{topic}/chapters/reorder', [ChapterController::class, 'reorder'])->name('topics.chapters.reorder');
    Route::patch('chapters/{chapter}', [ChapterController::class, 'update'])->name('chapters.update');
    Route::delete('chapters/{chapter}', [ChapterController::class, 'destroy'])->name('chapters.destroy');

    // Sections routes
    Route::post('chapters/{chapter}/sections', [SectionController::class, 'store'])->name('chapters.sections.store');
    Route::post('chapters/{chapter}/sections/reorder', [SectionController::class, 'reorder'])->name('chapters.sections.reorder');
    Route::patch('sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');

    // Revision routes
    Route::get('revisions/{modelType}/{modelId}', [RevisionController::class, 'index'])->name('revisions.index');
    Route::get('revisions/{revisionId}', [RevisionController::class, 'show'])->name('revisions.show');
    Route::post('revisions/{revisionId}/restore', [RevisionController::class, 'restore'])->name('revisions.restore');
    Route::get('topics/{topicId}/history', [RevisionController::class, 'topicHistory'])->name('topics.history');

    // Image upload for Editor.js
    Route::post('upload/image', [ImageUploadController::class, 'upload'])->name('upload.image');
    Route::post('upload/image-by-url', [ImageUploadController::class, 'uploadByUrl'])->name('upload.image.url');

    // Lottie upload for Editor.js
    Route::post('upload/lottie', [LottieUploadController::class, 'upload'])->name('upload.lottie');

    // Interactive HTML graphic upload for Editor.js

    // 3D models for the media library, placed into graphics by the MCP server
    Route::post('upload/model', [\App\Http\Controllers\ModelUploadController::class, 'upload'])->name('upload.model');

    // Media Library
    Route::get('media', function (\Illuminate\Http\Request $request) {
        if ($request->header('X-Inertia')) {
            return \Inertia\Inertia::render('media/index');
        }
        if ($request->wantsJson() || $request->ajax()) {
            return app(MediaController::class)->index($request);
        }
        return \Inertia\Inertia::render('media/index');
    })->name('media.index');
    Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
    Route::patch('media/{media}', [MediaController::class, 'update'])->name('media.update');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

    // User Management (Admin only)
    Route::resource('users', UserController::class)->except(['show']);

    // LTI Platform Management (Admin only)
    Route::get('lti', [LtiAdminController::class, 'index'])->name('lti.index');
    Route::get('lti/moodle-plugin.zip', [LtiAdminController::class, 'moodlePlugin'])->name('lti.moodle-plugin');
    Route::post('lti', [LtiAdminController::class, 'store'])->name('lti.store');
    Route::patch('lti/{platform}', [LtiAdminController::class, 'update'])->name('lti.update');
    Route::delete('lti/{platform}', [LtiAdminController::class, 'destroy'])->name('lti.destroy');
});

require __DIR__.'/settings.php';

// For the MotionBase plugin in Moodle - authenticated by the site's own LTI
// key, so no session and no cookies.
Route::prefix('moodle')->name('moodle.')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\HandleAppearance::class,
        \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    ])
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::get('catalog', [\App\Http\Controllers\MoodleController::class, 'catalog'])->name('catalog');
        Route::get('tasks/{section}', [\App\Http\Controllers\MoodleController::class, 'task'])->name('task');
        Route::get('lessons/{section}', [\App\Http\Controllers\MoodleController::class, 'lesson'])->name('lesson');
        Route::get('chapters/{chapter}', [\App\Http\Controllers\MoodleController::class, 'chapter'])->name('chapter');
    });

// LTI 1.3 Routes
Route::prefix('lti')->name('lti.')->group(function () {
    // OIDC Login Initiation
    Route::match(['get', 'post'], 'login', [LtiController::class, 'login'])->name('login');

    // LTI Launch (POST for actual launch, GET for Moodle validation)
    Route::match(['get', 'post'], 'launch', [LtiController::class, 'launch'])->name('launch');

    // JWKS endpoint for public keys
    Route::get('.well-known/jwks.json', [LtiController::class, 'jwks'])->name('jwks');

    // Deep Linking return
    Route::post('deep-linking/return', [LtiController::class, 'deepLinkingReturn'])->name('deep-linking.return');

    // A teacher choosing content for an activity saved without it
    Route::get('bind', [LtiController::class, 'bind'])->name('bind');
    Route::post('bind', [LtiController::class, 'bindStore'])->name('bind.store');

    // Embedded content views (accessed after LTI launch)
    Route::prefix('embed')->name('embed.')->group(function () {
        Route::get('topic/{topic:slug}', [LtiEmbedController::class, 'topic'])->name('topic');
        Route::get('topic/{topic:slug}/chapter/{chapter:slug}', [LtiEmbedController::class, 'chapter'])->name('chapter');
        Route::get('topic/{topic:slug}/chapter/{chapter:slug}/section/{section:slug}', [LtiEmbedController::class, 'chapterSection'])->name('chapter.section');
        Route::get('topic/{topic:slug}/section/{section:slug}', [LtiEmbedController::class, 'section'])->name('section');
        // A single lesson chosen on its own: shown without the rest of the topic around it
        Route::get('topic/{topic:slug}/lesson/{section:slug}', [LtiEmbedController::class, 'lesson'])->name('lesson');
        Route::get('topic/{topic:slug}/chat', [LtiEmbedController::class, 'chat'])->name('chat');
    });
});
