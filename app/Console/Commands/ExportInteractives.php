<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Packt die interaktiven Grafiken, die in Lektionen stecken, für den Umzug
 * nach eifach.online: je eine HTML-Datei mit dem Design der Schule im Kopf,
 * ihre Zusatzdateien, dazu auftrag.json für scripts/import-pages.ts und
 * mapping.json für `motionbase:interactives-relink`.
 *
 * Neue Grafiken entstehen auf eifach.online; dieser Befehl ist für die, die es
 * schon gibt.
 */
class ExportInteractives extends Command
{
    protected $signature = 'motionbase:interactives-export
        {--dir=interactives-export : Zielordner (unter storage/app)}
        {--organization=bzz : Organisation auf eifach.online}
        {--owner= : E-Mail der Person, der die Seiten gehören}
        {--visibility=unlisted : public, unlisted oder private}';

    protected $description = 'Interaktive Grafiken für den Umzug nach eifach.online auspacken';

    /** Das Design der Schule ist verbindlich; ohne diesen Link nimmt eifach.online die Seite nicht an. */
    private const DESIGN_LINK = '<link rel="stylesheet" href="/_eifach/design/design.css">';

    public function handle(): int
    {
        $owner = $this->option('owner');

        if (! $owner) {
            $this->error('--owner fehlt: die E-Mail der Person, der die Seiten auf eifach.online gehören.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $dir = trim($this->option('dir'), '/');
        $disk->deleteDirectory($dir);

        $pages = [];
        $mapping = [];
        $slugs = [];

        foreach ($this->usedGraphics() as $id => $sections) {
            $media = Media::with('assets')->find($id);

            if (! $media || $media->type !== 'interactive' || ! $disk->exists($media->path)) {
                $this->warn("Grafik {$id} fehlt auf der Platte, übersprungen (in ".count($sections).' Lektionen).');

                continue;
            }

            $slug = $this->slug($media, $slugs);
            $slugs[] = $slug;

            $disk->put("{$dir}/html/{$slug}.html", $this->withDesign($disk->get($media->path)));

            $files = [];
            foreach ($media->assets as $asset) {
                if (! $disk->exists($asset->path)) {
                    $this->warn("Datei {$asset->name} von Grafik {$id} fehlt, übersprungen.");

                    continue;
                }

                $disk->put("{$dir}/dateien/{$slug}/{$asset->name}", $disk->get($asset->path));
                $files[] = ['path' => $asset->name, 'file' => "dateien/{$slug}/{$asset->name}"];
            }

            $pages[] = array_filter([
                'slug' => $slug,
                'title' => $this->title($media),
                'file' => "html/{$slug}.html",
                'files' => $files ?: null,
            ]);

            $mapping[] = [
                'media_id' => $media->id,
                'slug' => $slug,
                'title' => $this->title($media),
                'sections' => $sections,
            ];
        }

        $disk->put("{$dir}/auftrag.json", json_encode([
            'organization' => $this->option('organization'),
            'owner' => $owner,
            'visibility' => $this->option('visibility'),
            'pages' => $pages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $disk->put("{$dir}/mapping.json", json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info(count($pages).' Grafiken ausgepackt nach '.$disk->path($dir));
        $this->line('Weiter auf dem eifach.online-Server mit scripts/import-pages.ts, danach motionbase:interactives-relink.');

        return self::SUCCESS;
    }

    /**
     * Grafiken, die wirklich in einer Lektion stecken, mit den Lektionen dazu.
     *
     * @return array<int, int[]> media id => section ids
     */
    private function usedGraphics(): array
    {
        $used = [];

        foreach (Section::all() as $section) {
            foreach ($section->content['blocks'] ?? [] as $block) {
                if (($block['type'] ?? null) !== 'interactive') {
                    continue;
                }

                if (preg_match('#^/interactive/(\d+)#', $block['data']['url'] ?? '', $match)) {
                    $used[(int) $match[1]][] = $section->id;
                }
            }
        }

        return $used;
    }

    /** Der Dateiname der Grafik wird zur Adresse: fps-simulator.html → fps-simulator */
    private function slug(Media $media, array $taken): string
    {
        $base = Str::slug(Str::of($media->original_filename)->beforeLast('.')->toString(), '-', 'de')
            ?: 'grafik-'.$media->id;
        $slug = $base;

        for ($n = 2; in_array($slug, $taken, true); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function title(Media $media): string
    {
        return Str::of($media->original_filename)
            ->beforeLast('.')
            ->replace(['-', '_'], ' ')
            ->trim()
            ->ucfirst()
            ->toString() ?: "Grafik {$media->id}";
    }

    /**
     * Das Design der Schule in den Kopf, falls es nicht schon drinsteht. Der
     * Grundstil von design.css steht in :where(), überschreibt also nichts,
     * was die Grafik selbst setzt.
     */
    private function withDesign(string $html): string
    {
        if (str_contains($html, '/_eifach/design/design.css')) {
            return $html;
        }

        if (preg_match('/<head[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at)."\n  ".self::DESIGN_LINK.substr($html, $at);
        }

        return self::DESIGN_LINK."\n".$html;
    }
}
