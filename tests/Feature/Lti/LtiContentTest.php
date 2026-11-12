<?php

use App\Models\Chapter;
use App\Models\LtiPlatform;
use App\Models\LtiResourceLink;
use App\Models\LtiSession;
use App\Models\Section;
use App\Models\Topic;
use App\Services\LtiContent;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // A throwaway key pair: the real ones are not in the repository.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $dir = sys_get_temp_dir().'/lti-'.Str::random(8);
    mkdir($dir);
    file_put_contents("$dir/private.pem", $private);
    file_put_contents("$dir/public.pem", openssl_pkey_get_details($key)['key']);
    config(['lti.private_key_path' => "$dir/private.pem", 'lti.public_key_path' => "$dir/public.pem"]);

    Http::fake();
});

function moodle(): LtiPlatform
{
    return LtiPlatform::firstOrCreate(['issuer' => 'https://moodle.test'], [
        'name' => 'Moodle', 'client_id' => 'client-1', 'deployment_id' => '1',
        'auth_login_url' => 'https://moodle.test/mod/lti/auth.php',
        'auth_token_url' => 'https://moodle.test/mod/lti/token.php',
        'jwks_url' => 'https://moodle.test/mod/lti/certs.php', 'is_active' => true,
    ]);
}

function launch(array $claims = [], bool $teacher = false): LtiSession
{
    $claims = array_replace([
        'sub' => 'learner-7',
        'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
        'https://purl.imsglobal.org/spec/lti/claim/roles' => [
            'http://purl.imsglobal.org/vocab/lis/v2/membership#'.($teacher ? 'Instructor' : 'Learner'),
        ],
        'https://purl.imsglobal.org/spec/lti/claim/resource_link' => ['id' => '42'],
    ], $claims);

    return LtiSession::create([
        'lti_platform_id' => moodle()->id,
        'lti_user_id' => $claims['sub'],
        'resource_link_id' => $claims['https://purl.imsglobal.org/spec/lti/claim/resource_link']['id'] ?? null,
        'claims' => $claims,
        'session_token' => Str::random(64),
        'expires_at' => now()->addHour(),
    ]);
}

function quizLesson(Chapter $chapter, int $questions = 3, string $blockId = 'check-1'): Section
{
    $items = [];
    for ($i = 1; $i <= $questions; $i++) {
        $items[] = ['id' => "{$blockId}-q{$i}", 'question' => "Frage {$i}", 'answers' => [
            ['id' => "{$blockId}-q{$i}-wrong", 'text' => 'Falsch', 'isCorrect' => false],
            ['id' => "{$blockId}-q{$i}-right", 'text' => 'Richtig', 'isCorrect' => true],
        ]];
    }

    return Section::factory()->create(['chapter_id' => $chapter->id, 'content' => ['blocks' => [
        ['id' => 'intro', 'type' => 'paragraph', 'data' => ['text' => 'Einleitung']],
        ['id' => $blockId, 'type' => 'quiz', 'data' => ['questions' => $items]],
    ]]]);
}

// ---------------------------------------------------------------- Auswahl ---

it('lets a teacher choose a chapter, and never asks Moodle for a grade column', function () {
    $chapter = Chapter::factory()->create();
    quizLesson($chapter, 3);

    $session = launch([
        'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest',
        'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings' => ['deep_link_return_url' => 'https://moodle.test/mod/lti/contentitem_return.php'],
    ], teacher: true);

    $item = function (string $choice) use ($session) {
        $html = post('/lti/deep-linking/return', ['lti_session' => $session->session_token, 'choice' => $choice])
            ->assertOk()->getContent();
        preg_match('/name="JWT" value="([^"]+)"/', $html, $m);
        $claims = (array) JWT::decode($m[1], new Key(file_get_contents(config('lti.public_key_path')), 'RS256'));

        return json_decode(json_encode($claims['https://purl.imsglobal.org/spec/lti-dl/claim/content_items'][0]), true);
    };

    $quiz = $item("chapter:{$chapter->id}");

    expect($quiz['custom']['chapter_id'])->toBe((string) $chapter->id)
        // Ids travel along, so renaming a chapter does not break the activity
        ->and($quiz['custom']['content_type'])->toBe('chapter')
        ->and($quiz['text'])->toBe('MotionBase: 1 Lektion.')
        // No grading through MotionBase - not even for a chapter with a quiz in it
        ->and($quiz)->not->toHaveKey('lineItem');
});

it('refuses content that is not published or a session that is not choosing', function () {
    $hidden = Chapter::factory()->create(['is_published' => false]);
    Section::factory()->create(['chapter_id' => $hidden->id]);

    $choosing = launch(['https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest'], teacher: true);

    post('/lti/deep-linking/return', ['lti_session' => $choosing->session_token, 'choice' => "chapter:{$hidden->id}"])
        ->assertSessionHasErrors('choice');

    $viewing = launch(teacher: true);
    $open = Chapter::factory()->create();
    Section::factory()->create(['chapter_id' => $open->id]);

    post('/lti/deep-linking/return', ['lti_session' => $viewing->session_token, 'choice' => "chapter:{$open->id}"])
        ->assertForbidden();
});

it('lists only what a class could actually see', function () {
    $topic = Topic::factory()->create(['title' => 'Easing']);
    $chapter = Chapter::factory()->create(['topic_id' => $topic->id]);
    quizLesson($chapter, 2);
    Chapter::factory()->create(['topic_id' => $topic->id, 'title' => 'Leer']);
    Topic::factory()->create(['title' => 'Ohne Inhalt']);

    $catalog = collect(LtiContent::catalog());

    expect($catalog->pluck('title')->all())->toBe(['Easing'])
        ->and($catalog[0]['chapters'])->toHaveCount(1)
        ->and($catalog[0]['lessons'])->toBe(1);
});

