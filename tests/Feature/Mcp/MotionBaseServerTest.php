<?php

use App\Mcp\Servers\MotionBaseServer;
use App\Mcp\Support\MarkdownBlocks;
use App\Mcp\Tools\AddInteractiveBlock;
use App\Mcp\Tools\AddAlertBlock;
use App\Mcp\Tools\AddImageBlock;
use App\Mcp\Tools\AddLottieBlock;
use App\Mcp\Tools\AddQuizBlock;
use App\Mcp\Tools\AddYoutubeBlock;
use App\Mcp\Tools\DeleteContent;
use App\Mcp\Tools\ListMedia;
use App\Mcp\Tools\MoveBlock;
use App\Mcp\Tools\RemoveBlock;
use App\Mcp\Tools\CreateSection;
use App\Mcp\Tools\GetSection;
use App\Mcp\Tools\GetTopic;
use App\Mcp\Tools\ListBlockTypes;
use App\Mcp\Tools\ListTopics;
use App\Mcp\Tools\UpdateSection;
use App\Models\Chapter;
use App\Models\Media;
use App\Models\Section;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function course(User $owner): array
{
    $topic = Topic::factory()->create(['user_id' => $owner->id, 'title' => 'Easing']);
    $chapter = Chapter::factory()->create(['topic_id' => $topic->id, 'title' => 'Grundlagen']);
    $section = Section::factory()->create([
        'chapter_id' => $chapter->id,
        'title' => 'Was ist Easing?',
        'content' => ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Ein <b>Absatz</b>.']],
            ['type' => 'interactive', 'data' => ['url' => '/interactive/7', 'caption' => '', 'height' => 640]],
        ]],
    ]);

    return [$topic, $chapter, $section];
}

it('refuses unauthenticated calls and points at the auth server', function () {
    $response = postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    $response->assertStatus(401);

    // Without this header an MCP client cannot discover where to authenticate.
    expect($response->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata=')
        ->toContain('/.well-known/oauth-protected-resource/mcp');
});

it('publishes oauth discovery documents', function () {
    $this->get('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
        ->assertJsonStructure(['issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint']);

    $this->get('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use']);
});

it('lists only the acting user\'s own courses', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    course($owner);
    Topic::factory()->create(['user_id' => $stranger->id, 'title' => 'Fremdkurs']);

    MotionBaseServer::actingAs($owner)
        ->tool(ListTopics::class)
        ->assertOk()
        ->assertSee('Easing')
        ->assertDontSee('Fremdkurs');
});

it('never reaches another user\'s content, even with the right id', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    [$topic, $chapter, $section] = course($owner);

    $acting = fn () => MotionBaseServer::actingAs($stranger);

    // A record that exists but belongs to someone else must be indistinguishable
    // from one that does not exist, or the tools become an id oracle.
    $acting()->tool(GetTopic::class, ['topic_id' => $topic->id])->assertHasErrors();
    $acting()->tool(GetSection::class, ['section_id' => $section->id])->assertHasErrors();
    $acting()->tool(CreateSection::class, ['chapter_id' => $chapter->id, 'title' => 'Fremd'])->assertHasErrors();
    $acting()->tool(UpdateSection::class, ['section_id' => $section->id, 'title' => 'Fremd'])->assertHasErrors();

    expect($section->fresh()->title)->toBe('Was ist Easing?');
});

it('round-trips markdown through the editor block format', function () {
    $owner = User::factory()->create();
    [, $chapter] = course($owner);

    $markdown = <<<'MD'
    Ein Absatz mit **fett**, *kursiv* und `code`.

    ### Unterüberschrift

    - Erster Punkt
    - Zweiter Punkt

    ```css
    .a { transition-timing-function: ease-out; }
    ```
    MD;

    MotionBaseServer::actingAs($owner)
        ->tool(CreateSection::class, [
            'chapter_id' => $chapter->id,
            'title' => 'Neue Seite',
            'markdown' => $markdown,
        ])->assertOk();

    $section = Section::where('title', 'Neue Seite')->firstOrFail();

    expect(collect($section->content['blocks'])->pluck('type')->all())
        ->toBe(['paragraph', 'header', 'list', 'code']);

    MotionBaseServer::actingAs($owner)
        ->tool(GetSection::class, ['section_id' => $section->id])
        ->assertOk();

    // Compared against the conversion itself - assertSee would be matching the
    // JSON-escaped wire format, where every newline is a literal \n.
    expect(MarkdownBlocks::toMarkdown($section->content['blocks']))->toBe($markdown);
});

