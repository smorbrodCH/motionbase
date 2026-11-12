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

it('hides a whole unpublished course from the overview, its pages, embeds and chat', function () {
    $visible = Topic::factory()->create(['title' => 'Sichtbarer Kurs']);
    Section::factory()->for(Chapter::factory()->for($visible))->create();

    $topic = Topic::factory()->unpublished()->create(['title' => 'Versteckter Kurs']);
    $chapter = Chapter::factory()->for($topic)->create();
    $section = Section::factory()->for($chapter)->create();

    get('/themen')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('topics', 1)
            ->where('topics.0.title', 'Sichtbarer Kurs'));

    get("/themen/{$topic->slug}")->assertNotFound();
    get("/themen/{$topic->slug}/{$chapter->slug}/{$section->slug}")->assertNotFound();
    get("/embed/topic/{$topic->id}")->assertNotFound();
    get("/embed/chapter/{$chapter->id}")->assertNotFound();
    get("/embed/chapter/{$chapter->id}/section/{$section->id}")->assertNotFound();
    get("/embed/section/{$section->id}")->assertNotFound();
    Pest\Laravel\postJson("/themen/{$topic->slug}/chat", ['question' => 'Hallo?'])->assertNotFound();
});

it('lets the owner hide and show a whole course', function () {
    $topic = Topic::factory()->create();

    expect($topic->fresh()->is_published)->toBeTrue();

    Pest\Laravel\actingAs($topic->user)
        ->put("/admin/topics/{$topic->id}", ['is_published' => false])
        ->assertRedirect();

    expect($topic->fresh()->is_published)->toBeFalse();

    Pest\Laravel\actingAs($topic->user)
        ->get('/admin/topics')
        ->assertInertia(fn ($page) => $page->where('topics.0.is_published', false));
});