// ----------------------------------------------------------- Aufruf & Bindung

it('finds the content by id, and still by slug for links made before ids', function () {
    $chapter = Chapter::factory()->create();
    Section::factory()->create(['chapter_id' => $chapter->id]);

    $byId = launch(['https://purl.imsglobal.org/spec/lti/claim/custom' => ['content_type' => 'chapter', 'chapter_id' => (string) $chapter->id]]);
    $bySlug = launch(['https://purl.imsglobal.org/spec/lti/claim/custom' => [
        'content_type' => 'chapter', 'topic_slug' => $chapter->topic->slug, 'chapter_slug' => $chapter->slug,
    ]]);

    // Renamed since: the id still finds it
    $chapter->update(['slug' => 'umbenannt']);

    expect(LtiContent::forSession($byId)?->chapter->id)->toBe($chapter->id)
        ->and(LtiContent::forSession($bySlug))->toBeNull();
});

it('lets a teacher choose content for an activity saved without it', function () {
    $chapter = Chapter::factory()->create();
    Section::factory()->create(['chapter_id' => $chapter->id]);
    $session = launch(teacher: true);

    get("/lti/bind?lti_session={$session->session_token}")->assertOk()->assertSee('Diese Aktivität zeigt noch nichts');

    post('/lti/bind', ['lti_session' => $session->session_token, 'choice' => "chapter:{$chapter->id}"])
        ->assertRedirect();

    expect(LtiResourceLink::firstOrFail()->chapter_id)->toBe($chapter->id)
        // Every later launch of the same activity shows it - learners included
        ->and(LtiContent::forSession(launch())?->chapter->id)->toBe($chapter->id);

    // And Moodle was never asked for anything
    Http::assertNothingSent();
});

it('keeps learners out of choosing, and leaves Moodle-chosen content to Moodle', function () {
    $chapter = Chapter::factory()->create();
    Section::factory()->create(['chapter_id' => $chapter->id]);

    get('/lti/bind?lti_session='.launch()->session_token)->assertForbidden();

    $chosen = launch(['https://purl.imsglobal.org/spec/lti/claim/custom' => ['content_type' => 'chapter', 'chapter_id' => (string) $chapter->id]], teacher: true);

    post('/lti/bind', ['lti_session' => $chosen->session_token, 'choice' => "chapter:{$chapter->id}"])
        ->assertStatus(409);
});

// ------------------------------------------------------------ Darstellung ---

it('shows a single chosen lesson without the rest of the topic around it', function () {
    $chapter = Chapter::factory()->create();
    $chosen = Section::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Die gewählte Lektion']);
    Section::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Eine andere Lektion', 'sort_order' => 1]);
    $session = launch(['https://purl.imsglobal.org/spec/lti/claim/custom' => ['content_type' => 'section', 'section_id' => (string) $chosen->id]]);

    get(LtiContent::forSession($session)->url($session->session_token))
        ->assertOk()
        ->assertSee('Die gewählte Lektion')
        ->assertDontSee('Eine andere Lektion');
});

it('does not show a lesson under a topic it does not belong to', function () {
    $lesson = Section::factory()->create();
    $otherTopic = Topic::factory()->create();

    get("/lti/embed/topic/{$otherTopic->slug}/section/{$lesson->slug}?lti_session=".launch()->session_token)
        ->assertNotFound();
});

it('tells the teacher what the class sees, and only the teacher', function () {
    $chapter = Chapter::factory()->create(['title' => 'Aufgaben']);
    Section::factory()->create(['chapter_id' => $chapter->id]);
    $custom = ['https://purl.imsglobal.org/spec/lti/claim/custom' => ['content_type' => 'chapter', 'chapter_id' => (string) $chapter->id]];

    $teacher = launch($custom, teacher: true);

    $this->followingRedirects()
        ->get(LtiContent::forSession($teacher)->url($teacher->session_token))
        ->assertSee('Nur für Lehrpersonen sichtbar')
        ->assertSee($chapter->topic->title.' – Aufgaben');

    $learner = launch($custom);

    $this->followingRedirects()
        ->get(LtiContent::forSession($learner)->url($learner->session_token))
        ->assertDontSee('Nur für Lehrpersonen sichtbar');
});

it('offers only published and private courses, and never shows a draft', function () {
    foreach (Topic::STATUSES as $status) {
        $topic = Topic::factory()->status($status)->create(['title' => $status]);
        quizLesson(Chapter::factory()->create(['topic_id' => $topic->id]), 1);
    }

    expect(collect(LtiContent::catalog())->pluck('title')->sort()->values()->all())->toBe(['private', 'published']);

    $draft = Topic::where('status', Topic::DRAFT)->first();
    expect(LtiContent::fromChoice("topic:{$draft->id}"))->toBeNull()
        ->and(LtiContent::fromChoice("chat:{$draft->id}"))->toBeNull()
        ->and(LtiContent::fromChoice('chapter:'.$draft->chapters()->value('id')))->toBeNull();

    // A course being revised still resolves, so a launch shows its note instead of an error
    $revision = Topic::where('status', Topic::REVISION)->first();
    expect(LtiContent::fromChoice("topic:{$revision->id}"))->not->toBeNull();
});
