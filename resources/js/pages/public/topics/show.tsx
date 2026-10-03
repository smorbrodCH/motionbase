import { Button } from '@/components/ui/button';
import { CodeBlock } from '@/components/ui/code-block';
import PublicLayout from '@/layouts/public-layout';
import { type Chapter, type Section, type Topic } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import type { OutputBlockData } from '@editorjs/editorjs';
import { createElement, type ReactNode, useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { cn } from '@/lib/utils';
import { ChevronDown, ChevronRight, ClipboardList, List, CheckCircle2, XCircle, ArrowRight } from 'lucide-react';
import { TopicChat } from '@/components/topic-chat';
import Prism from 'prismjs';
import 'prismjs/components/prism-javascript';
import 'prismjs/components/prism-typescript';
import 'prismjs/components/prism-css';
import 'prismjs/components/prism-markup';
import 'prismjs/components/prism-markup-templating';
import 'prismjs/components/prism-php';
import 'prismjs/components/prism-bash';
import 'prismjs/components/prism-json';

interface PublicTopicShowProps {
    topic: Topic & {
        chapters: Array<Chapter>;
        // is_task: in Moodle wird daraus eine Aufgabe, hier trägt sie ein Abzeichen.
        activeSection: (Section & { is_task?: boolean }) | null;
    };
}

type EditorListItem = string | { content?: string; items?: EditorListItem[] | null };
type ListStyle = 'ordered' | 'unordered';

export default function PublicTopicShow({ topic }: PublicTopicShowProps) {
    const activeSection = topic.activeSection;
    const contentRef = useRef<HTMLElement | null>(null);

    // Track expanded chapters
    const [isTocOpen, setIsTocOpen] = useState(false);
    const [expandedChapters, setExpandedChapters] = useState<Set<number>>(() => {
        // Initially expand all chapters, or at least the one containing the active section
        return new Set(topic.chapters.map((ch) => ch.id));
    });

    const toggleChapter = (chapterId: number) => {
        setExpandedChapters((prev) => {
            const next = new Set(prev);
            if (next.has(chapterId)) {
                next.delete(chapterId);
            } else {
                next.add(chapterId);
            }
            return next;
        });
    };

    useEffect(() => {
        if (contentRef.current) {
            Prism.highlightAllUnder(contentRef.current);
        }
    }, [activeSection?.id]);

    useEffect(() => {
        if (!contentRef.current) {
            return;
        }

        const prefersDesktop = window.matchMedia('(min-width: 1024px)').matches;
        if (prefersDesktop) {
            contentRef.current.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            const top = contentRef.current.getBoundingClientRect().top + window.scrollY - 80;
            window.scrollTo({ top: top < 0 ? 0 : top, behavior: 'smooth' });
        }
    }, [activeSection?.id]);

    const scrollToHeading = (headingId: string) => {
        const heading = document.getElementById(headingId);
        if (!heading) {
            return;
        }

        const container = contentRef.current;
        if (container && container.contains(heading)) {
            const offset = Math.max(heading.offsetTop - 24, 0);
            container.scrollTo({
                top: offset,
                behavior: 'smooth',
            });
            return;
        }

        const headerOffset = 90;
        const elementPosition = heading.getBoundingClientRect().top + window.scrollY;
        window.scrollTo({
            top: elementPosition - headerOffset,
            behavior: 'smooth',
        });
    };

    const tocItems = useMemo(() => {
        const blocks = activeSection?.content?.blocks ?? [];
        return blocks
            .map((block, index) => ({
                block,
                index,
            }))
            .filter(({ block }) => block.type === 'header')
            .map(({ block, index }) => ({
                id: getHeadingId(block, index),
                block,
            }));
    }, [activeSection]);

    const topHeadingLevel = useMemo(
        () => Math.min(...tocItems.map(({ block }) => (block.data?.level as number) ?? 2), 6),
        [tocItems],
    );

    const navigateToSection = (chapterSlug: string, sectionSlug: string) => {
        router.visit(`/themen/${topic.slug}/${chapterSlug}/${sectionSlug}`, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Find the current chapter for the active section
    const currentChapter = topic.chapters.find((ch) =>
        ch.sections.some((s) => s.id === activeSection?.id)
    );

    return (
        <>
            <PublicLayout stickyFooter fullWidth>
                <Head title={`${topic.title} – ${activeSection?.title ?? 'Thema'}`} />

            <div className="relative bg-white lg:min-h-[calc(100vh-128px)] lg:overflow-hidden">
                <div
                    className="grid lg:grid-cols-[320px_minmax(0,1fr)]"
                >
                    <aside className="border-b border-zinc-100 px-4 py-6 lg:border-b-0 lg:border-r lg:px-6 lg:py-8 flex flex-col gap-6 lg:sticky lg:top-16 lg:max-h-[calc(100vh-128px)] lg:overflow-y-auto lg:bg-white">
                        <div className="space-y-1.5 border-b border-zinc-100 pb-6 -mx-4 lg:-mx-6 px-4 lg:px-6">
                            <p className="text-base font-semibold text-zinc-900 leading-snug mb-0">
                                {topic.title}
                            </p>
                            <p className="text-sm text-zinc-400 leading-tight">
                                {topic.author?.name ? `von ${topic.author.name}` : 'Autor unbekannt'}
                            </p>
                        </div>
                        <div className="flex items-center justify-between">
                            <h3 className="text-xs font-bold uppercase tracking-widest text-zinc-400 flex items-center gap-2">
                                <List className="w-3 h-3" /> Kursinhalt
                            </h3>
                        </div>

                        {/* Chapters and Sections */}
                        <div className="space-y-3">
                            {topic.chapters.map((chapter, chapterIndex) => {
                                const isExpanded = expandedChapters.has(chapter.id);
                                const hasActiveSectionInChapter = chapter.sections.some(
                                    (s) => s.id === activeSection?.id
                                );

                                return (
                                    <div key={chapter.id} className="space-y-1">
                                        {/* Chapter Header */}
                                        <button
                                            type="button"
                                            onClick={() => toggleChapter(chapter.id)}
                                            className={cn(
                                                'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left transition-colors',
                                                hasActiveSectionInChapter
                                                    ? 'bg-zinc-100 text-zinc-900'
                                                    : 'text-zinc-600 hover:bg-zinc-50'
                                            )}
                                        >
                                            {isExpanded ? (
                                                <ChevronDown className="h-4 w-4 shrink-0 text-zinc-400" />
                                            ) : (
                                                <ChevronRight className="h-4 w-4 shrink-0 text-zinc-400" />
                                            )}
                                            <span className="text-sm font-semibold truncate">
                                                {chapterIndex + 1}. {chapter.title}
                                            </span>
                                        </button>

                                        {/* Sections */}
                                        {isExpanded && (
                                            <div className="ml-3 space-y-1 border-l border-zinc-100 pl-3">
                                                {chapter.sections.map((section, sectionIndex) => {
                                                    const isActive = section.id === activeSection?.id;
                                                    return (
                                                        <button
                                                            key={section.id}
                                                            type="button"
                                                            onClick={() => navigateToSection(chapter.slug, section.slug)}
                                                            className={cn(
                                                                'group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-all',
                                                                isActive
                                                                    ? 'bg-zinc-900 text-white'
                                                                    : 'text-zinc-500 hover:bg-white hover:text-zinc-900'
                                                            )}
                                                        >
                                                            <span
                                                                className={cn(
                                                                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold transition-colors',
                                                                    isActive
                                                                        ? 'bg-white/20 text-white'
                                                                        : 'bg-white text-zinc-400 group-hover:text-zinc-700'
                                                                )}
                                                            >
                                                                {sectionIndex + 1}
                                                            </span>
                                                            <span className="truncate text-left">
                                                                {section.title}
                                                            </span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </aside>

                    <section
                        ref={contentRef}
                        className="px-4 py-8 sm:px-8 lg:px-12 lg:py-10 lg:max-h-[calc(100vh-128px)] lg:overflow-y-auto"
                    >
                        <header className="pb-6 space-y-3">
                            <div className="flex items-center gap-2 text-xs font-medium text-[#ff0055] uppercase tracking-wider">
                                <span className="h-px w-8 bg-[#ff0055]/50"></span>
                                {currentChapter ? `${currentChapter.title}` : topic.title}
                            </div>
                            <h1 className="text-3xl font-bold tracking-tight text-zinc-900 sm:text-5xl leading-tight">
                                {activeSection?.title ?? topic.title}
                            </h1>
                            {activeSection?.is_task && (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-700">
                                    <ClipboardList className="h-3.5 w-3.5" />
                                    Aufgabe
                                </span>
                            )}
                        </header>

                        {tocItems.length > 0 && (
                            <nav aria-label="Inhaltsverzeichnis" className="mb-8">
                                <div
                                    className={cn(
                                        'overflow-hidden rounded-xl border transition-colors duration-200',
                                        isTocOpen
                                            ? 'border-zinc-200 bg-zinc-50'
                                            : 'border-zinc-200 bg-white hover:border-zinc-300',
                                    )}
                                >
                                    <h2>
                                        <button
                                            type="button"
                                            onClick={() => setIsTocOpen((open) => !open)}
                                            aria-expanded={isTocOpen}
                                            aria-controls="inhaltsverzeichnis"
                                            className="group flex w-full items-center gap-2.5 px-4 py-3 text-left"
                                        >
                                            <ChevronRight
                                                className={cn(
                                                    'h-4 w-4 shrink-0 text-zinc-400 transition-transform duration-200 group-hover:text-zinc-600',
                                                    isTocOpen && 'rotate-90',
                                                )}
                                            />
                                            <span className="text-sm font-medium text-zinc-700 transition-colors group-hover:text-zinc-900">
                                                Inhaltsverzeichnis
                                            </span>
                                            <span className="ml-auto text-xs tabular-nums text-zinc-400">
                                                {tocItems.length}
                                            </span>
                                        </button>
                                    </h2>

                                    {/* Animating grid rows reveals the list without measuring its
                                        height; inert keeps the links out of the tab order while
                                        they are collapsed but still in the DOM. */}
                                    <div
                                        className={cn(
                                            'grid transition-[grid-template-rows] duration-200 ease-out',
                                            isTocOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
                                        )}
                                        inert={!isTocOpen}
                                    >
                                        <div className="overflow-hidden">
                                            <ul id="inhaltsverzeichnis" className="border-t border-zinc-200 px-4 py-2">
                                                {tocItems.map(({ block, id }) => {
                                                    const level = block.data?.level ?? 2;
                                                    const isTopLevel = level <= topHeadingLevel;

                                                    return (
                                                        <li key={id}>
                                                            <a
                                                                href={`#${id}`}
                                                                onClick={(e) => {
                                                                    e.preventDefault();
                                                                    scrollToHeading(id);
                                                                }}
                                                                className={cn(
                                                                    'block rounded-md py-1.5 text-sm transition-colors hover:text-[#ff0055]',
                                                                    isTopLevel ? 'text-zinc-700' : 'pl-4 text-zinc-600',
                                                                )}
                                                                dangerouslySetInnerHTML={{ __html: block.data?.text ?? '' }}
                                                            />
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </nav>
                        )}

                        <div className="prose max-w-none prose-headings:font-bold prose-headings:tracking-tight prose-headings:text-zinc-900 prose-p:text-zinc-600 prose-p:leading-relaxed prose-li:text-zinc-600 prose-strong:text-zinc-900">
                            {renderBlocks(activeSection?.content?.blocks ?? [])}
                        </div>
                    </section>

                </div>
            </div>
            </PublicLayout>

            {/* AI Chat Assistant */}
            <TopicChat topicSlug={topic.slug} />
        </>
    );
}

type RenderTheme = {
    textClass?: string;
    headingClass?: Partial<Record<2 | 3 | 4, string>>;
};

function renderBlocks(blocks: OutputBlockData[], theme?: RenderTheme) {
    if (!blocks.length) {
        return (
            <div className="flex flex-col items-center justify-center py-12 text-zinc-400 border border-dashed border-zinc-200 rounded-xl bg-zinc-50">
                <p>Dieser Abschnitt ist noch leer.</p>
            </div>
        );
    }

    return blocks.map((block, index) => {
        const key = block.id ?? `${block.type}-${index}`;

        switch (block.type) {
            case 'header': {
                const level = Math.min(Math.max(block.data.level ?? 2, 2), 4) as 2 | 3 | 4;
                const headingTagMap: Record<typeof level, 'h2' | 'h3' | 'h4'> = {
                    2: 'h2',
                    3: 'h3',
                    4: 'h4',
                };
                const headingClassMap: Record<typeof level, string> = {
                    2: 'text-xl sm:text-3xl font-semibold tracking-tight text-zinc-900 leading-tight',
                    3: 'text-lg sm:text-xl font-semibold text-zinc-900 leading-tight',
                    4: 'text-base sm:text-lg font-semibold text-zinc-900',
                };
                const headingId = getHeadingId(block, index);

                return createElement(headingTagMap[level] ?? 'h2', {
                    key,
                    id: headingId,
                    className: cn(
                        'scroll-mt-20 mt-12 first:mt-0 mb-4',
                        headingClassMap[level] ?? '',
                        theme?.headingClass?.[level] ?? '',
                    ),
                    dangerouslySetInnerHTML: { __html: block.data.text ?? '' },
                });
            }

            case 'list': {
                const style = block.data.style === 'ordered' ? 'ordered' : 'unordered';
                return (
                    <ul
                        key={key}
                        className="my-6 list-none space-y-2"
                    >
                        {renderListItems(block.data.items as EditorListItem[] | undefined, key, style, theme)}
                    </ul>
                );
            }

            case 'alert': {
                const type = (block.data?.type ?? 'info') as 'info' | 'warning' | 'danger' | 'neutral';
                const innerBlocks = (block.data?.contentBlocks?.blocks ?? []) as OutputBlockData[];
                const hasStructuredContent = innerBlocks.length > 0;
                const fallbackMessage = block.data?.content ?? '';
                const alertClassMap: Record<typeof type, string> = {
                    info: 'border-sky-200 bg-sky-50 text-sky-900',
                    warning: 'border-amber-200 bg-amber-50 text-amber-900',
                    danger: 'border-rose-200 bg-rose-50 text-rose-950',
                    neutral: 'border-zinc-200 bg-white text-zinc-900',
                };

                return (
                    <div
                        key={key}
                        className={cn(
                            'mb-6 rounded-2xl border px-4 py-4 text-sm leading-relaxed lg:px-5 lg:py-5',
                            alertClassMap[type],
                        )}
                    >
                        {hasStructuredContent ? (
                            <div className="space-y-4">
                                {renderBlocks(innerBlocks, alertThemes[type])}
                            </div>
                        ) : (
                            <div
                                className={alertThemes[type]?.textClass}
                                dangerouslySetInnerHTML={{ __html: fallbackMessage.replace(/\n/g, '<br />') }}
                            />
                        )}
                    </div>
                );
            }

            case 'image': {
                // Support both old format (file.url) and new format (url)
                const imageUrl = block.data?.url ?? block.data?.file?.url ?? '';
                const caption = block.data?.caption ?? '';
                const withBorder = block.data?.withBorder ?? false;
                const stretched = block.data?.stretched ?? false;
                const withBackground = block.data?.withBackground ?? false;

                return (
                    <figure
                        key={key}
                        className={cn(
                            'mb-6',
                            stretched && '-mx-4 lg:-mx-8',
                            withBackground && 'rounded-2xl bg-zinc-100 p-4',
                        )}
                    >
                        <img
                            src={imageUrl}
                            alt={caption || 'Bild'}
                            className={cn(
                                'h-auto w-full',
                                !stretched && 'rounded-xl',
                                withBorder && 'border border-zinc-200',
                            )}
                            loading="lazy"
                        />
                        {caption && (
                            <figcaption
                                className="mt-3 text-center text-sm text-zinc-500"
                                dangerouslySetInnerHTML={{ __html: caption }}
                            />
                        )}
                    </figure>
                );
            }

            case 'quiz':
                return <QuizRenderer key={key} data={block.data} />;

            case 'youtube': {
                const videoId = block.data?.videoId as string | undefined;
                const caption = (block.data?.caption as string | undefined)?.trim();

                if (!videoId) {
                    return null;
                }

                return (
                    <figure key={key} className="my-8">
                        <div className="relative w-full overflow-hidden rounded-xl bg-zinc-900" style={{ paddingBottom: '56.25%' }}>
                            <iframe
                                src={`https://www.youtube-nocookie.com/embed/${videoId}`}
                                className="absolute inset-0 h-full w-full border-0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowFullScreen
                                loading="lazy"
                                title={caption || 'YouTube Video'}
                            />
                        </div>
                        {caption && caption.length > 0 && (
                            <figcaption className="mt-3 text-center text-sm text-zinc-500">
                                {caption}
                            </figcaption>
                        )}
                    </figure>
                );
            }

            case 'lottie': {
                const lottieUrl = block.data?.url as string | undefined;
                const lottieCaption = (block.data?.caption as string | undefined)?.trim();
                const loop = block.data?.loop !== false;
                const autoplay = block.data?.autoplay !== false;

                if (!lottieUrl) {
                    return null;
                }

                return (
                    <LottieRenderer
                        key={key}
                        url={lottieUrl}
                        caption={lottieCaption}
                        loop={loop}
                        autoplay={autoplay}
                    />
                );
            }

            case 'table': {
                const rows = (block.data?.content as string[][] | undefined) ?? [];
                const withHeadings = block.data?.withHeadings !== false;

                if (rows.length === 0) {
                    return null;
                }

                const head = withHeadings ? rows[0] : null;
                const body = withHeadings ? rows.slice(1) : rows;

                return (
                    <div key={key} className="my-8 overflow-x-auto rounded-xl border border-zinc-200">
                        <table className="w-full border-collapse text-left text-sm">
                            {head && (
                                <thead>
                                    <tr className="bg-zinc-50">
                                        {head.map((cell, i) => (
                                            <th
                                                key={i}
                                                scope="col"
                                                className="border-b border-zinc-200 px-4 py-2.5 font-semibold text-zinc-900"
                                                dangerouslySetInnerHTML={{ __html: cell ?? '' }}
                                            />
                                        ))}
                                    </tr>
                                </thead>
                            )}
                            <tbody>
                                {body.map((row, r) => (
                                    <tr key={r} className="border-b border-zinc-100 last:border-b-0">
                                        {row.map((cell, c) => (
                                            <td
                                                key={c}
                                                className="px-4 py-2.5 align-top text-zinc-600"
                                                dangerouslySetInnerHTML={{ __html: cell ?? '' }}
                                            />
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                );
            }

            case 'interactive': {
                const interactiveUrl = block.data?.url as string | undefined;
                const interactiveCaption = (block.data?.caption as string | undefined)?.trim();
                const interactiveHeight = block.data?.height as number | undefined;

                if (!interactiveUrl || !isSafeInteractiveUrl(interactiveUrl)) {
                    return null;
                }

                return (
                    <InteractiveRenderer
                        key={key}
                        url={interactiveUrl}
                        caption={interactiveCaption}
                        height={interactiveHeight}
                    />
                );
            }

            case 'code': {
                const language = (block.data?.language as string) ?? 'javascript';
                const codeContent = (block.data?.code as string) ?? '';

                return (
                    <CodeBlock
                        key={key}
                        code={codeContent}
                        language={language}
                    />
                );
            }

            case 'paragraph':
            default:
                return (
                    <p
                        key={key}
                        dangerouslySetInnerHTML={{ __html: block.data.text ?? '' }}
                        className={cn('mb-6 last:mb-0', theme?.textClass ?? 'text-zinc-600')}
                    />
                );
        }
    });
}

function renderListItems(
    items: EditorListItem[] = [],
    keyPrefix = '',
    style: ListStyle = 'unordered',
    theme?: RenderTheme,
): ReactNode[] {
    return items.map((item, index) => {
        const itemKey = `${keyPrefix}-${index}`;
        const content =
            typeof item === 'string'
                ? item
                : typeof item?.content === 'string'
                  ? item.content
                  : '';
        const childItems =
            typeof item === 'object' && item !== null && Array.isArray(item.items)
                ? (item.items as EditorListItem[])
                : [];

        const bullet = style === 'ordered' ? `${index + 1}.` : '–';

        return (
            <li key={itemKey} className="list-none space-y-1.5">
                <div className={cn('flex gap-3', theme?.textClass ?? 'text-zinc-900')}>
                    <span className="font-normal">{bullet}</span>
                    <div className="flex-1" dangerouslySetInnerHTML={{ __html: content }} />
                </div>
                {childItems.length > 0 && (
                    <ul className="ml-6 list-none space-y-1.5">
                        {renderListItems(childItems, `${itemKey}-child`, style, theme)}
                    </ul>
                )}
            </li>
        );
    });
}

const alertThemes: Record<
    'info' | 'warning' | 'danger' | 'neutral',
    RenderTheme
> = {
    info: {
        textClass: 'text-sky-900',
        headingClass: {
            2: '!text-sky-900',
            3: '!text-sky-900',
            4: '!text-sky-900',
        },
    },
    warning: {
        textClass: 'text-amber-900',
        headingClass: {
            2: '!text-amber-900',
            3: '!text-amber-900',
            4: '!text-amber-900',
        },
    },
    danger: {
        textClass: 'text-rose-950',
        headingClass: {
            2: '!text-rose-950',
            3: '!text-rose-950',
            4: '!text-rose-950',
        },
    },
    neutral: {
        textClass: 'text-zinc-900',
        headingClass: {
            2: '!text-zinc-900',
            3: '!text-zinc-900',
            4: '!text-zinc-900',
        },
    },
};

function getHeadingId(block: OutputBlockData, fallbackIndex: number): string {
    if (block.id) {
        return String(block.id);
    }

    const rawText =
        typeof block.data?.text === 'string'
            ? block.data.text.replace(/<[^>]*>/g, '').trim().toLowerCase()
            : '';

    if (rawText) {
        const slug = rawText
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

        if (slug) {
            return `${slug}-${fallbackIndex}`;
        }
    }

    return `heading-${fallbackIndex}`;
}

// Quiz Types
interface QuizQuestion {
    id: string;
    question: string;
    imageUrl?: string;
    answers: {
        id: string;
        text: string;
        isCorrect: boolean;
        explanation?: string;
    }[];
}

interface QuizData {
    questions: QuizQuestion[];
}

// Shuffle array helper (Fisher-Yates)
function shuffleArray<T>(array: T[]): T[] {
    const shuffled = [...array];
    for (let i = shuffled.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }
    return shuffled;
}

// Quiz Renderer Component
function QuizRenderer({ data }: { data: QuizData }) {
    const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
    const [selectedAnswerId, setSelectedAnswerId] = useState<string | null>(null);
    const [hasAnswered, setHasAnswered] = useState(false);
    const [correctCount, setCorrectCount] = useState(0);
    const [isCompleted, setIsCompleted] = useState(false);

    const questions = data?.questions ?? [];
    const totalQuestions = questions.length;

    // Randomize answers for each question (memoized per quiz session)
    const [shuffledAnswersMap] = useState<Map<string, typeof questions[0]['answers']>>(() => {
        const map = new Map();
        questions.forEach((q) => {
            map.set(q.id, shuffleArray(q.answers));
        });
        return map;
    });

    const currentQuestion = questions[currentQuestionIndex];
    const currentAnswers = currentQuestion ? (shuffledAnswersMap.get(currentQuestion.id) ?? currentQuestion.answers) : [];

    const handleAnswerSelect = useCallback((answerId: string) => {
        if (hasAnswered) return;

        setSelectedAnswerId(answerId);
        setHasAnswered(true);

        const isCorrect = currentAnswers.find((a) => a.id === answerId)?.isCorrect ?? false;
        if (isCorrect) {
            setCorrectCount((prev) => prev + 1);
        }
    }, [hasAnswered, currentAnswers]);

    // Fires once the quiz ends with nothing wrong. The overlay itself lives in
    // a Blade partial so the course view, the public embed and the LTI embed
    // all run the same animation instead of three lookalikes.
    useEffect(() => {
        if (!isCompleted || totalQuestions === 0) {
            return;
        }

        const celebrate = (window as unknown as {
            motionbaseCelebrate?: (options: {
                variant?: string;
                title?: string;
                subtitle?: string;
            }) => void;
        }).motionbaseCelebrate;

        if (correctCount === totalQuestions) {
            celebrate?.({
                variant: 'high-five',
                title: 'Alles richtig.',
                subtitle: `${totalQuestions} von ${totalQuestions} Fragen.`,
            });

            return;
        }

        // Easter egg. 67 % is what two right out of three rounds to, so it
        // turns up on its own rather than having to be aimed for.
        if (Math.round((correctCount / totalQuestions) * 100) === 67) {
            celebrate?.({
                variant: 'six-seven',
                title: 'six-seven',
                subtitle: `${correctCount} / ${totalQuestions} · 67 %`,
            });
        }
    }, [isCompleted, correctCount, totalQuestions]);

    const handleNextQuestion = useCallback(() => {
        if (currentQuestionIndex < totalQuestions - 1) {
            setCurrentQuestionIndex((prev) => prev + 1);
            setSelectedAnswerId(null);
            setHasAnswered(false);
        } else {
            setIsCompleted(true);
        }
    }, [currentQuestionIndex, totalQuestions]);

    const handleRestart = useCallback(() => {
        // Re-shuffle answers on restart
        questions.forEach((q) => {
            shuffledAnswersMap.set(q.id, shuffleArray(q.answers));
        });
        setCurrentQuestionIndex(0);
        setSelectedAnswerId(null);
        setHasAnswered(false);
        setCorrectCount(0);
        setIsCompleted(false);
    }, [questions, shuffledAnswersMap]);

    if (!questions.length) {
        return null;
    }

    if (isCompleted) {
        const percentage = Math.round((correctCount / totalQuestions) * 100);
        const isPerfect = correctCount === totalQuestions;
        const isGood = percentage >= 70;

        return (
            <div className="my-8 rounded-2xl border border-zinc-200 bg-zinc-50 p-6 sm:p-8">
                <div className="text-center space-y-5">
                    <div className={cn(
                        'inline-flex items-center justify-center w-20 h-20 rounded-full',
                        isPerfect ? 'bg-zinc-900' : isGood ? 'bg-emerald-100' : 'bg-amber-100'
                    )}>
                        {isPerfect ? (
                            <CheckCircle2 className="w-10 h-10 text-white" />
                        ) : (
                            <span className={cn(
                                'text-3xl font-bold',
                                isGood ? 'text-emerald-600' : 'text-amber-600'
                            )}>
                                {percentage}%
                            </span>
                        )}
                    </div>
                    <div>
                        <h3 className="text-xl font-bold text-zinc-900">
                            {isPerfect ? 'Perfekt!' : isGood ? 'Gut gemacht!' : 'Weiter üben!'}
                        </h3>
                        <p className="text-zinc-500 mt-1">
                            Du hast {correctCount} von {totalQuestions} Fragen richtig beantwortet.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={handleRestart}
                        className="inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-zinc-800"
                    >
                        Quiz wiederholen
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="my-8 rounded-2xl border border-zinc-200 bg-zinc-50 overflow-hidden">
            {/* Progress Header */}
            <div className="flex items-center justify-between px-5 py-4 sm:px-6 bg-white border-b border-zinc-100">
                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-zinc-900 text-sm font-bold text-white">
                    {currentQuestionIndex + 1}
                </div>
                <div className="flex gap-1.5">
                    {questions.map((_, idx) => (
                        <div
                            key={idx}
                            className={cn(
                                'h-2 w-6 rounded-full transition-colors',
                                idx < currentQuestionIndex
                                    ? 'bg-zinc-900'
                                    : idx === currentQuestionIndex
                                        ? 'bg-zinc-400'
                                        : 'bg-zinc-200'
                            )}
                        />
                    ))}
                </div>
            </div>

            {/* Question Content */}
            <div className="p-5 sm:p-6 space-y-5">
                {/* Question Image */}
                {currentQuestion?.imageUrl && (
                    <div className="rounded-xl overflow-hidden border border-zinc-100">
                        <img
                            src={currentQuestion.imageUrl}
                            alt="Frage-Bild"
                            className="w-full h-auto object-contain"
                        />
                    </div>
                )}

                {/* Question Text */}
                <h4 className="text-lg font-semibold text-zinc-900 leading-snug">
                    {currentQuestion?.question}
                </h4>

                {/* Answers */}
                <div className="space-y-3">
                    {currentAnswers.map((answer, idx) => {
                        const isSelected = selectedAnswerId === answer.id;
                        const showCorrect = hasAnswered && answer.isCorrect;
                        const showIncorrect = hasAnswered && isSelected && !answer.isCorrect;

                        // Only the picked option and the right one explain
                        // themselves - revealing every rationale at once would
                        // give away the answer on the next attempt.
                        const explanation = hasAnswered && (isSelected || answer.isCorrect)
                            ? answer.explanation?.trim()
                            : undefined;

                        return (
                            <div key={answer.id} className="space-y-1.5">
                            <button
                                type="button"
                                onClick={() => handleAnswerSelect(answer.id)}
                                disabled={hasAnswered}
                                className={cn(
                                    'group flex w-full items-center gap-4 rounded-xl border-2 px-4 py-3.5 text-left transition-all',
                                    !hasAnswered && 'hover:border-zinc-400 hover:bg-white cursor-pointer',
                                    hasAnswered && 'cursor-default',
                                    !hasAnswered && !isSelected && 'border-zinc-200 bg-white',
                                    !hasAnswered && isSelected && 'border-zinc-900 bg-white',
                                    showCorrect && 'border-emerald-500 bg-emerald-50',
                                    showIncorrect && 'border-rose-500 bg-rose-50',
                                    hasAnswered && !showCorrect && !showIncorrect && 'border-zinc-100 bg-zinc-50/50 opacity-60'
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold transition-colors',
                                        !hasAnswered && 'bg-zinc-100 text-zinc-600 group-hover:bg-zinc-200 group-hover:text-zinc-900',
                                        showCorrect && 'bg-emerald-500 text-white',
                                        showIncorrect && 'bg-rose-500 text-white',
                                        hasAnswered && !showCorrect && !showIncorrect && 'bg-zinc-100 text-zinc-400'
                                    )}
                                >
                                    {showCorrect ? (
                                        <CheckCircle2 className="w-4 h-4" />
                                    ) : showIncorrect ? (
                                        <XCircle className="w-4 h-4" />
                                    ) : (
                                        String.fromCharCode(65 + idx)
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'flex-1 text-sm font-medium',
                                        showCorrect && 'text-emerald-900',
                                        showIncorrect && 'text-rose-900',
                                        !showCorrect && !showIncorrect && 'text-zinc-700'
                                    )}
                                >
                                    {answer.text}
                                </span>
                            </button>

                            {explanation && (
                                <p
                                    className={cn(
                                        'ml-4 border-l-2 pl-3 text-sm leading-relaxed',
                                        answer.isCorrect
                                            ? 'border-emerald-300 text-emerald-800'
                                            : 'border-rose-300 text-rose-800',
                                    )}
                                >
                                    {explanation}
                                </p>
                            )}
                            </div>
                        );
                    })}
                </div>

                {/* Feedback & Next Button */}
                {hasAnswered && (
                    <div className="flex items-center justify-between pt-2">
                        <div
                            className={cn(
                                'text-sm font-medium',
                                currentAnswers.find((a) => a.id === selectedAnswerId)?.isCorrect
                                    ? 'text-emerald-600'
                                    : 'text-rose-600'
                            )}
                        >
                            {currentAnswers.find((a) => a.id === selectedAnswerId)?.isCorrect
                                ? '✓ Richtig!'
                                : '✗ Leider falsch'}
                        </div>
                        <button
                            type="button"
                            onClick={handleNextQuestion}
                            className="inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-zinc-800"
                        >
                            {currentQuestionIndex < totalQuestions - 1 ? (
                                <>
                                    Nächste Frage
                                    <ArrowRight className="w-4 h-4" />
                                </>
                            ) : (
                                'Ergebnis anzeigen'
                            )}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

// Interactive Renderer Component - Sandboxed HTML graphics with auto-height
const INTERACTIVE_DEFAULT_HEIGHT = 480;
const INTERACTIVE_MIN_HEIGHT = 120;
const INTERACTIVE_MAX_HEIGHT = 5000;

// Only same-app paths and http(s) URLs may become an iframe src
function isSafeInteractiveUrl(url: string): boolean {
    return url.startsWith('/') || /^https?:\/\//i.test(url);
}

function clampInteractiveHeight(value: unknown): number {
    const height = Number(value);

    if (!Number.isFinite(height) || height <= 0) {
        return INTERACTIVE_DEFAULT_HEIGHT;
    }

    return Math.round(Math.min(INTERACTIVE_MAX_HEIGHT, Math.max(INTERACTIVE_MIN_HEIGHT, height)));
}

function InteractiveRenderer({
    url,
    caption,
    height,
}: {
    url: string;
    caption?: string;
    height?: number;
}) {
    const iframeRef = useRef<HTMLIFrameElement>(null);
    const [frameHeight, setFrameHeight] = useState(() => clampInteractiveHeight(height));

    useEffect(() => {
        setFrameHeight(clampInteractiveHeight(height));
    }, [height]);

    useEffect(() => {
        // Sandboxed frames have an opaque origin, so event.origin is always
        // "null" and useless for verification - identify the sender by window.
        const handleMessage = (event: MessageEvent) => {
            if (!iframeRef.current || event.source !== iframeRef.current.contentWindow) {
                return;
            }

            const payload = event.data;
            if (!payload || payload.type !== 'motionbase:resize') {
                return;
            }

            setFrameHeight(clampInteractiveHeight(payload.height));
        };

        window.addEventListener('message', handleMessage);

        return () => window.removeEventListener('message', handleMessage);
    }, []);

    return (
        <figure className="my-8">
            <div className="overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50">
                <iframe
                    ref={iframeRef}
                    src={url}
                    // No allow-same-origin: the graphic runs on an opaque origin
                    // and cannot reach the app's cookies or localStorage.
                    sandbox="allow-scripts"
                    loading="lazy"
                    title={caption || 'Interaktive Grafik'}
                    className="block w-full border-0 transition-[height] duration-200"
                    style={{ height: `${frameHeight}px` }}
                />
            </div>
            {caption && caption.length > 0 && (
                <figcaption className="mt-3 text-center text-sm text-zinc-500">
                    {caption}
                </figcaption>
            )}
        </figure>
    );
}

// Lottie Renderer Component - Supports State Machines in .lottie files
function LottieRenderer({
    url,
    caption,
    loop,
    autoplay,
}: {
    url: string;
    caption?: string;
    loop: boolean;
    autoplay: boolean;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasContainerRef = useRef<HTMLDivElement>(null);
    const [isPlaying, setIsPlaying] = useState(autoplay);
    const [hasStateMachine, setHasStateMachine] = useState(false);
    const [isLoaded, setIsLoaded] = useState(false);
    const animationRef = useRef<ReturnType<typeof import('lottie-web').default.loadAnimation> | null>(null);
    const dotLottieRef = useRef<InstanceType<typeof import('@lottiefiles/dotlottie-web').DotLottie> | null>(null);

    const isDotLottie = url.endsWith('.lottie');

    useEffect(() => {
        let isMounted = true;

        const loadAnimation = async () => {
            try {
                if (isDotLottie) {
                    // Use dotLottie-web for .lottie files (supports State Machines)
                    if (!canvasContainerRef.current) return;

                    const { DotLottie } = await import('@lottiefiles/dotlottie-web');

                    if (!isMounted) return;

                    // Destroy previous instance
                    if (dotLottieRef.current) {
                        dotLottieRef.current.destroy();
                    }

                    // Clear container and create fresh canvas
                    canvasContainerRef.current.innerHTML = '';
                    const canvas = document.createElement('canvas');
                    canvas.style.display = 'block';
                    canvas.style.width = '100%';
                    canvasContainerRef.current.appendChild(canvas);

                    // Create the dotLottie instance
                    const instance = new DotLottie({
                        canvas,
                        src: url,
                        autoplay: false,
                        loop: false,
                    });

                    instance.addEventListener('load', () => {
                        if (!isMounted) {
                            instance.destroy();
                            return;
                        }

                        const manifest = instance.manifest;
                        const stateMachines = (manifest as { stateMachines?: { id: string }[] })?.stateMachines;
                        const hasStates = stateMachines && stateMachines.length > 0;

                        // Check if we need state machine
                        if (hasStates) {
                            setHasStateMachine(true);
                            // Destroy current instance and recreate with state machine
                            instance.destroy();
                            
                            if (!canvasContainerRef.current) return;
                            canvasContainerRef.current.innerHTML = '';
                            
                            const newCanvas = document.createElement('canvas');
                            newCanvas.style.display = 'block';
                            newCanvas.style.width = '100%';
                            canvasContainerRef.current.appendChild(newCanvas);
                            
                            const firstStateMachineId = stateMachines[0]?.id;
                            
                            dotLottieRef.current = new DotLottie({
                                canvas: newCanvas,
                                src: url,
                                loop,
                                autoplay: true,
                                stateMachineId: firstStateMachineId,
                            });

                            dotLottieRef.current.addEventListener('load', () => {
                                setIsLoaded(true);
                            });
                        } else {
                            setHasStateMachine(false);
                            // Keep using the same instance
                            dotLottieRef.current = instance;
                            
                            if (loop !== false) {
                                instance.setLoop(true);
                            }
                            if (autoplay) {
                                instance.play();
                            }
                            
                            setIsLoaded(true);
                        }

                        dotLottieRef.current?.addEventListener('play', () => {
                            setIsPlaying(true);
                        });

                        dotLottieRef.current?.addEventListener('pause', () => {
                            setIsPlaying(false);
                        });

                        dotLottieRef.current?.addEventListener('stop', () => {
                            setIsPlaying(false);
                        });

                        // Listen for state machine events
                        dotLottieRef.current?.addEventListener('stateMachineStart', () => {
                            console.log('State machine started');
                        });

                        dotLottieRef.current?.addEventListener('stateMachineTransition', (event) => {
                            console.log('State machine transition:', event);
                        });

                        // Handle window resize
                        const handleResize = () => {
                            if (!dotLottieRef.current) return;
                            dotLottieRef.current.resize();
                        };

                        window.addEventListener('resize', handleResize);
                        (dotLottieRef.current as unknown as { _resizeHandler?: () => void })._resizeHandler = handleResize;
                    });

                    instance.addEventListener('loadError', (error) => {
                        console.error('Failed to load dotLottie:', error);
                    });

                } else {
                    // Use lottie-web for .json files
                    if (!containerRef.current) return;

                    const lottie = await import('lottie-web');

                    if (!isMounted || !containerRef.current) return;

                    // Clear previous animation
                    if (animationRef.current) {
                        animationRef.current.destroy();
                    }

                    animationRef.current = lottie.default.loadAnimation({
                        container: containerRef.current,
                        renderer: 'svg',
                        loop,
                        autoplay,
                        path: url,
                    });

                    if (autoplay) {
                        setIsPlaying(true);
                    }

                    animationRef.current.addEventListener('DOMLoaded', () => {
                        setIsLoaded(true);
                    });

                    animationRef.current.addEventListener('complete', () => {
                        if (!loop) {
                            setIsPlaying(false);
                        }
                    });
                }
            } catch (error) {
                console.error('Failed to load Lottie animation:', error);
            }
        };

        void loadAnimation();

        return () => {
            isMounted = false;
            if (animationRef.current) {
                animationRef.current.destroy();
                animationRef.current = null;
            }
            if (dotLottieRef.current) {
                // Remove resize handler
                const handler = (dotLottieRef.current as unknown as { _resizeHandler?: () => void })._resizeHandler;
                if (handler) {
                    window.removeEventListener('resize', handler);
                }
                dotLottieRef.current.destroy();
                dotLottieRef.current = null;
            }
        };
    }, [url, loop, autoplay, isDotLottie]);

    const togglePlayPause = useCallback(() => {
        // Don't interfere with state machine controlled animations
        if (hasStateMachine) return;

        if (isDotLottie && dotLottieRef.current) {
            if (isPlaying) {
                dotLottieRef.current.pause();
            } else {
                dotLottieRef.current.play();
            }
        } else if (animationRef.current) {
            if (isPlaying) {
                animationRef.current.pause();
            } else {
                animationRef.current.play();
            }
        }
    }, [isDotLottie, isPlaying, hasStateMachine]);

    return (
        <figure className="my-8">
            <div
                className={cn(
                    'relative rounded-xl overflow-hidden group',
                    !hasStateMachine && 'cursor-pointer'
                )}
                onClick={togglePlayPause}
            >
                {isDotLottie ? (
                    <div
                        ref={canvasContainerRef}
                        className="w-full"
                    />
                ) : (
                    <div
                        ref={containerRef}
                        className="w-full [&_svg]:w-full [&_svg]:h-auto"
                    />
                )}
                {/* Loading indicator */}
                {!isLoaded && (
                    <div className="absolute inset-0 flex items-center justify-center bg-zinc-50">
                        <div className="h-8 w-8 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-600" />
                    </div>
                )}
                {/* Play/Pause overlay - only for non-state-machine animations */}
                {isLoaded && !hasStateMachine && (
                    <div className={cn(
                        'absolute inset-0 flex items-center justify-center bg-black/5 opacity-0 transition-opacity',
                        'group-hover:opacity-100'
                    )}>
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-white/90 shadow-lg">
                            {isPlaying ? (
                                <svg viewBox="0 0 24 24" className="w-5 h-5 text-zinc-700" fill="currentColor">
                                    <rect x="6" y="4" width="4" height="16" rx="1" />
                                    <rect x="14" y="4" width="4" height="16" rx="1" />
                                </svg>
                            ) : (
                                <svg viewBox="0 0 24 24" className="w-5 h-5 text-zinc-700 ml-0.5" fill="currentColor">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                            )}
                        </div>
                    </div>
                )}
                {/* State machine indicator */}
                {isLoaded && hasStateMachine && (
                    <div className="absolute bottom-2 right-2 rounded-full bg-white/80 px-2 py-1 text-xs font-medium text-zinc-600 shadow-sm">
                        Interaktiv
                    </div>
                )}
            </div>
            {caption && caption.length > 0 && (
                <figcaption className="mt-3 text-center text-sm text-zinc-500">
                    {caption}
                </figcaption>
            )}
        </figure>
    );
}
