<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAlertBlock;
use App\Mcp\Tools\AddImageBlock;
use App\Mcp\Tools\AddLottieBlock;
use App\Mcp\Tools\AddYoutubeBlock;
use App\Mcp\Tools\DeleteContent;
use App\Mcp\Tools\ListMedia;
use App\Mcp\Tools\MoveBlock;
use App\Mcp\Tools\RemoveBlock;
use App\Mcp\Tools\AddInteractiveBlock;
use App\Mcp\Tools\AddQuizBlock;
use App\Mcp\Tools\CreateChapter;
use App\Mcp\Tools\CreateSection;
use App\Mcp\Tools\GetSection;
use App\Mcp\Tools\GetTopic;
use App\Mcp\Tools\ListBlockTypes;
use App\Mcp\Tools\ListTopics;
use App\Mcp\Tools\UpdateSection;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Icon;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MotionBase')]
// Without these the client has nothing to go on and falls back to guessing at
// the domain, which is how the old Laravel favicon kept showing up. Relative
// paths are resolved against APP_URL by the package.
#[Icon('/favicon.svg', 'image/svg+xml', ['any'])]
#[Icon('/favicon-96x96.png', 'image/png', ['96x96'])]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
MotionBase is a course authoring platform. Courses are called topics; each topic
has chapters, and each chapter has sections. A section is one page of content.

Every tool acts as the authenticated user and only ever sees that user's own
courses. Start with list_topics, then get_topic for the outline, then
get_section to read a page.

# Block types

A section is a list of Editor.js blocks. Five of them are written as Markdown
through create_section and update_section:

  header      ## / ### / ####   Only levels 2-4 exist. Do NOT open a section
                                with a heading repeating its own title - the
                                title is already rendered above the body. Use
                                ### for subheadings; they also fill the page's
                                table of contents, so give every section one.
  paragraph   plain text        **bold**, *italic*, `code`, [links](url).
  list        - item / 1. item  Unordered and ordered.
  code        ``` fences        Put the language after the fence: ```css
  table       | a | b |         GFM pipe table. The first row is the header and
              | - | - |         the separator row is required. Cells may
              | 1 | 2 |         contain **bold**, *italic* and `code`.

The other six are placed by their own tool:

  interactive   add_interactive_block places a page that already exists -
                MotionBase does not build or host interactive elements.
                They are made on eifach.online, which keeps their versions,
                files (images, 3D models, audio) and the school's design,
                and they are embedded here by their address, e.g.
                https://bzz.eifach.online/physik-pendel/. An eifach.online
                page reports its height, so the frame fits by itself.
  quiz          add_quiz_block. Multiple choice, single answer, exactly one
                option correct. Answers are shuffled per learner, so never
                write one that refers to its own position.
  alert         add_alert_block. info / warning / danger / neutral.
  youtube       add_youtube_block. Any YouTube URL or a bare video id.
  image         list_media to find the id, then add_image_block.
  lottie        list_media to find the id, then add_lottie_block.

Files are uploaded in the web editor; this server places them but does not
accept uploads.

# Tasks for Moodle

A lesson can be a task: create_section or update_section with task set to
none, file, text or both - what learners hand in. A Moodle course with the
MotionBase plugin then takes it over as a native Moodle assignment, the lesson
as its description. Write it like an assignment sheet: what to do,
requirements, example input and output. Grading happens in Moodle, never
here - do not add quizzes to a task.

The plugin also turns chapters into Moodle books and lessons into pages.
Moodle shows every one of them - task, book, page - with the content as it
is here at that moment, so an edit here is live in Moodle at once.

get_section renders those six as "> [...]" placeholders in the body and lists
them under rich_blocks with their full data, so anything removed can be put
back.

# Editing and removing

remove_block and move_block address a block by its index and work on every
type - that is how a single alert or graphic is deleted or reordered without
touching the rest. A Markdown block is edited by rewriting the body; the other
six are replaced by removing and adding them again.

# Overwriting

update_section with markdown replaces the ENTIRE body. Everything that is not
one of the five Markdown-backed types is lost, and the tool reports what it
removed in dropped_rich_blocks. If you only want one block gone, use
remove_block instead. When you do overwrite, read the section first and put the
rich blocks back from the data in rich_blocks.

delete_content removes a section, a chapter with its sections, or a topic with
everything under it, and wants the exact title as confirmation. It is not
reversible in one call - unpublishing is usually what is actually wanted.
TXT)]
class MotionBaseServer extends Server
{
    /**
     * tools/list paginates, and the package defaults to 15 per page. With more
     * tools than that the rest land behind a nextCursor, invisible to any
     * client that does not follow it - and the newest tools are exactly the
     * ones that end up on page two.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        ListTopics::class,
        ListBlockTypes::class,
        GetTopic::class,
        GetSection::class,
        CreateChapter::class,
        CreateSection::class,
        UpdateSection::class,
        AddInteractiveBlock::class,
        AddQuizBlock::class,
        AddAlertBlock::class,
        AddYoutubeBlock::class,
        AddImageBlock::class,
        AddLottieBlock::class,
        ListMedia::class,
        RemoveBlock::class,
        MoveBlock::class,
        DeleteContent::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
