<?php
// This file is part of the MotionBase plugin for Moodle.

/**
 * "Add from MotionBase": tick what the class should get, pick the section,
 * done. Tasks become assignments, chapters books, lessons pages - Moodle's
 * own activities, showing MotionBase's current content whenever opened.
 *
 * @package    filter_motionbase
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use filter_motionbase\catalog;
use filter_motionbase\client;
use filter_motionbase\creator;

$courseid = required_param('course', PARAM_INT);
$sectionnum = optional_param('section', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url(new moodle_url('/filter/motionbase/add.php', ['course' => $course->id, 'section' => $sectionnum]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('addfrommotionbase', 'filter_motionbase'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('addfrommotionbase', 'filter_motionbase'));

$tool = client::tool();
$error = null;
$topics = [];

try {
    $topics = client::get('catalog')['topics'] ?? [];
} catch (moodle_exception $e) {
    $error = $e->getMessage();
}

$catalog = new catalog($topics, $course, $tool);

if ($tool && !$error && data_submitted() && confirm_sesskey()) {
    $items = array_values(array_unique(array_filter(
        optional_param_array('items', [], PARAM_RAW_TRIMMED),
        fn($item) => (bool) preg_match('/^(topic|chapter|section|chat|task):\d+$/', $item)
    )));
    $target = required_param('target', PARAM_INT);

    /**
     * What one tick brings: a chapter brings its book and its tasks, a whole
     * course every chapter that way. Everything else stands for itself.
     */
    $pieces = function(string $item) use ($catalog): array {
        [$type, $id] = explode(':', $item);
        $chapters = match ($type) {
            'topic' => $catalog->chapters((int) $id),
            'chapter' => [(int) $id],
            default => [],
        };

        if (!$chapters) {
            return [$item];
        }

        $pieces = [];
        foreach ($chapters as $chapterid) {
            $pieces[] = 'chapter:' . $chapterid;
            foreach ($catalog->tasks($chapterid) as $taskid) {
                $pieces[] = 'task:' . $taskid;
            }
        }

        return $pieces;
    };

    /**
     * Makes one piece, or nothing where the course already has it - ticking
     * the same chapter twice adds what is missing instead of a second copy.
     *
     * @return string|null The activity's name, null if there was nothing to do
     */
    $make = function(string $piece) use ($course, $target, $context, $catalog, $tool): ?string {
        $option = $catalog->option($piece);

        // Only what the catalogue offered: an id that isn't in it is gone
        // from MotionBase, or was never there.
        if (!$option || $catalog->has($piece)) {
            return null;
        }

        [$type, $id] = explode(':', $piece);

        switch ($type) {
            case 'task':
                require_capability('mod/assign:addinstance', $context);
                creator::assignment($course, $target, client::get('tasks/' . (int) $id));
                break;

            case 'section':
                require_capability('mod/page:addinstance', $context);
                creator::page($course, $target, client::get('lessons/' . (int) $id));
                break;

            case 'chapter':
                require_capability('mod/book:addinstance', $context);
                $chapter = client::get('chapters/' . (int) $id . '?html=1');

                // A chapter of nothing but tasks has no book; its tasks come
                // along as pieces of their own.
                if (!$chapter['lessons']) {
                    return null;
                }

                creator::book($course, $target, $chapter);
                break;

            case 'chat':
                require_capability('mod/lti:addpreconfiguredinstance', $context);
                creator::activity($course, $target, $tool, $piece, $option['title'], $option['summary']);
                break;

            default:
                return null;
        }

        return $option['title'];
    };

    $added = [];
    $failed = [];
    $skipped = 0;
    $done = [];

    foreach ($items as $item) {
        foreach ($pieces($item) as $piece) {
            if (isset($done[$piece])) {
                continue;
            }
            $done[$piece] = true;

            try {
                if ($title = $make($piece)) {
                    $added[] = $title;
                } else if ($catalog->has($piece)) {
                    $skipped++;
                }
            } catch (moodle_exception $e) {
                $failed[] = ($catalog->option($piece)['title'] ?? $piece) . ' (' . $e->getMessage() . ')';
            }
        }
    }

    if ($failed) {
        \core\notification::warning(get_string('failed', 'filter_motionbase', implode(', ', $failed)));
    }

    if ($skipped) {
        \core\notification::info($skipped === 1
            ? get_string('skippedone', 'filter_motionbase')
            : get_string('skipped', 'filter_motionbase', $skipped));
    }

    if ($added) {
        $message = count($added) === 1
            ? get_string('addedone', 'filter_motionbase', reset($added))
            : get_string('added', 'filter_motionbase', count($added));
        redirect(course_get_url($course, $target), $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if (!$failed && !$skipped) {
        \core\notification::info(get_string('nothingselected', 'filter_motionbase'));
    }
}

$sections = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
    // A subsection lives inside an activity; new activities go into real sections.
    if (method_exists($section, 'is_delegated') && $section->is_delegated()) {
        continue;
    }

    $sections[] = [
        'num' => $section->section,
        'name' => get_section_name($course, $section),
        'selected' => $section->section == $sectionnum,
    ];
}

// Unnamed sections share a name ("New section"); their number tells them apart.
$names = array_count_values(array_column($sections, 'name'));
foreach ($sections as &$section) {
    if ($names[$section['name']] > 1) {
        $section['name'] .= ' (' . $section['num'] . ')';
    }
}
unset($section);

$data = [
    'action' => (new moodle_url('/filter/motionbase/add.php'))->out(false),
    'courseid' => $course->id,
    'sesskey' => sesskey(),
    'sections' => $sections,
    'cancelurl' => course_get_url($course, $sectionnum)->out(false),
    'error' => $error,
    'topics' => $error ? [] : $catalog->export(),
    'empty' => !$error && !$topics,
];

$PAGE->requires->js_call_amd('filter_motionbase/picker', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('filter_motionbase/picker', $data);
echo $OUTPUT->footer();
