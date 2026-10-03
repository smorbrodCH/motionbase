<?php

use App\Models\Chapter;
use App\Models\Section;
use App\Models\Topic;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('persists autosaved section content', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();
    $section = Section::factory()->for($chapter)->create();

    $content = [
        'time' => 1_700_000_000_000,
        'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Neuer Absatz']]],
        'version' => '2.31.0',
    ];

    actingAs($user)
        ->patch("/admin/sections/{$section->id}", [
            'title' => 'Überarbeitet',
            'content' => $content,
        ])
        ->assertRedirect();

    $section->refresh();

    expect($section->title)->toBe('Überarbeitet')
        ->and($section->content['blocks'][0]['data']['text'])->toBe('Neuer Absatz');
});

it('collapses a burst of autosaves into one restore point', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();
    $section = Section::factory()->for($chapter)->create();

    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'V2']);
    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'V3']);
    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'V4']);

    // One revision holding the state before the writing session started.
    expect($section->revisions()->count())->toBe(1)
        ->and($section->revisions()->first()->content['title'])->toBe($section->title);
});

it('starts a new restore point once the coalescing window has passed', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();
    $section = Section::factory()->for($chapter)->create();

    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'Vormittag']);

    $this->travel(10)->minutes();

    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'Nachmittag']);

    expect($section->revisions()->count())->toBe(2);
});

it('records every save when coalescing is disabled', function () {
    config()->set('revisions.coalesce_minutes', 0);

    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();
    $section = Section::factory()->for($chapter)->create();

    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'V2']);
    actingAs($user)->patch("/admin/sections/{$section->id}", ['title' => 'V3']);

    expect($section->revisions()->count())->toBe(2);
});

it('reorders sections atomically', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();

    $a = Section::factory()->for($chapter)->create(['sort_order' => 0]);
    $b = Section::factory()->for($chapter)->create(['sort_order' => 1]);
    $c = Section::factory()->for($chapter)->create(['sort_order' => 2]);

    actingAs($user)
        ->post("/admin/chapters/{$chapter->id}/sections/reorder", [
            'order' => [$c->id, $a->id, $b->id],
        ])
        ->assertRedirect();

    expect($chapter->sections()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('ignores ids from a foreign chapter when reordering', function () {
    $user = User::factory()->create();
    $topic = Topic::factory()->for($user)->create();
    $chapter = Chapter::factory()->for($topic)->create();
    $foreignChapter = Chapter::factory()->for($topic)->create();

    $own = Section::factory()->for($chapter)->create(['sort_order' => 0]);
    $foreign = Section::factory()->for($foreignChapter)->create(['sort_order' => 7]);

    actingAs($user)->post("/admin/chapters/{$chapter->id}/sections/reorder", [
        'order' => [$foreign->id, $own->id],
    ]);

    expect($foreign->fresh()->sort_order)->toBe(7)
        ->and($own->fresh()->sort_order)->toBe(1);
});

it('keeps a topic from losing its last section', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();
    $section = Section::factory()->for($chapter)->create();

    actingAs($user)->delete("/admin/sections/{$section->id}")->assertRedirect();

    expect(Section::whereKey($section->id)->exists())->toBeTrue();
});

it('tells the editor whether a page is theory or a task', function () {
    $user = User::factory()->create();
    $chapter = Chapter::factory()->for(Topic::factory()->for($user))->create();
    $task = Section::factory()->for($chapter)->create(['task_submission' => 'file']);

    // Ohne dieses Feld zeigt der Editor jede Seite als Theorie, auch eine Aufgabe.
    actingAs($user)->get(route('topics.edit.section', ['topic' => $chapter->topic, 'section' => $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeSection.task_submission', 'file')
            ->where('topic.chapters.0.sections.0.task_submission', 'file')
        );
});
