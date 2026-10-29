import { cn } from '@/lib/utils';
import { type Chapter } from '@/types';
import { ChevronDown, ChevronRight, ClipboardList } from 'lucide-react';

// Die öffentliche Ansicht schickt is_task, der Editor task_submission - beides
// heisst: In Moodle wird daraus eine Aufgabe.
type OutlineSection = Chapter['sections'][number] & { is_task?: boolean };
type OutlineChapter = Omit<Chapter, 'sections'> & { sections: OutlineSection[] };

interface CourseOutlineProps {
    chapters: OutlineChapter[];
    activeSectionId?: number | null;
    expandedChapters: Set<number>;
    onToggleChapter: (chapterId: number) => void;
    onNavigate: (chapterSlug: string, sectionSlug: string) => void;
    className?: string;
}

/**
 * Das Kursinhaltsverzeichnis: am Desktop in der Seitenleiste, auf dem Handy
 * im aufklappbaren Verzeichnis. Beide zeigen dasselbe, damit Lernende nicht
 * zwei verschiedene Listen lernen müssen.
 */
export function CourseOutline({
    chapters,
    activeSectionId,
    expandedChapters,
    onToggleChapter,
    onNavigate,
    className,
}: CourseOutlineProps) {
    return (
        <div className={cn('space-y-3', className)}>
            {chapters.map((chapter, chapterIndex) => {
                const isExpanded = expandedChapters.has(chapter.id);
                const hasActiveSectionInChapter = chapter.sections.some((s) => s.id === activeSectionId);

                return (
                    <div key={chapter.id} className="space-y-1">
                        <button
                            type="button"
                            onClick={() => onToggleChapter(chapter.id)}
                            className={cn(
                                'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left transition-colors',
                                hasActiveSectionInChapter
                                    ? 'bg-zinc-100 text-zinc-900'
                                    : 'text-zinc-600 hover:bg-zinc-50',
                            )}
                        >
                            {isExpanded ? (
                                <ChevronDown className="h-4 w-4 shrink-0 text-zinc-400" />
                            ) : (
                                <ChevronRight className="h-4 w-4 shrink-0 text-zinc-400" />
                            )}
                            <span className="truncate text-sm font-semibold">
                                {chapterIndex + 1}. {chapter.title}
                            </span>
                            <span className="ml-auto shrink-0 text-xs text-zinc-400">
                                {chapter.sections.length}
                            </span>
                        </button>

                        {isExpanded && (
                            <div className="ml-3 space-y-1 border-l border-zinc-100 pl-3">
                                {chapter.sections.map((section, sectionIndex) => {
                                    const isActive = section.id === activeSectionId;

                                    return (
                                        <button
                                            key={section.id}
                                            type="button"
                                            onClick={() => onNavigate(chapter.slug, section.slug)}
                                            className={cn(
                                                'group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-all',
                                                isActive
                                                    ? 'bg-zinc-900 text-white'
                                                    : 'text-zinc-500 hover:bg-white hover:text-zinc-900',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold transition-colors',
                                                    isActive
                                                        ? 'bg-white/20 text-white'
                                                        : 'bg-white text-zinc-400 group-hover:text-zinc-700',
                                                )}
                                            >
                                                {sectionIndex + 1}
                                            </span>
                                            <span className="truncate text-left">{section.title}</span>
                                            {/* Aufgaben sind in Moodle eine Aufgabe mit Abgabe - auch hier erkennbar. */}
                                            {(section.is_task || section.task_submission) && (
                                                <ClipboardList
                                                    className={cn(
                                                        'ml-auto h-3.5 w-3.5 shrink-0',
                                                        isActive ? 'text-white/70' : 'text-[#ff0055]',
                                                    )}
                                                />
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