it('reports which rich blocks an overwrite destroys', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    // The interactive block cannot survive a Markdown body, so the tool has to
    // say what it removed rather than silently dropping it.
    MotionBaseServer::actingAs($owner)
        ->tool(UpdateSection::class, ['section_id' => $section->id, 'markdown' => 'Nur Text.'])
        ->assertOk()
        ->assertSee('interactive');

    expect(collect($section->fresh()->content['blocks'])->pluck('type')->all())->toBe(['paragraph']);
});

it('adds interactive blocks but refuses unsafe urls', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    MotionBaseServer::actingAs($owner)
        ->tool(AddInteractiveBlock::class, [
            'section_id' => $section->id,
            'url' => 'javascript:alert(1)',
        ])->assertHasErrors();

    MotionBaseServer::actingAs($owner)
        ->tool(AddInteractiveBlock::class, [
            'section_id' => $section->id,
            'url' => '/interactive/7',
            'height' => 600,
            'position' => 0,
        ])->assertOk();

    $blocks = $section->fresh()->content['blocks'];

    expect($blocks[0]['type'])->toBe('interactive')
        ->and($blocks[0]['data']['height'])->toBe(600);
});

it('renders the consent screen instead of dying on an unbound view', function () {
    // Passport binds AuthorizationViewResponse only from inside
    // Passport::authorizationView(). Without that call every real client hits
    // "Target [AuthorizationViewResponse] is not instantiable" the moment it
    // starts the OAuth flow - while discovery and token auth still look fine.
    $client = app(Laravel\Passport\ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Consent Test', ['https://claude.ai/api/mcp/auth_callback'], false,
    );

    actingAs(User::factory()->create())
        ->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Consent Test');
});

it('round-trips markdown tables', function () {
    $markdown = <<<'MD'
    | Kurve | Wirkung | Einsatz |
    | --- | --- | --- |
    | **Ease-Out** | schneller Start | Menüs, Dialoge |
    | *Ease-In* | träger Start | alles was `verschwindet` |
    MD;

    $blocks = MarkdownBlocks::toBlocks($markdown);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('table')
        ->and($blocks[0]['data']['withHeadings'])->toBeTrue()
        ->and($blocks[0]['data']['content'][0])->toBe(['Kurve', 'Wirkung', 'Einsatz'])
        ->and($blocks[0]['data']['content'][1][0])->toBe('<b>Ease-Out</b>');

    expect(MarkdownBlocks::toMarkdown($blocks))->toBe($markdown);
});

it('gives a headless table a header row so it survives the round trip', function () {
    // Markdown has no table without a header, so one is invented rather than
    // letting the rows collapse into paragraphs on the way back in.
    $blocks = [['type' => 'table', 'data' => [
        'withHeadings' => false,
        'content' => [['a', 'b'], ['c', 'd']],
    ]]];

    $back = MarkdownBlocks::toBlocks(MarkdownBlocks::toMarkdown($blocks));

    expect($back[0]['type'])->toBe('table')
        ->and($back[0]['data']['content'])->toBe([['', ''], ['a', 'b'], ['c', 'd']]);
});

