<?php

namespace App\Mcp\Tools;

use App\Mcp\Annotations\IsReadOnly;
use App\Mcp\Support\MarkdownBlocks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[IsReadOnly]
#[Description('List every block type a section can contain, how each one is created, and whether this server can write it. Call this before writing a section if you are unsure what is expressible.')]
class ListBlockTypes extends Tool
{
    /**
     * How each block is authored. `markdown` types round-trip through
     * create_section and update_section; `tool` types have their own; the rest
     * are readable but can only be authored in the web editor.
     */
    private const BLOCKS = [
        ['type' => 'header', 'created_by' => 'markdown', 'syntax' => "## Ebene 2\n### Ebene 3\n#### Ebene 4",
            'notes' => 'Only levels 2-4 exist. Never repeat the section title as a heading; it is already rendered above the body. Subheadings fill the page table of contents.'],
        ['type' => 'paragraph', 'created_by' => 'markdown', 'syntax' => 'Plain text with **bold**, *italic*, `code` and [links](https://example.com).',
            'notes' => 'Blank line separates paragraphs.'],
        ['type' => 'list', 'created_by' => 'markdown', 'syntax' => "- unordered\n- second\n\n1. ordered\n2. second",
            'notes' => 'Nesting is flattened to one level.'],
        ['type' => 'code', 'created_by' => 'markdown', 'syntax' => "```css\n.a { color: #ff0055; }\n```",
            'notes' => 'Put the language directly after the opening fence.'],
        ['type' => 'table', 'created_by' => 'markdown', 'syntax' => "| Kurve | Einsatz |\n| --- | --- |\n| Ease-Out | Menüs |",
            'notes' => 'The first row is the header and the separator row is required. Cells accept **bold**, *italic* and `code`.'],

        ['type' => 'interactive', 'created_by' => 'tool', 'syntax' => 'add_interactive_block',
            'notes' => 'A page made on eifach.online, embedded by its address - MotionBase neither builds nor hosts interactive elements. eifach.online keeps their versions, files and the school\'s design, and such a page reports its height so the frame fits.'],

        ['type' => 'alert', 'created_by' => 'tool', 'syntax' => 'add_alert_block',
            'notes' => 'Coloured callout: info, warning, danger or neutral. For something a reader must not miss, not for emphasis.'],
        ['type' => 'quiz', 'created_by' => 'tool', 'syntax' => 'add_quiz_block',
            'notes' => 'Multiple choice, single answer. Exactly one option per question must be correct; answers are shuffled per learner, so never refer to their position. Each answer can carry an explanation shown when a learner picks it.'],
        ['type' => 'image', 'created_by' => 'tool', 'syntax' => 'list_media, then add_image_block',
            'notes' => 'A picture already in the media library. Uploading happens in the web editor.'],
        ['type' => 'youtube', 'created_by' => 'tool', 'syntax' => 'add_youtube_block',
            'notes' => 'Takes a watch, share, embed or shorts URL, or a bare video id.'],
        ['type' => 'lottie', 'created_by' => 'tool', 'syntax' => 'list_media, then add_lottie_block',
            'notes' => 'A Lottie file already in the media library. Uploading happens in the web editor.'],
    ];

    public function handle(Request $request): Response
    {
        $blocks = array_map(fn (array $block) => $block + [
            // Derived, not restated: this is the same list the converter and the
            // overwrite warning use, so the three cannot fall out of step.
            'writable' => in_array($block['type'], MarkdownBlocks::MARKDOWN_TYPES, true)
                || $block['created_by'] === 'tool',
        ], self::BLOCKS);

        return Response::json([
            'blocks' => $blocks,
            'markdown_types' => MarkdownBlocks::MARKDOWN_TYPES,
            'editing' => 'Markdown blocks are edited by rewriting the body with update_section. The rest are replaced: remove_block then add it again. remove_block and move_block work on every type.',
            'warning' => 'update_section with markdown replaces the whole body. Block types not in markdown_types are lost and reported in dropped_rich_blocks. Prefer remove_block when you only want one gone.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
