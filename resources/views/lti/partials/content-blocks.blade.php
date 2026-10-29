@php
    use Illuminate\Support\Str;

    if (!function_exists('renderEditorBlock')) {
    function renderEditorBlock($block, $index = 0) {
        $type = $block['type'] ?? 'paragraph';
        $data = $block['data'] ?? [];

        switch ($type) {
            case 'header':
                $level = min(max($data['level'] ?? 2, 2), 4);
                $text = $data['text'] ?? '';
                $classes = match($level) {
                    2 => 'text-2xl font-bold mt-8 mb-4',
                    3 => 'text-xl font-semibold mt-6 mb-3',
                    4 => 'text-lg font-semibold mt-4 mb-2',
                    default => 'text-xl font-bold mt-6 mb-3',
                };
                return "<h{$level} class=\"{$classes}\">{$text}</h{$level}>";

            case 'paragraph':
                $text = $data['text'] ?? '';
                return "<p class=\"mb-4 text-zinc-600 leading-relaxed\">{$text}</p>";

            case 'list':
                $items = $data['items'] ?? [];
                $style = $data['style'] ?? 'unordered';
                $listItems = renderListItems($items, $style);
                return "<ul class=\"mb-6 space-y-2 list-none\">{$listItems}</ul>";

            case 'code':
                $code = htmlspecialchars($data['code'] ?? '', ENT_QUOTES, 'UTF-8');
                $language = $data['language'] ?? 'plaintext';
                $languageLabels = [
                    'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'jsx' => 'JSX', 'tsx' => 'TSX',
                    'php' => 'PHP', 'css' => 'CSS', 'html' => 'HTML', 'markup' => 'HTML', 'bash' => 'Terminal',
                    'shell' => 'Terminal', 'sh' => 'Terminal', 'json' => 'JSON', 'yaml' => 'YAML', 'sql' => 'SQL',
                    'python' => 'Python', 'ruby' => 'Ruby', 'go' => 'Go', 'rust' => 'Rust', 'java' => 'Java',
                    'plaintext' => 'Text', 'text' => 'Text',
                ];
                $languageLabel = $languageLabels[$language] ?? ucfirst($language);
                $blockId = 'code-' . md5($code . $index);
                return "
                <div class=\"code-block group relative mb-6 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50\">
                    <div class=\"flex items-center justify-between border-b border-zinc-200 bg-zinc-100/80 px-4 py-2\">
                        <span class=\"text-xs font-medium text-zinc-600\">{$languageLabel}</span>
                        <button onclick=\"copyCode('{$blockId}')\" class=\"copy-btn flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-200 hover:text-zinc-800 transition-all\">
                            <svg class=\"copy-icon w-3.5 h-3.5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><rect width=\"14\" height=\"14\" x=\"8\" y=\"8\" rx=\"2\" ry=\"2\" stroke-width=\"2\"/><path d=\"M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2\" stroke-width=\"2\"/></svg>
                            <span class=\"hidden sm:inline\">Kopieren</span>
                        </button>
                    </div>
                    <div class=\"overflow-x-auto bg-white\">
                        <pre class=\"p-4 text-sm leading-relaxed language-{$language}\" id=\"{$blockId}\"><code class=\"language-{$language}\">{$code}</code></pre>
                    </div>
                </div>";

            case 'image':
                $url = $data['url'] ?? $data['file']['url'] ?? '';
                $caption = $data['caption'] ?? '';
                $withBorder = $data['withBorder'] ?? false;
                $stretched = $data['stretched'] ?? false;
                $borderClass = $withBorder ? 'border border-zinc-200' : '';
                $stretchedClass = $stretched ? '-mx-4 lg:-mx-8' : '';
                $html = "<figure class=\"mb-6 {$stretchedClass}\">";
                $html .= "<img src=\"{$url}\" alt=\"" . htmlspecialchars($caption ?: 'Bild', ENT_QUOTES) . "\" class=\"w-full h-auto rounded-xl {$borderClass}\" loading=\"lazy\">";
                if ($caption) {
                    $html .= "<figcaption class=\"mt-2 text-center text-sm text-zinc-500\">{$caption}</figcaption>";
                }
                $html .= "</figure>";
                return $html;

            case 'youtube':
                $videoId = $data['videoId'] ?? '';
                $caption = $data['caption'] ?? '';
                if (!$videoId) return '';
                $html = "<figure class=\"mb-6\">";
                $html .= "<div class=\"relative w-full overflow-hidden rounded-xl bg-zinc-900\" style=\"padding-bottom: 56.25%\">";
                $html .= "<iframe src=\"https://www.youtube-nocookie.com/embed/{$videoId}\" class=\"absolute inset-0 w-full h-full border-0\" allowfullscreen loading=\"lazy\"></iframe>";
                $html .= "</div>";
                if ($caption) {
                    $html .= "<figcaption class=\"mt-2 text-center text-sm text-zinc-500\">{$caption}</figcaption>";
                }
                $html .= "</figure>";
                return $html;

            case 'alert':
                $alertType = $data['type'] ?? 'info';
                $content = $data['content'] ?? '';
                $innerBlocks = $data['contentBlocks']['blocks'] ?? [];
                $alertClass = match($alertType) {
                    'warning' => 'alert-warning',
                    'danger' => 'alert-danger',
                    'neutral' => 'alert-neutral',
                    default => 'alert-info',
                };
                $html = "<div class=\"mb-6 rounded-xl border p-4 {$alertClass}\">";
                if (!empty($innerBlocks)) {
                    foreach ($innerBlocks as $i => $innerBlock) {
                        $html .= renderEditorBlock($innerBlock, $i);
                    }
                } else {
                    $html .= "<div>" . nl2br($content) . "</div>";
                }
                $html .= "</div>";
                return $html;

            case 'lottie':
                $url = $data['url'] ?? '';
                $caption = $data['caption'] ?? '';
                if (!$url) return '';
                // For Lottie, we'll show a placeholder with a link
                $html = "<figure class=\"mb-6 p-8 bg-zinc-50 rounded-xl text-center\">";
                $html .= "<div class=\"text-zinc-400 mb-2\">Lottie Animation</div>";
                if ($caption) {
                    $html .= "<figcaption class=\"text-sm text-zinc-500\">{$caption}</figcaption>";
                }
                $html .= "</figure>";
                return $html;

            case 'table':
                $rows = $data['content'] ?? [];
                if (empty($rows)) return '';

                $withHeadings = ($data['withHeadings'] ?? true) !== false;
                $cell = fn ($value) => is_string($value) ? $value : '';

                $html = "<div class=\"mb-6 overflow-x-auto rounded-xl border border-zinc-200\">";
                $html .= "<table class=\"w-full border-collapse text-left text-sm\">";

                if ($withHeadings) {
                    $head = array_shift($rows);
                    $html .= "<thead><tr class=\"bg-zinc-50\">";
                    foreach ((array) $head as $value) {
                        $html .= "<th scope=\"col\" class=\"border-b border-zinc-200 px-4 py-2.5 font-semibold text-zinc-900\">".$cell($value)."</th>";
                    }
                    $html .= "</tr></thead>";
                }

                $html .= "<tbody>";
                foreach ($rows as $row) {
                    $html .= "<tr class=\"border-b border-zinc-100 last:border-b-0\">";
                    foreach ((array) $row as $value) {
                        $html .= "<td class=\"px-4 py-2.5 align-top text-zinc-600\">".$cell($value)."</td>";
                    }
                    $html .= "</tr>";
                }
                $html .= "</tbody></table></div>";

                return $html;

            case 'interactive':
                $url = trim($data['url'] ?? '');
                $caption = $data['caption'] ?? '';

                // Only same-app paths and http(s) URLs may become an iframe src
                if (!$url || !(Str::startsWith($url, '/') || preg_match('#^https?://#i', $url))) {
                    return '';
                }

                $height = (int) ($data['height'] ?? 480);
                $height = max(120, min(5000, $height > 0 ? $height : 480));

                $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                // Seiten von eifach.online behalten ihre eigene Herkunft, eigene Uploads nicht
                $sandbox = \App\Services\InteractiveEmbed::sandbox($url);
                $safeCaption = htmlspecialchars($caption, ENT_QUOTES, 'UTF-8');
                $title = $safeCaption !== '' ? $safeCaption : 'Interaktive Grafik';

                $html = "<figure class=\"mb-6\">";
                $html .= "<div class=\"overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50\">";
                // No allow-same-origin: the graphic runs on an opaque origin and
                // cannot reach the app's cookies or localStorage.
                $html .= "<iframe src=\"{$safeUrl}\" class=\"interactive-frame block w-full border-0\" style=\"height: {$height}px\" sandbox=\"{$sandbox}\" loading=\"lazy\" title=\"{$title}\"></iframe>";
                $html .= "</div>";
                if ($safeCaption !== '') {
                    $html .= "<figcaption class=\"mt-3 text-center text-sm text-zinc-500\">{$safeCaption}</figcaption>";
                }
                $html .= "</figure>";
                return $html;

            case 'quiz':
                $questions = $data['questions'] ?? [];
                if (empty($questions)) {
                    return "<div class=\"mb-6 p-6 bg-zinc-50 rounded-xl border border-zinc-200 text-center\">
                        <div class=\"text-sm text-zinc-500\">Keine Fragen vorhanden.</div>
                    </div>";
                }
                $quizId = 'quiz-' . md5(json_encode($questions) . $index);
                $quizData = htmlspecialchars(json_encode($questions), ENT_QUOTES, 'UTF-8');
                return "<div class=\"quiz-container mb-6\" id=\"{$quizId}\" data-questions=\"{$quizData}\"></div>";

            default:
                return '';
        }
    }
    } // end if !function_exists('renderEditorBlock')

    if (!function_exists('renderListItems')) {
    function renderListItems($items, $style = 'unordered', $depth = 0) {
        $html = '';
        foreach ($items as $index => $item) {
            $content = is_string($item) ? $item : ($item['content'] ?? '');
            $children = is_array($item) ? ($item['items'] ?? []) : [];
            $bullet = $style === 'ordered' ? ($index + 1) . '.' : '–';

            $html .= "<li class=\"flex gap-3 text-zinc-600\">";
            $html .= "<span class=\"shrink-0\">{$bullet}</span>";
            $html .= "<div class=\"flex-1\">{$content}";
            if (!empty($children)) {
                $html .= "<ul class=\"mt-2 space-y-2\">" . renderListItems($children, $style, $depth + 1) . "</ul>";
            }
            $html .= "</div></li>";
        }
        return $html;
    }
    } // end if !function_exists('renderListItems')
@endphp

@if(empty($blocks))
    <div class="flex flex-col items-center justify-center py-12 text-zinc-400 border border-dashed border-zinc-200 rounded-xl bg-zinc-50">
        <p>Dieser Abschnitt ist noch leer.</p>
    </div>
@else
    @foreach($blocks as $index => $block)
        {!! renderEditorBlock($block, $index) !!}
    @endforeach
@endif