it('treats tables as editable rather than as a rich block', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    $section->update(['content' => ['blocks' => [
        ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['a'], ['b']]]],
    ]]]);

    // A table reported as rich would tell the model it cannot be edited, and
    // update_section would claim to have destroyed it.
    MotionBaseServer::actingAs($owner)
        ->tool(GetSection::class, ['section_id' => $section->id])
        ->assertOk()
        ->assertDontSee('rich_blocks":[{');

    MotionBaseServer::actingAs($owner)
        ->tool(UpdateSection::class, ['section_id' => $section->id, 'markdown' => "| x |\n| --- |\n| y |"])
        ->assertOk()
        ->assertSee('"dropped_rich_blocks":[]', false);
});

it('tells the client which files a graphic was uploaded with', function () {
    $owner = User::factory()->create();

    $graphic = Media::create([
        'filename' => 'g.html', 'original_filename' => 'wuerfel.html', 'path' => 'interactive/g.html',
        'url' => '/interactive/1/index.html', 'mime_type' => 'text/html', 'type' => 'interactive', 'size' => 10,
    ]);
    $graphic->assets()->create([
        'name' => 'model.glb', 'path' => 'interactive/g/x.glb', 'mime_type' => 'model/gltf-binary', 'size' => 10,
    ]);

    Media::create([
        'filename' => 'x.png', 'original_filename' => 'diagramm.png', 'path' => 'editor-images/x.png',
        'url' => '/storage/editor-images/x.png', 'mime_type' => 'image/png', 'type' => 'image', 'size' => 10,
    ]);

    // Without this a client writing a 3D graphic has no way to learn what the
    // author already uploaded, or that the url differs from /interactive/{id}.
    MotionBaseServer::actingAs($owner)->tool(ListMedia::class, ['type' => 'interactive'])
        ->assertOk()
        ->assertSee('"files":["model.glb"]');

    MotionBaseServer::actingAs($owner)->tool(ListMedia::class, ['type' => 'image'])
        ->assertOk()
        ->assertSee('diagramm.png')
        ->assertDontSee('"files"');
});

it('exposes the block vocabulary as a callable tool', function () {
    // The inventory also lives in the server instructions, but most clients
    // never surface those - a tool is the only discoverable form.
    MotionBaseServer::actingAs(User::factory()->create())
        ->tool(ListBlockTypes::class)
        ->assertOk()
        ->assertSee('table')
        ->assertSee('interactive')
        ->assertSee('quiz')
        ->assertSee('dropped_rich_blocks');
});

it('never advertises a block it cannot actually write', function () {
    $content = (new ListBlockTypes)->handle(new Laravel\Mcp\Request([]))->content()->toArray();
    $blocks = json_decode($content['text'] ?? '{}', true)['blocks'] ?? [];

    $registered = (new ReflectionClass(MotionBaseServer::class))->newInstanceWithoutConstructor();
    $property = (new ReflectionClass(MotionBaseServer::class))->getProperty('tools');
    $property->setAccessible(true);

    $toolNames = collect($property->getValue($registered))
        ->map(fn (string $class) => Str::snake(class_basename($class)))
        ->all();

    foreach ($blocks as $block) {
        if ($block['created_by'] === 'markdown') {
            // Must survive a round trip, or an update_section destroys it.
            expect(MarkdownBlocks::MARKDOWN_TYPES)->toContain($block['type']);
            expect($block['writable'])->toBeTrue();
        }

        if ($block['created_by'] === 'tool') {
            // A capability documented without an endpoint behind it is worse
            // than no capability: it sends a model looking for a tool that
            // does not exist.
            // The field may name more than one tool in a sentence, so check
            // every snake_case token it mentions.
            preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', $block['syntax'], $mentioned);

            expect($mentioned[0])->not->toBeEmpty();

            foreach ($mentioned[0] as $tool) {
                expect($toolNames)->toContain($tool);
            }

            expect($block['writable'])->toBeTrue();
        }

        if ($block['created_by'] === 'editor') {
            expect($block['writable'])->toBeFalse();
        }
    }

    // Every one of the eleven types is now writable through this server.
    expect(collect($blocks)->where('writable', true))->toHaveCount(count($blocks));
});

