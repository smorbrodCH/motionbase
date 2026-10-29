<?php

namespace App\Services;

use App\Models\Section;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A lesson as the description of a Moodle assignment.
 *
 * Plain semantic HTML that Moodle's theme styles on its own. Moodle strips
 * iframes from descriptions, so interactive graphics and videos become links
 * - a YouTube link is turned back into a player by Moodle's media filter.
 * Text the editor allowed to carry markup goes through an allow-list: the
 * description ends up inside somebody else's Moodle.
 */
class MoodleHtml
{
    /** Inline markup Editor.js produces, with the attributes each may keep. */
    private const INLINE = [
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
        'code' => [], 'mark' => [], 'br' => [], 'a' => ['href'],
    ];

    /** Interactive graphics as frames rather than links - see render(). */
    private bool $embed = false;

    /**
     * @param  bool  $embed  For Moodle's MotionBase filter, whose output Moodle
     *                       does not clean: interactive graphics as frames.
     *                       What is stored in Moodle goes through its cleaning,
     *                       which would drop a frame without a trace.
     * @param  bool  $source  End with a link to the lesson in MotionBase.
     */
    public function render(Section $section, bool $embed = false, bool $source = true): string
    {
        $this->embed = $embed;
        $html = $this->blocks($section->content['blocks'] ?? []);

        if (! $source) {
            return $html;
        }

        $chapter = $section->chapter;
        $url = route('public.topics.show', [
            'topic' => $chapter->topic->slug,
            'chapter' => $chapter->slug,
            'section' => $section->slug,
        ]);

        return $html.'<p class="mb-source"><a href="'.e($url).'">Diese Aufgabe in MotionBase öffnen</a></p>';
    }

    public function blocks(array $blocks): string
    {
        return implode("\n", array_filter(array_map(fn ($block) => is_array($block) ? $this->block($block) : '', $blocks)));
    }

    private function block(array $block): string
    {
        $data = $block['data'] ?? [];

        return match ($block['type'] ?? null) {
            // The activity name is Moodle's heading, so the lesson's own
            // levels move one step down beneath it.
            'header' => (function () use ($data) {
                $level = min(max((int) ($data['level'] ?? 2), 2), 4) + 1;

                return "<h{$level}>".$this->inline($data['text'] ?? '')."</h{$level}>";
            })(),
            'paragraph' => trim($data['text'] ?? '') === '' ? '' : '<p>'.$this->inline($data['text']).'</p>',
            'list' => $this->listItems($data['items'] ?? [], ($data['style'] ?? '') === 'ordered'),
            'code' => '<pre><code'.(! empty($data['language']) ? ' class="language-'.e(preg_replace('/[^a-z0-9+#-]/i', '', $data['language'])).'"' : '').'>'
                .e($data['code'] ?? '').'</code></pre>',
            'table' => $this->table($data),
            'image' => $this->image($data),
            'alert' => $this->alert($data),
            'youtube' => ! empty($data['videoId'])
                ? '<p><a href="https://www.youtube.com/watch?v='.e(rawurlencode($data['videoId'])).'">'
                    .e($this->text($data['caption'] ?? '') ?: 'Video auf YouTube').'</a></p>'
                : '',
            'interactive' => $this->interactive($data),
            // Neither plays in a Moodle description; the link to the lesson
            // at the end leads to them.
            'quiz', 'lottie' => '',
            default => '',
        };
    }

    private function interactive(array $data): string
    {
        $url = $this->url($data['url'] ?? '');

        if (! $url) {
            return '';
        }

        $caption = $this->text($data['caption'] ?? '');

        if (! $this->embed) {
            return '<p><a href="'.e($url).'">Interaktive Grafik öffnen'.($caption ? ': '.e($caption) : '').'</a></p>';
        }

        $height = min(max((int) ($data['height'] ?? 480), 120), 5000);

        // Die Grafik läuft weiter dort, wo sie liegt - auf MotionBase oder auf
        // eifach.online. Sie meldet ihre Höhe; das Skript des Plugins stellt
        // den Rahmen darauf ein.
        return '<figure class="mb-interactive"><iframe class="motionbase-frame" src="'.e($url).'" title="'.e($caption ?: 'Interaktive Grafik').'"'
            .' sandbox="'.InteractiveEmbed::sandbox($url).'" loading="lazy" allowfullscreen'
            .' style="display: block; width: 100%; height: '.$height.'px; border: 0; border-radius: 0.5rem;"></iframe>'
            .($caption ? '<figcaption>'.e($caption).'</figcaption>' : '').'</figure>';
    }

