<?php

use App\Mcp\Servers\MotionBaseServer;
use App\Mcp\Tools\GetSection;
use App\Mcp\Tools\UpdateSection;
use App\Models\Chapter;
use App\Models\LtiPlatform;
use App\Models\Section;
use App\Models\Topic;
use App\Models\User;
use App\Services\MoodleHtml;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // The school's Moodle: its LTI key pair, and the key set it publishes.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $this->moodleKey = $private;
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    LtiPlatform::create([
        'name' => 'Schule', 'issuer' => 'https://schule.test', 'client_id' => 'tool-1', 'deployment_id' => '1',
        'auth_login_url' => 'https://schule.test/mod/lti/auth.php',
        'auth_token_url' => 'https://schule.test/mod/lti/token.php',
        'jwks_url' => 'https://schule.test/mod/lti/certs.php', 'is_active' => true,
    ]);

    Http::fake(['schule.test/*' => Http::response(['keys' => [[
        'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'schule-1',
        'n' => $b64($rsa['n']), 'e' => $b64($rsa['e']),
    ]]])]);
});

/** A request token as the plugin signs it. */
function moodlePluginToken(string $privateKey, array $claims = []): string
{
    return JWT::encode(array_replace([
        'iss' => 'https://schule.test',
        'sub' => 'tool-1',
        'aud' => rtrim(config('app.url'), '/').'/moodle',
        'iat' => time(),
        'exp' => time() + 60,
    ], $claims), $privateKey, 'RS256', 'schule-1');
}

function moodleTask(array $attributes = [], array $blocks = []): Section
{
    $chapter = Chapter::factory()->for(Topic::factory()->create(['title' => 'Easing']))->create(['title' => 'Aufgaben']);

    return Section::factory()->for($chapter)->create(array_merge([
        'title' => 'Eigene Kurve bauen',
        'task_submission' => 'file',
        'content' => ['blocks' => $blocks ?: [['type' => 'paragraph', 'data' => ['text' => 'Baue eine Kurve.']]]],
    ], $attributes));
}

// ------------------------------------------------------------- Zugang ---

it('lets a registered Moodle read the catalogue, with tasks marked', function () {
    $task = moodleTask();
    Section::factory()->for($task->chapter)->create(['title' => 'Nur Lektüre']);

    $response = getJson('/moodle/catalog', ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)])
        ->assertOk();

    $sections = collect($response->json('topics.0.chapters.0.sections'))->keyBy('title');

    expect($sections['Eigene Kurve bauen']['task'])->toBe('file')
        ->and($sections['Nur Lektüre']['task'])->toBeNull();
});

it('keeps out requests that are not from a registered Moodle', function (string $case) {
    moodleTask();

    $key = $this->moodleKey;
    $token = match ($case) {
        'no token' => null,
        'garbage' => 'not.a.token',
        'signed with another key' => (function () {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $other);

            return moodlePluginToken($other);
        })(),
        'unknown site' => moodlePluginToken($key, ['iss' => 'https://fremd.test']),
        'unknown tool' => moodlePluginToken($key, ['sub' => 'other-tool']),
        'meant for another endpoint' => moodlePluginToken($key, ['aud' => rtrim(config('app.url'), '/').'/lti/launch']),
        'expired' => moodlePluginToken($key, ['iat' => time() - 600, 'exp' => time() - 60]),
        'valid for too long' => moodlePluginToken($key, ['exp' => time() + 3600]),
    };

    getJson('/moodle/catalog', $token ? ['Authorization' => 'Bearer '.$token] : [])->assertUnauthorized();
})->with([
    'no token', 'garbage', 'signed with another key', 'unknown site', 'unknown tool',
    'meant for another endpoint', 'expired', 'valid for too long',
]);

it('keeps out a Moodle that was switched off', function () {
    LtiPlatform::where('issuer', 'https://schule.test')->update(['is_active' => false]);

    getJson('/moodle/catalog', ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)])
        ->assertUnauthorized();
});

it('needs no session or cookies', function () {
    $response = getJson('/moodle/catalog', ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)]);

    expect($response->headers->getCookies())->toBeEmpty();
});

// ------------------------------------------------------------ Aufgaben ---