it('marks read-only tools so clients do not lump them in with writes', function () {
    $annotations = (new ListTopics)->toArray()['annotations'];

    expect($annotations)->toMatchArray(['readOnlyHint' => true])
        ->and((array) (new UpdateSection)->toArray()['annotations'])->toBe([]);
});

it('tells the client which icon to use', function () {
    // Declaring none leaves the client guessing at the domain, which is how a
    // leftover Laravel favicon kept representing the connector.
    // Read off the class attributes: the server itself cannot be constructed
    // without a transport, and the declaration is what we care about.
    $icons = collect((new ReflectionClass(MotionBaseServer::class))
        ->getAttributes(Laravel\Mcp\Server\Attributes\Icon::class))
        ->map(fn (ReflectionAttribute $a) => $a->newInstance());

    expect($icons)->not->toBeEmpty()
        ->and($icons->pluck('mimeType')->all())->toContain('image/svg+xml');

    // A renamed logo must fail here rather than silently serving a 404 to
    // every client that shows the connector.
    foreach ($icons as $icon) {
        expect(is_file(public_path($icon->src)))->toBeTrue("Icon fehlt: {$icon->src}");
    }
});

it('writes a quiz into a section', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    MotionBaseServer::actingAs($owner)
        ->tool(AddQuizBlock::class, [
            'section_id' => $section->id,
            'position' => 0,
            'questions' => [
                ['question' => 'Was verändert Easing?', 'answers' => [
                    ['text' => 'Die Verteilung der Bewegung', 'correct' => true],
                    ['text' => 'Die Dauer', 'correct' => false],
                ]],
            ],
        ])->assertOk();

    $block = $section->fresh()->content['blocks'][0];
    $question = $block['data']['questions'][0];

    expect($block['type'])->toBe('quiz')
        ->and($question['question'])->toBe('Was verändert Easing?')
        // The renderer keys on these ids and shuffles by them, so they have to
        // exist and be distinct even though the caller never supplies them.
        ->and($question['id'])->toBeString()->not->toBeEmpty()
        ->and(collect($question['answers'])->pluck('id')->unique())->toHaveCount(2)
        ->and(collect($question['answers'])->where('isCorrect', true))->toHaveCount(1);
});

it('refuses a quiz question that has no single right answer', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    $withCorrect = fn (array $flags) => [
        'section_id' => $section->id,
        'questions' => [['question' => 'Frage', 'answers' => array_map(
            fn (bool $c, int $i) => ['text' => 'Antwort '.$i, 'correct' => $c],
            $flags, array_keys($flags),
        )]],
    ];

    // The renderer resolves a pick with find(isCorrect): none means the
    // question can never be answered right, several means only the first
    // counts. Both look fine in the editor, so they have to fail here.
    MotionBaseServer::actingAs($owner)->tool(AddQuizBlock::class, $withCorrect([false, false]))->assertHasErrors();
    MotionBaseServer::actingAs($owner)->tool(AddQuizBlock::class, $withCorrect([true, true]))->assertHasErrors();

    expect($section->fresh()->content['blocks'])->toHaveCount(2);
});

it('hands back rich block data so an overwrite can be undone', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    MotionBaseServer::actingAs($owner)->tool(AddQuizBlock::class, [
        'section_id' => $section->id,
        'questions' => [['question' => 'Bleibt das lesbar?', 'answers' => [
            ['text' => 'Ja', 'correct' => true],
            ['text' => 'Nein', 'correct' => false],
        ]]],
    ])->assertOk();

    // Without the questions in the response, a model that overwrites the body
    // has no way to put the quiz back - it never saw what was in it.
    MotionBaseServer::actingAs($owner)
        ->tool(GetSection::class, ['section_id' => $section->id])
        ->assertOk()
        ->assertSee('Bleibt das lesbar?')
        ->assertSee('isCorrect');
});