    private function listItems(array $items, bool $ordered): string
    {
        if ($items === []) {
            return '';
        }

        $tag = $ordered ? 'ol' : 'ul';
        $html = "<{$tag}>";

        foreach ($items as $item) {
            // Older lists hold strings, newer ones {content, items}
            $content = is_array($item) ? ($item['content'] ?? $item['text'] ?? '') : (string) $item;
            $children = is_array($item) ? ($item['items'] ?? []) : [];

            $html .= '<li>'.$this->inline($content).(is_array($children) ? $this->listItems($children, $ordered) : '').'</li>';
        }

        return $html."</{$tag}>";
    }

    private function table(array $data): string
    {
        $rows = array_values(array_filter($data['content'] ?? [], 'is_array'));

        if ($rows === []) {
            return '';
        }

        $html = '<table class="table table-bordered">';

        if (($data['withHeadings'] ?? true) !== false) {
            $head = array_shift($rows);
            $html .= '<thead><tr>'.implode('', array_map(fn ($cell) => '<th scope="col">'.$this->inline((string) $cell).'</th>', $head)).'</tr></thead>';
        }

        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn ($cell) => '<td>'.$this->inline((string) $cell).'</td>', $row)).'</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function image(array $data): string
    {
        $url = $this->url($data['url'] ?? $data['file']['url'] ?? '');

        if (! $url) {
            return '';
        }

        $caption = $this->text($data['caption'] ?? '');

        return '<figure><img src="'.e($url).'" alt="'.e($caption).'" style="max-width: 100%; height: auto;">'
            .($caption ? '<figcaption>'.e($caption).'</figcaption>' : '').'</figure>';
    }

    private function alert(array $data): string
    {
        $class = match ($data['type'] ?? 'info') {
            'warning' => 'alert-warning',
            'danger' => 'alert-danger',
            'neutral' => 'alert-secondary',
            default => 'alert-info',
        };

        $inner = ! empty($data['contentBlocks']['blocks'])
            ? $this->blocks($data['contentBlocks']['blocks'])
            : '<p>'.$this->inline($data['content'] ?? '').'</p>';

        return '<div class="alert '.$class.'">'.$inner.'</div>';
    }

    /**
     * Inline markup, reduced to the allow-list: other elements give up their
     * tags but keep their text, and only a link keeps an attribute - its
     * address, and only if it goes somewhere harmless.
     */
    private function inline(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->getElementsByTagName('div')->item(0);

        return $root ? $this->children($root) : e(strip_tags($html));
    }

    private function children(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $html .= e($child->textContent);
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                    continue;
                }

                if (! array_key_exists($tag, self::INLINE)) {
                    $html .= $this->children($child);

                    continue;
                }

                if ($tag === 'br') {
                    $html .= '<br>';

                    continue;
                }

                $attributes = '';
                if ($tag === 'a' && ($href = $this->url($child->getAttribute('href'), mailto: true))) {
                    $attributes = ' href="'.e($href).'"';
                }

                $html .= "<{$tag}{$attributes}>".$this->children($child)."</{$tag}>";
            }
        }

        return $html;
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * An address a learner may be sent to: http(s), a mail link where allowed,
     * or a path on MotionBase made absolute - in Moodle, "/storage/..." would
     * point at the school's own server.
     */
    private function url(string $url, bool $mailto = false): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return rtrim(config('app.url'), '/').$url;
        }

        if (preg_match('#^https?://#i', $url) || ($mailto && preg_match('#^mailto:#i', $url))) {
            return $url;
        }

        return null;
    }
}
