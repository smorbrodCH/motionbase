<?php

use App\Models\Chapter;
use App\Models\Section;
use App\Models\Topic;

use function Pest\Laravel\get;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('hides unpublished chapters and sections from the public view', function () {
    $topic = Topic::factory()->create();
    $published = Chapter::factory()->for($topic)->create(['sort_order' => 0]);
    Section::factory()->for($published)->create(['title' => 'Sichtbar']);

    $hidden = Chapter::factory()->for($topic)->unpublished()->create(['sort_order' => 1]);
    Section::factory()->for($hidden)->create(['title' => 'Versteckt']);

    get("/themen/{$topic->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/topics/show')
            ->has('topic.chapters', 1)
            ->where('topic.chapters.0.sections.0.title', 'Sichtbar'));
});

it('404s on an unpublished chapter url', function () {
    $topic = Topic::factory()->create();
    $chapter = Chapter::factory()->for($topic)->unpublished()->create();

    get("/themen/{$topic->slug}/{$chapter->slug}")->assertNotFound();
});

it('serves a topic embed once it has published content', function () {
    $topic = Topic::factory()->create();
    $chapter = Chapter::factory()->for($topic)->create();
    Section::factory()->for($chapter)->create(['title' => 'Einstieg']);

    get("/embed/topic/{$topic->id}")
        ->assertOk()
        ->assertSee('Einstieg', false);
});

it('404s the topic embed while nothing is published', function () {
    $topic = Topic::factory()->create();
    $chapter = Chapter::factory()->for($topic)->unpublished()->create();
    Section::factory()->for($chapter)->create();

    get("/embed/topic/{$topic->id}")->assertNotFound();
});

it('404s the topic embed for a chapter that has no published sections', function () {
    $topic = Topic::factory()->create();
    Chapter::factory()->for($topic)->create();

    get("/embed/topic/{$topic->id}")->assertNotFound();
});

function courseWithLesson(string $status, string $title): array
{
    $topic = Topic::factory()->status($status)->create(['title' => $title]);
    $chapter = Chapter::factory()->for($topic)->create();
    $section = Section::factory()->for($chapter)->create();

    return [$topic, $chapter, $section];
}

it('lists published courses and courses being revised, nothing else', function () {
    courseWithLesson(Topic::PUBLISHED, 'Offen');
    courseWithLesson(Topic::REVISION, 'Gesperrt');
    courseWithLesson(Topic::DRAFT, 'Entwurf');
    courseWithLesson(Topic::PRIVATE, 'Privat');
    courseWithLesson(Topic::ARCHIVED, 'Alt');

    get('/themen')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('topics', 2)
            ->where('topics', fn ($topics) => collect($topics)->pluck('in_revision', 'title')->sortKeys()->all()
                === ['Gesperrt' => true, 'Offen' => false]));
});

it('keeps a draft out of reach everywhere', function () {
    [$topic, $chapter, $section] = courseWithLesson(Topic::DRAFT, 'Entwurf');

    get("/themen/{$topic->slug}")->assertNotFound();
    get("/themen/{$topic->slug}/{$chapter->slug}/{$section->slug}")->assertNotFound();
    get("/embed/topic/{$topic->id}")->assertNotFound();
    get("/embed/chapter/{$chapter->id}")->assertNotFound();
    get("/embed/chapter/{$chapter->id}/section/{$section->id}")->assertNotFound();
    get("/embed/section/{$section->id}")->assertNotFound();
    Pest\Laravel\postJson("/themen/{$topic->slug}/chat", ['question' => 'Hallo?'])->assertNotFound();
});

it('shows a course being revised as locked, with a note instead of the content', function () {
    [$topic, $chapter, $section] = courseWithLesson(Topic::REVISION, 'Gesperrt');

    get("/themen/{$topic->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/topics/revision')
            ->where('topic.title', 'Gesperrt')
            ->missing('topic.chapters'));
    get("/themen/{$topic->slug}/{$chapter->slug}/{$section->slug}")
        ->assertInertia(fn ($page) => $page->component('public/topics/revision'));

    foreach (["/embed/topic/{$topic->id}", "/embed/chapter/{$chapter->id}", "/embed/chapter/{$chapter->id}/section/{$section->id}", "/embed/section/{$section->id}"] as $url) {
        get($url)->assertOk()->assertSee('In Überarbeitung')->assertDontSee($section->title);
    }

    Pest\Laravel\postJson("/themen/{$topic->slug}/chat", ['question' => 'Hallo?'])->assertNotFound();
});

it('keeps private and archived courses readable by their link', function () {
    [$private] = courseWithLesson(Topic::PRIVATE, 'Privat');
    [$archived, $chapter, $section] = courseWithLesson(Topic::ARCHIVED, 'Alt');

    get("/themen/{$private->slug}")
        ->assertInertia(fn ($page) => $page->component('public/topics/show')->where('topic.archived', false));
    get("/themen/{$archived->slug}")
        ->assertInertia(fn ($page) => $page->component('public/topics/show')->where('topic.archived', true));
    get("/embed/section/{$section->id}")->assertOk()->assertSee($section->title);
});

it('lets the owner set the status, and starts new courses as drafts', function () {
    $topic = Topic::factory()->create();

    expect($topic->fresh()->status)->toBe(Topic::PUBLISHED);

    Pest\Laravel\actingAs($topic->user)
        ->put("/admin/topics/{$topic->id}", ['status' => Topic::REVISION])
        ->assertRedirect();

    expect($topic->fresh()->status)->toBe(Topic::REVISION);

    Pest\Laravel\actingAs($topic->user)
        ->put("/admin/topics/{$topic->id}", ['status' => 'irgendwas'])
        ->assertSessionHasErrors('status');

    Pest\Laravel\actingAs($topic->user)
        ->post('/admin/topics', ['title' => 'Neu', 'category_id' => $topic->category_id]);

    expect(Topic::where('title', 'Neu')->value('status'))->toBe(Topic::DRAFT);
});
