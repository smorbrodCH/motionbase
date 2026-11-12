<?php

namespace App\Mcp\Tools;

use App\Models\Topic;
use App\Mcp\Annotations\IsReadOnly;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[IsReadOnly]
#[Description('List all courses (topics) owned by the authenticated user, with their chapter and section counts.')]
class ListTopics extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Not authenticated.');
        }

        $topics = Topic::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->with('category:id,name')
            ->withCount(['chapters', 'sections'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Topic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'slug' => $topic->slug,
                'status' => $topic->status,
                'category' => $topic->category?->name,
                'chapters' => $topic->chapters_count,
                'sections' => $topic->sections_count,
                'url' => url('/themen/'.$topic->slug),
            ]);

        return Response::json(['topics' => $topics]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