it('hands a task over as the description of an assignment', function () {
    $task = moodleTask();

    getJson("/moodle/tasks/{$task->id}", ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)])
        ->assertOk()
        ->assertJson([
            'id' => $task->id,
            'title' => 'Eigene Kurve bauen',
            'topic' => 'Easing',
            'chapter' => 'Aufgaben',
            'submission' => 'file',
        ])
        ->assertJsonPath('html', fn (string $html) => str_contains($html, '<p>Baue eine Kurve.</p>'));
});

it('hands over only published tasks', function (array $attributes, bool $chapterPublished) {
    $task = moodleTask($attributes);
    $task->chapter->update(['is_published' => $chapterPublished]);

    getJson("/moodle/tasks/{$task->id}", ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)])
        ->assertNotFound();
})->with([
    'plain lesson' => [['task_submission' => null], true],
    'unpublished lesson' => [['is_published' => false], true],
    'unpublished chapter' => [[], false],
]);

// -------------------------------------------------- Lektionen, Kapitel ---

it('hands over any published lesson, with graphics embedded when asked', function () {
    $lesson = moodleTask(['task_submission' => null], [
        ['type' => 'paragraph', 'data' => ['text' => 'Schau dir die Kurve an.']],
        ['type' => 'interactive', 'data' => ['url' => '/interactive/7', 'caption' => 'Simulator', 'height' => 640]],
    ]);
    $auth = ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)];
    $app = rtrim(config('app.url'), '/');

    $stored = getJson("/moodle/lessons/{$lesson->id}", $auth)->assertOk()
        ->assertJson(['id' => $lesson->id, 'chapter_id' => $lesson->chapter_id])->json('html');
    $live = getJson("/moodle/lessons/{$lesson->id}?embed=1", $auth)->assertOk()->json('html');

    // What Moodle stores goes through its cleaning, which drops frames: a link
    expect($stored)->toContain('<a href="'.$app.'/interactive/7">Interaktive Grafik öffnen: Simulator</a>')
        ->and($stored)->not->toContain('<iframe')
        // What the filter shows is not cleaned again: the graphic itself, sandboxed
        ->and($live)->toContain('<iframe class="motionbase-frame" src="'.$app.'/interactive/7" title="Simulator" sandbox="allow-scripts"')
        ->and($live)->toContain('height: 640px')
        // No link back: the content is current anyway
        ->and($live)->not->toContain('mb-source');
});

it('lets an eifach.online page keep its own origin, but not an upload of ours', function () {
    $app = rtrim(config('app.url'), '/');
    $lesson = moodleTask(['task_submission' => null], [
        ['type' => 'interactive', 'data' => ['url' => '/interactive/7', 'caption' => 'Eigene', 'height' => 400]],
        ['type' => 'interactive', 'data' => ['url' => 'https://bzz.eifach.online/physik-pendel/', 'caption' => 'Pendel', 'height' => 400]],
    ]);

    $html = app(MoodleHtml::class)->render($lesson, embed: true);

    // Eigene Uploads laufen auf einer undurchsichtigen Herkunft, sonst käme
    // hochgeladenes HTML an Cookies und Speicher der App.
    expect($html)->toContain('src="'.$app.'/interactive/7" title="Eigene" sandbox="allow-scripts"')
        // Eine fremde Seite hat ihre Herkunft ohnehin; sie braucht sie für
        // ihre eigene Datenbank und ihren Speicher.
        ->and($html)->toContain('src="https://bzz.eifach.online/physik-pendel/" title="Pendel" sandbox="allow-scripts allow-same-origin');
});

it('hands over only published lessons', function () {
    $lesson = moodleTask(['is_published' => false]);

    getJson("/moodle/lessons/{$lesson->id}", ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)])
        ->assertNotFound();
});