it('places alerts and youtube videos', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    MotionBaseServer::actingAs($owner)->tool(AddAlertBlock::class, [
        'section_id' => $section->id,
        'type' => 'warning',
        'paragraphs' => ['Erster Absatz.', 'Zweiter Absatz.'],
    ])->assertOk();

    MotionBaseServer::actingAs($owner)->tool(AddYoutubeBlock::class, [
        'section_id' => $section->id,
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42',
    ])->assertOk();

    $blocks = collect($section->fresh()->content['blocks']);
    $alert = $blocks->firstWhere('type', 'alert')['data'];
    $video = $blocks->firstWhere('type', 'youtube')['data'];

    // Renderers read contentBlocks, the LTI fallback reads content: a block
    // carrying only one of them renders blank in the other half.
    expect($alert['type'])->toBe('warning')
        ->and($alert['contentBlocks']['blocks'])->toHaveCount(2)
        ->and($alert['content'])->toContain('Erster Absatz.')
        ->and($video['videoId'])->toBe('dQw4w9WgXcQ');
});

it('refuses a youtube url it cannot read an id from', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    // The renderers key on videoId and draw nothing without it, so a bad URL
    // has to fail loudly instead of leaving a blank figure in the page.
    MotionBaseServer::actingAs($owner)->tool(AddYoutubeBlock::class, [
        'section_id' => $section->id,
        'url' => 'https://vimeo.com/12345',
    ])->assertHasErrors();

    expect($section->fresh()->content['blocks'])->toHaveCount(2);
});

it('places media from the library and refuses the wrong type', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    $image = Media::create(['filename' => 'a.png', 'original_filename' => 'diagramm.png',
        'path' => 'editor-images/a.png', 'url' => '/storage/editor-images/a.png',
        'mime_type' => 'image/png', 'type' => 'image', 'size' => 10, 'alt' => 'Ein Diagramm']);

    MotionBaseServer::actingAs($owner)->tool(ListMedia::class, ['type' => 'image'])
        ->assertOk()->assertSee('diagramm.png');

    MotionBaseServer::actingAs($owner)->tool(AddImageBlock::class, [
        'section_id' => $section->id, 'media_id' => $image->id,
    ])->assertOk();

    // A lottie tool pointed at an image would write a block the player cannot
    // load, so the type is checked rather than trusted.
    MotionBaseServer::actingAs($owner)->tool(AddLottieBlock::class, [
        'section_id' => $section->id, 'media_id' => $image->id,
    ])->assertHasErrors();

    $block = collect($section->fresh()->content['blocks'])->firstWhere('type', 'image');

    expect($block['data']['url'])->toBe('/storage/editor-images/a.png')
        ->and($block['data']['caption'])->toBe('Ein Diagramm');
});

it('removes and reorders single blocks of any type', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    // Starts as [paragraph, interactive]. Removing the interactive one used to
    // be impossible without overwriting the whole body.
    MotionBaseServer::actingAs($owner)
        ->tool(RemoveBlock::class, ['section_id' => $section->id, 'index' => 1])
        ->assertOk()->assertSee('interactive');

    expect(collect($section->fresh()->content['blocks'])->pluck('type')->all())->toBe(['paragraph']);

    MotionBaseServer::actingAs($owner)->tool(AddAlertBlock::class, [
        'section_id' => $section->id, 'type' => 'info', 'paragraphs' => ['Hinweis'],
    ])->assertOk();

    MotionBaseServer::actingAs($owner)
        ->tool(MoveBlock::class, ['section_id' => $section->id, 'from' => 1, 'to' => 0])
        ->assertOk();

    expect(collect($section->fresh()->content['blocks'])->pluck('type')->all())->toBe(['alert', 'paragraph']);
});

it('refuses a block index that does not exist', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    // Indexes come from a get_section that may be stale; removing the wrong
    // block silently would be worse than refusing.
    MotionBaseServer::actingAs($owner)
        ->tool(RemoveBlock::class, ['section_id' => $section->id, 'index' => 99])
        ->assertHasErrors();

    expect($section->fresh()->content['blocks'])->toHaveCount(2);
});

