<?php

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

/**
 * Eine Grafik, wie sie vor dem Umzug nach eifach.online angelegt wurde. Neue
 * entstehen dort; diese hier werden weiter ausgeliefert.
 */
function storedGraphic(string $body = '<!DOCTYPE html><p>hi</p>'): Media
{
    $filename = Str::uuid().'.html';
    Storage::disk('local')->put("interactive/{$filename}", $body);

    return Media::create([
        'filename' => $filename,
        'original_filename' => 'graphic.html',
        'path' => "interactive/{$filename}",
        'url' => '',
        'mime_type' => 'text/html',
        'type' => 'interactive',
        'size' => strlen($body),
    ]);
}

it('keeps graphics off the web served public disk', function () {
    $media = storedGraphic();

    // The public disk is served directly by the web server, which would
    // hand out the graphic on our own origin without any CSP.
    expect(Storage::disk('local')->exists($media->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($media->path))->toBeFalse();
});

it('serves graphics on an opaque origin', function () {
    $media = storedGraphic();

    $response = get("/interactive/{$media->id}");

    $response->assertOk();

    // Without allow-same-origin the document cannot reach the app's cookies,
    // localStorage or same-origin requests - not even on direct navigation.
    expect($response->headers->get('Content-Security-Policy'))->toBe('sandbox allow-scripts');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('revalidates instead of caching blind, so a replaced graphic shows up', function () {
    $media = storedGraphic();

    $first = get("/interactive/{$media->id}")->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($first->headers->get('Cache-Control'))->toContain('must-revalidate')
        ->and($first->headers->get('Cache-Control'))->toContain('max-age=0');

    // Unchanged: cheap 304 instead of shipping the file again.
    get("/interactive/{$media->id}", ['If-None-Match' => $etag])->assertStatus(304);

    // Replaced in place: the author must see it on the next load, not in an hour.
    Storage::disk('local')->put($media->path, '<!DOCTYPE html><p>v2</p>');

    $second = get("/interactive/{$media->id}", ['If-None-Match' => $etag]);
    $second->assertOk();
    expect($second->headers->get('ETag'))->not->toBe($etag);
});

it('serves graphics to guests so embeds keep working', function () {
    $media = storedGraphic();

    get("/interactive/{$media->id}")->assertOk();
});

it('does not serve media of other types through the interactive route', function () {
    $media = Media::create([
        'filename' => 'x.png',
        'original_filename' => 'x.png',
        'path' => 'editor-images/x.png',
        'url' => '/storage/editor-images/x.png',
        'mime_type' => 'image/png',
        'type' => 'image',
        'size' => 10,
    ]);

    get("/interactive/{$media->id}")->assertNotFound();
});

it('renders a sandboxed iframe in embed and lti views', function () {
    $html = view('lti.partials.content-blocks', ['blocks' => [
        ['type' => 'interactive', 'data' => [
            'url' => '/interactive/7',
            'caption' => 'Easing Simulator',
            'height' => 620,
        ]],
    ]])->render();

    expect($html)
        ->toContain('src="/interactive/7"')
        ->toContain('sandbox="allow-scripts"')
        ->toContain('height: 620px')
        ->toContain('class="interactive-frame')
        ->toContain('Easing Simulator')
        // allow-same-origin would defeat the whole point of the sandbox
        ->not->toContain('allow-same-origin');
});

it('clamps absurd heights and drops non-http urls', function () {
    $render = fn (array $data) => view('lti.partials.content-blocks', [
        'blocks' => [['type' => 'interactive', 'data' => $data]],
    ])->render();

    expect($render(['url' => '/interactive/7', 'height' => 99999]))->toContain('height: 5000px');
    expect($render(['url' => '/interactive/7', 'height' => 0]))->toContain('height: 480px');

    foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', ''] as $url) {
        expect($render(['url' => $url]))->not->toContain('<iframe');
    }
});

it('renders tables in embed and lti views', function () {
    $html = view('lti.partials.content-blocks', ['blocks' => [
        ['type' => 'table', 'data' => [
            'withHeadings' => true,
            'content' => [['Kurve', 'Einsatz'], ['Ease-Out', 'Menüs']],
        ]],
    ]])->render();

    expect($html)
        ->toContain('<th scope="col"')
        ->toContain('Kurve')
        ->toContain('<td class="px-4 py-2.5 align-top text-zinc-600">Menüs</td>')
        ->toContain('overflow-x-auto');
});

it('renders a headless table without inventing a header row', function () {
    $html = view('lti.partials.content-blocks', ['blocks' => [
        ['type' => 'table', 'data' => ['withHeadings' => false, 'content' => [['a', 'b']]]],
    ]])->render();

    expect($html)->not->toContain('<thead>')->toContain('<td');
});
