<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InteractiveController extends Controller
{
    /**
     * Upload a self-contained interactive HTML graphic.
     *
     * The file is stored on the private disk - never on the web served public
     * disk - because it is attacker controlled markup as far as the browser is
     * concerned. It is handed out again only through show(), which pins it to
     * an opaque origin. Uploads are admin-only.
     */
    public function show(Request $request, Media $media): Response|RedirectResponse
    {
        abort_unless($media->type === 'interactive', 404);

        // A graphic with files finds them only from one level down. Reached at
        // the short address - a block placed by hand or through the MCP server
        // with the id pieced together - its model would 404 without a trace.
        if ($media->assets()->exists()) {
            return redirect()->route('interactive.file', ['media' => $media, 'file' => 'index.html']);
        }

        return $this->document($request, $media);
    }

    private function document(Request $request, Media $media): Response
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($media->path), 404);

        $contents = $disk->get($media->path);

        $response = response($contents, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'sandbox allow-scripts',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Revalidate rather than cache blind: a graphic replaced in place has to
        // show up on the next reload, or authors debug against a stale copy for
        // an hour. The ETag keeps repeat views at a cheap 304.
        $response->setEtag(md5($contents));
        $response->setPublic();
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setMaxAge(0);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Anything below a graphic: the document itself as index.html, or a file
     * that was uploaded with it.
     */
    public function file(Request $request, Media $media, string $file): Response|BinaryFileResponse
    {
        abort_unless($media->type === 'interactive', 404);

        return $file === 'index.html'
            ? $this->document($request, $media)
            : $this->asset($request, $media, $file);
    }

    /**
     * Serve a file uploaded together with a graphic.
     *
     * The graphic runs on an opaque origin, so every request it makes back to
     * us is cross-origin as far as the browser is concerned - hence
     * Access-Control-Allow-Origin. The other way out, allow-same-origin on the
     * frame, would hand uploaded markup the app's cookies. The wildcard is safe
     * because these files are as public as the graphic and no credentials
     * travel with them.
     *
     * Opened directly, the file must never act like a page on our origin: its
     * type is the one fixed from its contents at upload, sniffing is off, and a
     * sandbox without allow-scripts stops anything from running.
     */
    private function asset(Request $request, Media $media, string $name): BinaryFileResponse
    {
        abort_unless($media->type === 'interactive', 404);

        $asset = $media->assets()->where('name', $name)->first();
        $disk = Storage::disk('local');

        abort_unless($asset && $disk->exists($asset->path), 404);

        $response = response()->file($disk->path($asset->path), [
            'Content-Type' => $asset->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);

        // Same revalidation as the document: a model replaced while an author
        // iterates has to show up on the next load.
        $response->setAutoLastModified();
        $response->setPublic();
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setMaxAge(0);
        $response->isNotModified($request);

        return $response;
    }
}
