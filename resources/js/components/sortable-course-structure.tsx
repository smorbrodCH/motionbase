import { cn } from '@/lib/utils';
import { type Chapter, type Section } from '@/types';
import {
    DndContext,
    DragOverlay,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    FileText,
    FolderOpen,
    GripVertical,
    Plus,
    X,
} from 'lucide-react';
import { useState } from 'react';

interface SortableCourseStructureProps {
    topicId: number;
    chapters: Chapter[];
    activeSection: Section | null;
    expandedChapters: Set<number>;
    editingChapterId: number | null;
    editingChapterTitle: string;
    onToggleChapter: (chapterId: number) => void;
    onNavigateToSection: (sectionId: number) => void;
    onCreateChapter: () => void;
    onCreateSection: (chapterId: number) => void;
    onChapterClick: (chapterId: number) => void;
    onEditChapterTitle: (chapterId: number, title: string) => void;
    onSaveChapterTitle: (chapterId: number) => void;
    onCancelEditChapter: () => void;
    setEditingChapterTitle: (title: string) => void;
}

// Sortable Chapter Item
function SortableChapterItem({
    chapter,
    chapterIndex,
    isExpanded,
    isEditing,
    editingChapterTitle,
    activeSection,
    onToggle,
    onNavigateToSection,
    onCreateSection,
    onChapterClick,
    onSaveChapterTitle,
    onCancelEditChapter,
    setEditingChapterTitle,
    topicId,
}: {
    chapter: Chapter;
    chapterIndex: number;
    isExpanded: boolean;
    isEditing: boolean;
    editingChapterTitle: string;
    activeSection: Section | null;
    onToggle: () => void;
    onNavigateToSection: (sectionId: number) => void;
    onCreateSection: (chapterId: number) => void;
    onChapterClick: (chapterId: number) => void;
    onSaveChapterTitle: (chapterId: number) => void;
    onCancelEditChapter: () => void;
    setEditingChapterTitle: (title: string) => void;
    topicId: number;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: `chapter-${chapter.id}` });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    // Section reordering
    const [sectionOrder, setSectionOrder] = useState<number[]>(
        chapter.sections.map((s) => s.id)
    );
    const [activeSectionDrag, setActiveSectionDrag] = useState<Pick<Section, 'id' | 'title'> | null>(null);

    const sectionSensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 5,
            },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    const handleSectionDragStart = (event: DragStartEvent) => {
        const sectionId = Number(String(event.active.id).replace('section-', ''));
        const section = chapter.sections.find((s) => s.id === sectionId);
        if (section) {
            setActiveSectionDrag(section);
        }
    };

    const handleSectionDragEnd = (event: DragEndEvent) => {
        setActiveSectionDrag(null);
        const { active, over } = event;

        if (over && active.id !== over.id) {
            const activeId = Number(String(active.id).replace('section-', ''));
            const overId = Number(String(over.id).replace('section-', ''));

            const oldIndex = sectionOrder.indexOf(activeId);
            const newIndex = sectionOrder.indexOf(overId);

            const newOrder = arrayMove(sectionOrder, oldIndex, newIndex);
            setSectionOrder(newOrder);

            // Save to backend
            router.post(
                `/admin/chapters/${chapter.id}/sections/reorder`,
                { order: newOrder },
                { preserveScroll: true, preserveState: true }
            );
        }
    };

    // Sort sections by current order
    const sortedSections = [...chapter.sections].sort(
        (a, b) => sectionOrder.indexOf(a.id) - sectionOrder.indexOf(b.id)
    );

    return (
        <div ref={setNodeRef} style={style} className="rounded-lg bg-white shadow-sm">
            <div className="flex items-center gap-1 p-2">
                {/* Drag Handle */}
                <button
                    type="button"
                    className="flex h-6 w-6 cursor-grab items-center justify-center rounded text-zinc-300 hover:bg-zinc-100 hover:text-zinc-500 active:cursor-grabbing"
                    {...attributes}
                    {...listeners}
                >
                    <GripVertical className="h-4 w-4" />
                </button>

                <button
                    type="button"
                    onClick={onToggle}
                    className="flex h-6 w-6 items-center justify-center rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600"
                >
                    {isExpanded ? (
                        <ChevronDown className="h-4 w-4" />
                    ) : (
                        <ChevronRight className="h-4 w-4" />
                    )}
                </button>
                <FolderOpen className="h-4 w-4 shrink-0 text-amber-500" />

                {isEditing ? (
                    <div className="flex flex-1 items-center gap-1">
                        <input
                            type="text"
                            value={editingChapterTitle}
                            onChange={(e) => setEditingChapterTitle(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    onSaveChapterTitle(chapter.id);
                                } else if (e.key === 'Escape') {
                                    onCancelEditChapter();
                                }
                            }}
                            className="h-6 flex-1 rounded border border-zinc-300 bg-white px-2 text-sm font-medium text-zinc-700 focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500"
                            autoFocus
                        />
                        <button
                            type="button"
                            onClick={() => onSaveChapterTitle(chapter.id)}
                            className="flex h-6 w-6 items-center justify-center rounded text-green-600 hover:bg-green-50"
                        >
                            <Check className="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            onClick={onCancelEditChapter}
                            className="flex h-6 w-6 items-center justify-center rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                ) : (
                    <>
                        <button
                            type="button"
                            className="flex-1 cursor-pointer truncate text-left text-sm font-medium text-zinc-700 hover:text-zinc-900"
                            onClick={() => onChapterClick(chapter.id)}
                        >
                            {chapter.title}
                        </button>
                        <span className="rounded-full bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-500">
                            {chapter.sections.length}
                        </span>
                        <button
                            type="button"
                            onClick={() => onCreateSection(chapter.id)}
                            className="flex h-6 w-6 items-center justify-center rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600"
                            title="Seite hinzufügen"
                        >
                            <Plus className="h-4 w-4" />
                        </button>
                    </>
                )}
            </div>

            {isExpanded && (
                <div className="space-y-0.5 px-2 pb-2">
                    <DndContext
                        sensors={sectionSensors}
                        collisionDetection={closestCenter}
                        onDragStart={handleSectionDragStart}
                        onDragEnd={handleSectionDragEnd}
                    >
                        <SortableContext
                            items={sortedSections.map((s) => `section-${s.id}`)}
                            strategy={verticalListSortingStrategy}
                        >
                            {sortedSections.map((section) => (
                                <SortableSectionItem
                                    key={section.id}
                                    section={section}
                                    isActive={section.id === activeSection?.id}
                                    onNavigate={() => onNavigateToSection(section.id)}
                                />
                            ))}
                        </SortableContext>
                        <DragOverlay>
                            {activeSectionDrag && (
                                <div className="flex items-center gap-2 rounded-md bg-white px-2 py-1.5 shadow-lg ring-2 ring-zinc-900">
                                    <GripVertical className="h-3 w-3 text-zinc-400" />
                                    <FileText className="h-4 w-4 text-zinc-400" />
                                    <span className="text-sm text-zinc-700">{activeSectionDrag.title}</span>
                                </div>
                            )}
                        </DragOverlay>
                    </DndContext>

                    {chapter.sections.length === 0 && (
                        <button
                            type="button"
                            onClick={() => onCreateSection(chapter.id)}
                            className="flex w-full items-center gap-2 rounded-md border border-dashed border-zinc-200 px-2 py-2 text-xs text-zinc-400 hover:border-zinc-300 hover:text-zinc-500"
                        >
                            <Plus className="h-3.5 w-3.5" />
                            Abschnitt hinzufügen
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

// Sortable Section Item
function SortableSectionItem({
    section,
    isActive,
    onNavigate,
}: {
    section: Pick<Section, 'id' | 'title' | 'slug' | 'sort_order' | 'is_published' | 'task_submission'>;
    isActive: boolean;
    onNavigate: () => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: `section-${section.id}` });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={cn(
                'group flex items-center gap-1 rounded-md px-2 py-1.5 transition-colors',
                isActive
                    ? 'bg-zinc-900 text-white'
                    : 'text-zinc-600 hover:bg-zinc-50',
            )}
        >
            {/* Drag Handle */}
            <button
                type="button"
                className={cn(
                    'flex h-5 w-5 cursor-grab items-center justify-center rounded active:cursor-grabbing',
                    isActive
                        ? 'text-white/50 hover:text-white/70'
                        : 'text-zinc-300 opacity-0 group-hover:opacity-100 hover:text-zinc-500',
                )}
                {...attributes}
                {...listeners}
            >
                <GripVertical className="h-3 w-3" />
            </button>

            <button
                type="button"
                className="flex flex-1 items-center gap-2 text-left"
                onClick={onNavigate}
            >
                {/* Theorie oder Aufgabe - in Moodle wird daraus eine Textseite
                    beziehungsweise eine Aufgabe. */}
                {!section.task_submission ? (
                    <FileText
                        className={cn('h-4 w-4 shrink-0', isActive ? 'text-white/70' : 'text-zinc-400')}
                    />
                ) : (
                    <ClipboardList
                        className={cn('h-4 w-4 shrink-0', isActive ? 'text-white' : 'text-pink-500')}
                    />
                )}
                <span className="truncate text-sm">
                    {section.title}
                </span>
            </button>
        </div>
    );
}

export function SortableCourseStructure({
    topicId,
    chapters,
    activeSection,
    expandedChapters,
    editingChapterId,
    editingChapterTitle,
    onToggleChapter,
    onNavigateToSection,
    onCreateChapter,
    onCreateSection,
    onChapterClick,
    onEditChapterTitle,
    onSaveChapterTitle,
    onCancelEditChapter,
    setEditingChapterTitle,
}: SortableCourseStructureProps) {
    const [chapterOrder, setChapterOrder] = useState<number[]>(
        chapters.map((c) => c.id)
    );
    const [activeChapterDrag, setActiveChapterDrag] = useState<Chapter | null>(null);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 8,
            },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    const handleDragStart = (event: DragStartEvent) => {
        const chapterId = Number(String(event.active.id).replace('chapter-', ''));
        const chapter = chapters.find((c) => c.id === chapterId);
        if (chapter) {
            setActiveChapterDrag(chapter);
        }
    };

    const handleDragEnd = (event: DragEndEvent) => {
        setActiveChapterDrag(null);
        const { active, over } = event;

        if (over && active.id !== over.id) {
            const activeId = Number(String(active.id).replace('chapter-', ''));
            const overId = Number(String(over.id).replace('chapter-', ''));

            const oldIndex = chapterOrder.indexOf(activeId);
            const newIndex = chapterOrder.indexOf(overId);

            const newOrder = arrayMove(chapterOrder, oldIndex, newIndex);
            setChapterOrder(newOrder);

            // Save to backend
            router.post(
                `/admin/topics/${topicId}/chapters/reorder`,
                { order: newOrder },
                { preserveScroll: true, preserveState: true }
            );
        }
    };

    // Sort chapters by current order
    const sortedChapters = [...chapters].sort(
        (a, b) => chapterOrder.indexOf(a.id) - chapterOrder.indexOf(b.id)
    );

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
        >
            <SortableContext
                items={sortedChapters.map((c) => `chapter-${c.id}`)}
                strategy={verticalListSortingStrategy}
            >
                <div className="space-y-2">
                    {sortedChapters.map((chapter, chapterIndex) => {
                        const isExpanded = expandedChapters.has(chapter.id);
                        const isEditing = editingChapterId === chapter.id;

                        return (
                            <SortableChapterItem
                                key={chapter.id}
                                chapter={chapter}
                                chapterIndex={chapterIndex}
                                isExpanded={isExpanded}
                                isEditing={isEditing}
                                editingChapterTitle={editingChapterTitle}
                                activeSection={activeSection}
                                onToggle={() => onToggleChapter(chapter.id)}
                                onNavigateToSection={onNavigateToSection}
                                onCreateSection={onCreateSection}
                                onChapterClick={onChapterClick}
                                onSaveChapterTitle={onSaveChapterTitle}
                                onCancelEditChapter={onCancelEditChapter}
                                setEditingChapterTitle={setEditingChapterTitle}
                                topicId={topicId}
                            />
                        );
                    })}
                </div>
            </SortableContext>

            <DragOverlay>
                {activeChapterDrag && (
                    <div className="rounded-lg bg-white p-2 shadow-lg ring-2 ring-zinc-900">
                        <div className="flex items-center gap-2">
                            <GripVertical className="h-4 w-4 text-zinc-400" />
                            <FolderOpen className="h-4 w-4 text-amber-500" />
                            <span className="text-sm font-medium text-zinc-700">
                                {activeChapterDrag.title}
                            </span>
                            <span className="rounded-full bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-500">
                                {activeChapterDrag.sections.length}
                            </span>
                        </div>
                    </div>
                )}
            </DragOverlay>
        </DndContext>
    );
}