it('describes a chapter as its published lessons without tasks, in order', function () {
    $chapter = Chapter::factory()->for(Topic::factory()->create(['title' => 'Easing']))->create(['title' => 'Grundlagen']);
    $second = Section::factory()->for($chapter)->create(['title' => 'Zweite', 'sort_order' => 2]);
    $first = Section::factory()->for($chapter)->create(['title' => 'Erste', 'sort_order' => 1]);
    Section::factory()->for($chapter)->unpublished()->create(['title' => 'Entwurf', 'sort_order' => 3]);
    // An assignment of its own in Moodle, not a book chapter as well
    Section::factory()->for($chapter)->create(['title' => 'Aufgabe', 'sort_order' => 4, 'task_submission' => 'file']);
    $auth = ['Authorization' => 'Bearer '.moodlePluginToken($this->moodleKey)];

    // Checked each time a book is opened: kept light
    getJson("/moodle/chapters/{$chapter->id}", $auth)->assertOk()
        ->assertExactJson([
            'id' => $chapter->id,
            'title' => 'Grundlagen',
            'topic' => 'Easing',
            'lessons' => [
                ['id' => $first->id, 'title' => 'Erste'],
                ['id' => $second->id, 'title' => 'Zweite'],
            ],
        ]);

    // For a new book, with the content
    expect(getJson("/moodle/chapters/{$chapter->id}?html=1", $auth)->json('lessons.0.html'))
        ->toContain('<p>'.e($first->content['blocks'][0]['data']['text']).'</p>');

    $chapter->update(['is_published' => false]);
    getJson("/moodle/chapters/{$chapter->id}", $auth)->assertNotFound();
});

// ---------------------------------------------------------------- HTML ---

it('renders a task as plain HTML for Moodle', function () {
    $task = moodleTask(blocks: [
        ['type' => 'header', 'data' => ['text' => 'Anforderungen', 'level' => 2]],
        ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => [
            ['content' => 'Erstens', 'items' => [['content' => 'Unterpunkt', 'items' => []]]],
            'Zweitens',
        ]]],
        ['type' => 'code', 'data' => ['code' => '<script>alert(1)</script>', 'language' => 'html']],
        ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Eingabe', 'Ausgabe'], ['3 1 2', '1 2 3']]]],
        ['type' => 'image', 'data' => ['url' => '/storage/media/kurve.png', 'caption' => 'Die Kurve']],
        ['type' => 'alert', 'data' => ['type' => 'warning', 'content' => 'Achtung']],
        ['type' => 'youtube', 'data' => ['videoId' => 'abc123', 'caption' => 'Erklärvideo']],
        ['type' => 'quiz', 'data' => ['questions' => [['question' => 'Geheim?', 'answers' => []]]]],
    ]);

    $html = app(MoodleHtml::class)->render($task);
    $app = rtrim(config('app.url'), '/');

    // Beneath Moodle's own heading for the activity
    expect($html)->toContain('<h3>Anforderungen</h3>')
        ->and($html)->toContain('<ol><li>Erstens<ol><li>Unterpunkt</li></ol></li><li>Zweitens</li></ol>')
        ->and($html)->toContain('<pre><code class="language-html">&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>')
        ->and($html)->toContain('<th scope="col">Eingabe</th>')
        ->and($html)->toContain('<td>3 1 2</td>')
        // "/storage/..." would point at the school's own server
        ->and($html)->toContain('<img src="'.$app.'/storage/media/kurve.png" alt="Die Kurve"')
        ->and($html)->toContain('<div class="alert alert-warning"><p>Achtung</p></div>')
        ->and($html)->toContain('href="https://www.youtube.com/watch?v=abc123">Erklärvideo</a>')
        // A quiz cannot work in a description; its answers must not leak
        ->and($html)->not->toContain('Geheim')
        ->and($html)->toContain('>Diese Aufgabe in MotionBase öffnen</a>');
});

it('keeps only harmless inline markup', function () {
    $task = moodleTask(blocks: [
        ['type' => 'paragraph', 'data' => ['text' => '<b>fett</b> <a href="javascript:alert(1)">klick</a> <img src=x onerror="alert(1)"><script>alert(2)</script><span style="color:red" onclick="x()">rot</span> <a href="https://example.com" onclick="x()">gut</a>']],
        ['type' => 'header', 'data' => ['text' => '<iframe src="https://evil.test"></iframe>Titel', 'level' => 3]],
        ['type' => 'image', 'data' => ['url' => 'javascript:alert(1)']],
        ['type' => 'interactive', 'data' => ['url' => 'data:text/html,<script>alert(1)</script>']],
    ]);

    $html = app(MoodleHtml::class)->render($task);

    expect($html)->toContain('<b>fett</b>')
        ->and($html)->toContain('<a>klick</a>')
        ->and($html)->toContain('rot')
        ->and($html)->toContain('<a href="https://example.com">gut</a>')
        ->and($html)->toContain('<h4>Titel</h4>')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('onclick')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('data:')
        ->and($html)->not->toContain('style=');
});

// ----------------------------------------------------- Autoren und MCP ---