it('deletes only what the confirmed title names', function () {
    $owner = User::factory()->create();
    [$topic, $chapter, $section] = course($owner);

    // An id is easy to get wrong; the title is not something you hold by
    // accident. A mismatch must leave everything standing.
    MotionBaseServer::actingAs($owner)->tool(DeleteContent::class, [
        'kind' => 'section', 'id' => $section->id, 'confirm_title' => 'Falscher Titel',
    ])->assertHasErrors();

    expect(Section::find($section->id))->not->toBeNull();

    MotionBaseServer::actingAs($owner)->tool(DeleteContent::class, [
        'kind' => 'section', 'id' => $section->id, 'confirm_title' => $section->title,
    ])->assertOk();

    expect(Section::find($section->id))->toBeNull()
        ->and(Chapter::find($chapter->id))->not->toBeNull()
        ->and(Topic::find($topic->id))->not->toBeNull();
});

it('writes a revision for every section a cascade would have swallowed', function () {
    $owner = User::factory()->create();
    [$topic, $chapter] = course($owner);
    Section::factory()->count(2)->create(['chapter_id' => $chapter->id]);

    $before = DB::table('revisions')->count();

    MotionBaseServer::actingAs($owner)->tool(DeleteContent::class, [
        'kind' => 'topic', 'id' => $topic->id, 'confirm_title' => $topic->title,
    ])->assertOk();

    expect(Topic::find($topic->id))->toBeNull()
        ->and(Chapter::where('topic_id', $topic->id)->count())->toBe(0)
        ->and(Section::where('chapter_id', $chapter->id)->count())->toBe(0);

    // The foreign keys cascade in the database, which bypasses Eloquent - the
    // sections would vanish without ever firing the event that records them.
    // Deleting child by child is what keeps that history.
    expect(DB::table('revisions')->count())->toBeGreaterThan($before + 2);
});

it('will not delete another user\'s course', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$topic] = course($owner);

    MotionBaseServer::actingAs($stranger)->tool(DeleteContent::class, [
        'kind' => 'topic', 'id' => $topic->id, 'confirm_title' => $topic->title,
    ])->assertHasErrors();

    expect(Topic::find($topic->id))->not->toBeNull();
});

it('fits every tool on one page of tools/list', function () {
    $server = (new ReflectionClass(MotionBaseServer::class))->newInstanceWithoutConstructor();

    $tools = (new ReflectionClass(MotionBaseServer::class))->getProperty('tools');
    $tools->setAccessible(true);

    // The package paginates at 15 by default. Anything past that sits behind a
    // nextCursor and is invisible to a client that does not follow it - and it
    // is always the newest tools that end up on page two.
    expect(count($tools->getValue($server)))->toBeLessThanOrEqual($server->defaultPaginationLength);
});

it('carries per-answer explanations through to the block', function () {
    $owner = User::factory()->create();
    [, , $section] = course($owner);

    MotionBaseServer::actingAs($owner)->tool(AddQuizBlock::class, [
        'section_id' => $section->id,
        'questions' => [['question' => 'Welche Kurve für ein Menü?', 'answers' => [
            ['text' => 'Ease-Out', 'correct' => true, 'explanation' => 'Reagiert sofort auf den Klick.'],
            ['text' => 'Ease-In', 'correct' => false, 'explanation' => 'Der träge Start wirkt wie Verzögerung.'],
            ['text' => 'Linear', 'correct' => false],
        ]]],
    ])->assertOk();

    $answers = collect($section->fresh()->content['blocks'])
        ->firstWhere('type', 'quiz')['data']['questions'][0]['answers'];

    expect($answers[0]['explanation'])->toBe('Reagiert sofort auf den Klick.')
        ->and($answers[1]['explanation'])->toBe('Der träge Start wirkt wie Verzögerung.')
        // Omitted rather than stored empty, so the renderers can test for
        // presence instead of for a blank string.
        ->and($answers[2])->not->toHaveKey('explanation');
});
