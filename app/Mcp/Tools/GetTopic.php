<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesOwnedContent;
use App\Models\Chapter;
use App\Models\Section;
use App\Mcp\Annotations\IsReadOnly;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[IsReadOnly]
#[Description('Get the full outline of one course: its chapters and, per chapter, the sections with their ids, publish state and order. Use get_section to read a section\'s actual content.')]
class GetTopic extends Tool
{
    use ResolvesOwnedContent;

    public function handle(Request $request): Response
    {
        ['topic_id' => $topicId] = $request->validate([
            'topic_id' => ['required', 'integer'],
        ]);

        $topic = $this->findTopic($request->user(), $topicId);

        if (! $topic) {
            return Response::error("No course with id {$topicId} owned by you.");
        }

        $topic->load(['chapters.sections' => fn ($query) => $query->orderBy('sort_order')]);

        return Response::json([
            'id' => $topic->id,
            'title' => $topic->title,
            'slug' => $topic->slug,
            'published' => $topic->is_published,
            'url' => url('/themen/'.$topic->slug),
            'chapters' => $topic->chapters->map(fn (Chapter $chapter) => [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'is_published' => $chapter->is_published,
                'sort_order' => $chapter->sort_order,
                'sections' => $chapter->sections->map(fn (Section $section) => [
                    'id' => $section->id,
                    'title' => $section->title,
                    'is_published' => $section->is_published,
                    'sort_order' => $section->sort_order,
                    'blocks' => count($section->content['blocks'] ?? []),
                ]),
            ]),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_id' => $schema->integer()
                ->description('Id of the course, as returned by list_topics.')
                ->required(),
        ];
    }
}
