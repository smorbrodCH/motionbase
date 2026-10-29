<?php

namespace App\Console\Commands;

use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Lässt die Lektionen auf die Seiten zeigen, die auf eifach.online entstanden
 * sind. Nimmt mapping.json aus `motionbase:interactives-export`, nachdem die
 * Seiten dort wirklich angelegt wurden.
 */
class RelinkInteractives extends Command
{
    protected $signature = 'motionbase:interactives-relink
        {mapping : Pfad zu mapping.json, relativ zu storage/app}
        {--base=https://bzz.eifach.online : Adresse der Organisation}
        {--dry-run : Nur zeigen, was sich ändern würde}';

    protected $description = 'Interaktive Blöcke auf die Seiten bei eifach.online umhängen';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $path = $this->argument('mapping');

        if (! $disk->exists($path)) {
            $this->error("mapping.json nicht gefunden: {$disk->path($path)}");

            return self::FAILURE;
        }

        $base = rtrim($this->option('base'), '/');
        $urls = [];

        foreach (json_decode($disk->get($path), true) ?? [] as $entry) {
            $urls[(int) $entry['media_id']] = "{$base}/{$entry['slug']}/";
        }

        if (! $urls) {
            $this->error('Das Mapping ist leer.');

            return self::FAILURE;
        }

        $changed = 0;
        $blocks = 0;

        foreach (Section::all() as $section) {
            $content = $section->content;
            $touched = false;

            foreach ($content['blocks'] ?? [] as $index => $block) {
                if (($block['type'] ?? null) !== 'interactive') {
                    continue;
                }

                if (! preg_match('#^/interactive/(\d+)#', $block['data']['url'] ?? '', $match)) {
                    continue;
                }

                $url = $urls[(int) $match[1]] ?? null;

                if (! $url) {
                    $this->warn("Lektion {$section->id}: Grafik {$match[1]} steht nicht im Mapping, bleibt.");

                    continue;
                }

                $this->line("Lektion {$section->id}: {$block['data']['url']} → {$url}");
                $content['blocks'][$index]['data']['url'] = $url;
                $touched = true;
                $blocks++;
            }

            if ($touched && ! $this->option('dry-run')) {
                // Ohne Revision: der Umzug ist keine inhaltliche Änderung der Autorin
                $section->forceFill(['content' => $content])->saveQuietly();
                $changed++;
            }
        }

        $this->info($this->option('dry-run')
            ? "{$blocks} Blöcke würden umgehängt."
            : "{$blocks} Blöcke in {$changed} Lektionen umgehängt.");

        return self::SUCCESS;
    }
}
