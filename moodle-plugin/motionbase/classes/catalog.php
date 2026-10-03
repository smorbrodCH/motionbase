<?php
// This file is part of the MotionBase plugin for Moodle.

namespace filter_motionbase;

/**
 * MotionBase's catalogue as the picker shows it: every option with the name
 * and description its activity will get, and whether the course has it yet.
 *
 * @package    filter_motionbase
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalog {
    /** @var array Options by value, e.g. "chapter:3" */
    private array $options = [];

    /** @var array Values already in the course */
    private array $present = [];

    /**
     * @param array $topics As returned by MotionBase
     * @param \stdClass $course
     * @param \stdClass|null $tool The MotionBase tool type
     */
    public function __construct(private array $topics, \stdClass $course, ?\stdClass $tool) {
        foreach ($topics as $topic) {
            $this->add('topic:' . $topic['id'], $topic['title'], '');
            $this->add('chat:' . $topic['id'], get_string('assistanttitle', 'filter_motionbase', $topic['title']),
                get_string('assistantsummary', 'filter_motionbase', $topic['title']));

            foreach ($topic['chapters'] as $chapter) {
                if ($chapter['sections']) {
                    $this->add('chapter:' . $chapter['id'], $chapter['title'], '');
                }

                foreach ($chapter['sections'] as $section) {
                    $kind = empty($section['task']) ? 'section' : 'task';
                    $this->add($kind . ':' . $section['id'], $section['title'], '');
                }
            }
        }

        $this->present = self::present($course, $tool);
    }

    /**
     * The chapters of a topic that have something to add.
     *
     * @param int $topicid
     * @return int[]
     */
    public function chapters(int $topicid): array {
        foreach ($this->topics as $topic) {
            if ((int) $topic['id'] === $topicid) {
                $filled = array_filter($topic['chapters'], fn($chapter) => (bool) $chapter['sections']);
                return array_values(array_map(fn($chapter) => (int) $chapter['id'], $filled));
            }
        }

        return [];
    }

    /**
     * A chapter's tasks - in Moodle each becomes an assignment of its own.
     *
     * @param int $chapterid
     * @return int[]
     */
    public function tasks(int $chapterid): array {
        foreach ($this->topics as $topic) {
            foreach ($topic['chapters'] as $chapter) {
                if ((int) $chapter['id'] === $chapterid) {
                    $tasks = array_filter($chapter['sections'], fn($section) => !empty($section['task']));
                    return array_values(array_map(fn($section) => (int) $section['id'], $tasks));
                }
            }
        }

        return [];
    }

    /**
     * Is this already in the course?
     *
     * @param string $value e.g. "task:67"
     * @return bool
     */
    public function has(string $value): bool {
        return in_array($value, $this->present, true);
    }

    /**
     * A chapter's lessons that are not tasks - what its book and pages are made
     * of. Tasks become assignments of their own.
     *
     * @param array $chapter
     * @return array
     */
    private static function theory(array $chapter): array {
        return array_values(array_filter($chapter['sections'], fn($section) => empty($section['task'])));
    }

    /**
     * The option behind a value, if MotionBase offers it.
     *
     * @param string $value
     * @return array|null title, summary
     */
    public function option(string $value): ?array {
        return $this->options[$value] ?? null;
    }

    /**
     * The ID number of an activity made from MotionBase, e.g. motionbase-chapter-39.
     *
     * @param string $source "task:67", "section:63" or "chapter:39"
     * @return string
     */
    public static function idnumber(string $source): string {
        return 'motionbase-' . str_replace(':', '-', $source);
    }

    /**
     * Template data for the picker.
     *
     * @return array
     */
    public function export(): array {
        return array_map(function(array $topic) {
            $chapters = [];
            $parts = [];

            foreach ($topic['chapters'] as $chapter) {
                if (!$chapter['sections']) {
                    continue;
                }

                $theory = self::theory($chapter);
                $tasks = array_values(array_filter($chapter['sections'], fn($section) => !empty($section['task'])));

                // Ein Haken bringt das ganze Kapitel: das Buch und seine Aufgaben.
                $pieces = array_merge(
                    $theory ? ['chapter:' . $chapter['id']] : [],
                    array_map(fn($task) => 'task:' . $task['id'], $tasks)
                );
                $parts = array_merge($parts, $pieces);

                // Einzeln wählbar: jede Theorieseite als Textseite, jede Aufgabe für sich.
                $lessons = array_merge(
                    array_map(fn($section) => $this->item('section:' . $section['id'], $section['title'],
                        get_string('pagemeta', 'filter_motionbase'),
                        $topic['title'] . ' ' . $chapter['title'] . ' ' . $section['title']), $theory),
                    array_map(fn($task) => $this->item('task:' . $task['id'], $task['title'],
                        get_string('task_' . $task['task'], 'filter_motionbase'),
                        $topic['title'] . ' ' . $chapter['title'] . ' ' . $task['title'] . ' '
                            . get_string('tasks', 'filter_motionbase')), $tasks)
                );

                $chapters[] = ['present' => !array_diff($pieces, $this->present)]
                    + $this->item('chapter:' . $chapter['id'], $chapter['title'],
                        self::chaptermeta(count($theory), count($tasks)),
                        $topic['title'] . ' ' . $chapter['title'],
                        ['lessons' => $lessons, 'haslessons' => count($lessons) > 1]);
            }

            return [
                'id' => $topic['id'],
                'title' => $topic['title'],
                'meta' => self::lessons($topic['lessons']),
                // Ein Kurs ist da, wenn jedes seiner Kapitel da ist.
                'whole' => $chapters ? ['present' => !array_diff($parts, $this->present)]
                    + $this->item('topic:' . $topic['id'], get_string('wholecourse', 'filter_motionbase'),
                        get_string('wholecoursemeta', 'filter_motionbase', count($chapters)), $topic['title']) : null,
                'chapters' => $chapters,
                'assistant' => $this->item('chat:' . $topic['id'], get_string('assistant', 'filter_motionbase'),
                    get_string('assistantmeta', 'filter_motionbase', $topic['title']),
                    $topic['title'] . ' ' . get_string('assistant', 'filter_motionbase') . ' chat ki ai'),
            ];
        }, $this->topics);
    }

    /**
     * "Buch · 3 Lektionen · 2 Aufgaben" - was aus dem Kapitel in Moodle entsteht.
     *
     * @param int $theory
     * @param int $tasks
     * @return string
     */
    private static function chaptermeta(int $theory, int $tasks): string {
        $parts = [];

        if ($theory) {
            $parts[] = get_string('bookmeta', 'filter_motionbase', self::lessons($theory));
        }

        if ($tasks) {
            $parts[] = $tasks === 1
                ? get_string('task', 'filter_motionbase')
                : get_string('taskcount', 'filter_motionbase', $tasks);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param string $value
     * @param string $title Activity name
     * @param string $summary Activity description, plain text
     */
    private function add(string $value, string $title, string $summary): void {
        $this->options[$value] = ['title' => $title, 'summary' => $summary];
    }

    /**
     * One checkbox in the picker.
     *
     * @param string $value
     * @param string $name
     * @param string $meta
     * @param string $search Words it is found by
     * @param array $extra
     * @return array
     */
    private function item(string $value, string $name, string $meta, string $search, array $extra = []): array {
        return [
            'value' => $value,
            'name' => $name,
            'meta' => $meta,
            'label' => $this->options[$value]['title'] ?? $name,
            'search' => \core_text::strtolower($search),
            'present' => in_array($value, $this->present, true),
        ] + $extra;
    }

    /**
     * "1 lesson", "12 lessons".
     *
     * @param int $count
     * @return string
     */
    private static function lessons(int $count): string {
        return $count === 1 ? get_string('lesson', 'filter_motionbase') : get_string('lessons', 'filter_motionbase', $count);
    }

    /**
     * What the course already has from MotionBase, as picker values.
     *
     * @param \stdClass $course
     * @param \stdClass|null $tool
     * @return string[]
     */
    private static function present(\stdClass $course, ?\stdClass $tool): array {
        global $DB;

        $present = [];

        $idnumbers = $DB->get_fieldset_sql(
            "SELECT idnumber FROM {course_modules} WHERE course = ? AND deletioninprogress = 0 AND idnumber LIKE ?",
            [$course->id, 'motionbase-%']
        );

        foreach ($idnumbers as $idnumber) {
            if (preg_match('/^motionbase-(task|section|chapter)-(\d+)$/', $idnumber, $m)) {
                $present[] = $m[1] . ':' . $m[2];
            }
        }

        // The assistant is an activity of the external tool, found by what it launches.
        if ($tool) {
            $params = $DB->get_fieldset_sql(
                "SELECT l.instructorcustomparameters
                   FROM {lti} l
                   JOIN {course_modules} cm ON cm.instance = l.id AND cm.deletioninprogress = 0
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'lti'
                  WHERE l.course = ? AND l.typeid = ?",
                [$course->id, $tool->id]
            );

            foreach ($params as $text) {
                if (preg_match('/content_type=chat\b/', (string) $text) && preg_match('/topic_id=(\d+)/', (string) $text, $m)) {
                    $present[] = 'chat:' . $m[1];
                }
            }
        }

        return $present;
    }
}
