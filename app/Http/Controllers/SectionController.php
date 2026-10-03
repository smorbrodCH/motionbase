<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectionController extends Controller
{
    /**
     * Das Gerüst einer Aufgabe: Auftrag, Anforderungen, Vorgehen. So steht in
     * Moodle von Anfang an das, was eine Aufgabe braucht.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function taskTemplate(): array
    {
        return [
            ['type' => 'paragraph', 'data' => ['text' => 'Worum geht es? Beschreibe den Auftrag in zwei, drei Sätzen.']],
            ['type' => 'header', 'data' => ['text' => 'Anforderungen', 'level' => 2]],
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                ['content' => 'Woran erkennt man, dass die Aufgabe gelöst ist?', 'meta' => [], 'items' => []],
            ]]],
            ['type' => 'header', 'data' => ['text' => 'Vorgehen', 'level' => 2]],
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => [
                ['content' => 'Erster Schritt', 'meta' => [], 'items' => []],
            ]]],
        ];
    }

    public function store(Request $request, Chapter $chapter): RedirectResponse
    {
        $this->authorize('update', $chapter->topic);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            // Theorie oder Aufgabe: bestimmt, was Moodle daraus macht
            'kind' => ['nullable', 'in:page,task'],
        ]);

        $isTask = ($validated['kind'] ?? 'page') === 'task';
        $maxSortOrder = $chapter->sections()->max('sort_order') ?? -1;

        $section = $chapter->sections()->create([
            'title' => $validated['title'],
            'slug' => $chapter->sections()->make()->uniqueSlug($validated['slug'] ?? $validated['title']),
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => $isTask ? self::taskTemplate() : [
                    [
                        'type' => 'paragraph',
                        'data' => ['text' => 'Beginne hier mit deinem Inhalt...'],
                    ],
                ],
                'version' => '2.31.0',
            ],
            // Ohne Abgabe, bis die Autorin eine wählt - in Moodle eine Aufgabe,
            // bei der im Unterricht abgegeben wird.
            'task_submission' => $isTask ? 'none' : null,
            'sort_order' => $maxSortOrder + 1,
        ]);

        return redirect()
            ->route('topics.edit', ['topic' => $chapter->topic, 'section' => $section->id])
            ->with('flash', ['status' => 'success', 'message' => $isTask ? 'Aufgabe erstellt.' : 'Seite erstellt.']);
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $this->authorize('update', $section->chapter->topic);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'array'],
            'is_published' => ['sometimes', 'boolean'],
            'task_submission' => ['sometimes', 'nullable', 'in:'.implode(',', Section::TASK_SUBMISSIONS)],
        ]);

        if (isset($validated['slug'])) {
            $validated['slug'] = $section->uniqueSlug($validated['slug']);
        }

        $section->update($validated);

        return redirect()
            ->route('topics.edit.section', ['topic' => $section->chapter->topic_id, 'section' => $section->id])
            ->with('flash', ['status' => 'success', 'message' => 'Abschnitt gespeichert.']);
    }

    public function destroy(Section $section): RedirectResponse
    {
        $this->authorize('update', $section->chapter->topic);

        $chapter = $section->chapter;
        $topic = $chapter->topic;

        // Prevent deleting the last section of the last chapter
        $totalSections = $topic->sections()->count();
        if ($totalSections <= 1) {
            return redirect()
                ->back()
                ->with('flash', ['status' => 'error', 'message' => 'Ein Thema muss mindestens einen Abschnitt haben.']);
        }

        $section->delete();

        // Find next section to redirect to
        $nextSection = $chapter->sections()->orderBy('sort_order')->first();

        if (! $nextSection) {
            // Chapter is empty, get first section from any other chapter
            $nextSection = $topic->sections()->orderBy('sort_order')->first();
        }

        return redirect()
            ->route('topics.edit', ['topic' => $topic, 'section' => $nextSection?->id])
            ->with('flash', ['status' => 'success', 'message' => 'Abschnitt gelöscht.']);
    }

    public function reorder(Request $request, Chapter $chapter): RedirectResponse
    {
        $this->authorize('update', $chapter->topic);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:sections,id'],
        ]);

        DB::transaction(function () use ($chapter, $validated): void {
            foreach ($validated['order'] as $index => $sectionId) {
                $chapter->sections()
                    ->whereKey($sectionId)
                    ->update(['sort_order' => $index]);
            }
        });

        return redirect()
            ->back()
            ->with('flash', ['status' => 'success', 'message' => 'Reihenfolge gespeichert.']);
    }
}