it('creates a task with the scaffolding a task needs', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();

    actingAs($user)->post("/admin/chapters/{$chapter->id}/sections", ['title' => 'Eigene Kurve bauen', 'kind' => 'task'])
        ->assertRedirect();

    $task = $chapter->sections()->firstOrFail();
    $headings = collect($task->content['blocks'])->where('type', 'header')->pluck('data.text');

    // Ohne Abgabe, bis die Autorin eine wählt - in Moodle schon eine Aufgabe
    expect($task->task_submission)->toBe('none')
        ->and($headings->all())->toBe(['Anforderungen', 'Vorgehen']);

    actingAs($user)->post("/admin/chapters/{$chapter->id}/sections", ['title' => 'Theorie', 'kind' => 'page'])
        ->assertRedirect();
    expect($chapter->sections()->where('title', 'Theorie')->value('task_submission'))->toBeNull();

    actingAs($user)->post("/admin/chapters/{$chapter->id}/sections", ['title' => 'Falsch', 'kind' => 'exam'])
        ->assertSessionHasErrors('kind');
});

it('lets authors mark a lesson as a task', function () {
    $user = User::factory()->create();
    $section = Section::factory()->for(Chapter::factory()->for(Topic::factory()->for($user)))->create();

    actingAs($user)->patch("/admin/sections/{$section->id}", ['task_submission' => 'both'])->assertRedirect();
    expect($section->fresh()->task_submission)->toBe('both');

    actingAs($user)->patch("/admin/sections/{$section->id}", ['task_submission' => 'exam'])->assertSessionHasErrors('task_submission');
    expect($section->fresh()->task_submission)->toBe('both');

    actingAs($user)->patch("/admin/sections/{$section->id}", ['task_submission' => null])->assertRedirect();
    expect($section->fresh()->task_submission)->toBeNull();
});

it('lets the MCP server mark a lesson as a task and back', function () {
    $user = User::factory()->create();
    $section = Section::factory()->for(Chapter::factory()->for(Topic::factory()->for($user)))->create();

    MotionBaseServer::actingAs($user)
        ->tool(UpdateSection::class, ['section_id' => $section->id, 'task' => 'text'])
        ->assertOk();
    expect($section->fresh()->task_submission)->toBe('text');

    MotionBaseServer::actingAs($user)
        ->tool(GetSection::class, ['section_id' => $section->id])
        ->assertSee('"task":"text"');

    MotionBaseServer::actingAs($user)
        ->tool(UpdateSection::class, ['section_id' => $section->id, 'task' => 'off'])
        ->assertOk();
    expect($section->fresh()->task_submission)->toBeNull();

    MotionBaseServer::actingAs($user)
        ->tool(UpdateSection::class, ['section_id' => $section->id, 'task' => 'exam'])
        ->assertHasErrors();
});

// ------------------------------------------------------------- Plugin ---

it('gives admins the plugin, with this MotionBase already set', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = actingAs($admin)->get('/admin/lti/moodle-plugin.zip')->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    // Moodle's installer wants the plugin's folder at the top of the zip
    expect($zip->locateName('motionbase/version.php'))->not->toBeFalse()
        ->and($zip->locateName('motionbase/lib.php'))->not->toBeFalse()
        ->and($zip->locateName('motionbase/amd/build/picker.min.js'))->not->toBeFalse()
        ->and($zip->locateName('motionbase/classes/text_filter.php'))->not->toBeFalse()
        ->and($zip->getFromName('motionbase/version.php'))->toContain("'filter_motionbase'")
        ->and($zip->getFromName('motionbase/settings.php'))->toContain(var_export(rtrim(config('app.url'), '/'), true));

    $zip->close();
});

it('keeps platforms and the plugin to admins', function () {
    $author = User::factory()->create(['is_admin' => false]);

    actingAs($author)->get('/admin/lti')->assertForbidden();
    actingAs($author)->get('/admin/lti/moodle-plugin.zip')->assertForbidden();
    actingAs($author)->post('/admin/lti', [
        'name' => 'Eigenes Moodle', 'issuer' => 'https://eigen.test', 'client_id' => 'x',
        'auth_login_url' => 'https://eigen.test/a', 'auth_token_url' => 'https://eigen.test/t', 'jwks_url' => 'https://eigen.test/j',
    ])->assertForbidden();

    expect(LtiPlatform::where('issuer', 'https://eigen.test')->exists())->toBeFalse();
});
