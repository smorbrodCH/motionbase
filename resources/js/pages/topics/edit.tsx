import InputError from '@/components/input-error';
import { RichTextEditor, type RichTextEditorHandle } from '@/components/editor/rich-text-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type Category, type Chapter, type Section, type Topic } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import type { OutputData } from '@editorjs/editorjs';
import { useEffect, useRef, useCallback } from 'react';
import {
    ArrowLeft,
    Check,
    ChevronRight,
    ClipboardList,
    Code,
    Copy,
    FileText,
    FolderOpen,
    PanelLeftClose,
    PanelLeftOpen,
    Plus,
    Save,
    Settings,
    TriangleAlert,
    Trash2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import { TopicHistory } from '@/components/revisions/topic-history';
import { CreateItemDialog } from '@/components/create-item-dialog';
import { SortableCourseStructure } from '@/components/sortable-course-structure';
import { useAutosave, type AutosaveStatus } from '@/hooks/use-autosave';
import { Spinner } from '@/components/ui/spinner';

type EditorContent = OutputData;

function SaveIndicator({
    status,
    lastSavedAt,
    errorMessage,
}: {
    status: AutosaveStatus;
    lastSavedAt: Date | null;
    errorMessage: string | null;
}) {
    const [, forceTick] = useState(0);

    // Keep the "vor X" label honest without re-rendering the whole editor.
    useEffect(() => {
        if (status !== 'saved') {
            return;
        }

        const interval = setInterval(() => forceTick((tick) => tick + 1), 30_000);
        return () => clearInterval(interval);
    }, [status, lastSavedAt]);

    if (status === 'error') {
        return (
            <span className="flex items-center gap-1.5 text-xs font-medium text-red-600">
                <TriangleAlert className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">{errorMessage ?? 'Nicht gespeichert'}</span>
            </span>
        );
    }

    if (status === 'saving') {
        return (
            <span className="flex items-center gap-1.5 text-xs text-zinc-500">
                <Spinner className="size-3.5" />
                <span className="hidden sm:inline">Wird gespeichert…</span>
            </span>
        );
    }

    if (status === 'unsaved') {
        return (
            <span className="flex items-center gap-1.5 text-xs text-amber-600">
                <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                <span className="hidden sm:inline">Ungespeicherte Änderungen</span>
            </span>
        );
    }

    if (status === 'saved') {
        return (
            <span className="flex items-center gap-1.5 text-xs text-zinc-400">
                <Check className="h-3.5 w-3.5 text-emerald-600" />
                <span className="hidden sm:inline">{describeSaveTime(lastSavedAt)}</span>
            </span>
        );
    }

    return null;
}

function describeSaveTime(savedAt: Date | null): string {
    if (!savedAt) {
        return 'Gespeichert';
    }

    const seconds = Math.floor((Date.now() - savedAt.getTime()) / 1000);

    if (seconds < 60) {
        return 'Gerade gespeichert';
    }

    const minutes = Math.floor(seconds / 60);

    return minutes < 60
        ? `Gespeichert vor ${minutes} Min.`
        : `Gespeichert um ${savedAt.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' })}`;
}

/** Stable serialisation used to decide whether a section still needs saving. */
function snapshot(title: string, content: EditorContent | null): string {
    return JSON.stringify({ title, content });
}

interface TopicsEditProps {
    topic: Topic & {
        category_id: number;
        chapters: Array<Chapter>;
    };
    activeSection: Section | null;
    categories: Pick<Category, 'id' | 'name'>[];
}

export default function TopicsEdit({ topic, activeSection, categories }: TopicsEditProps) {
    // Sidebar visibility
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [settingsOpen, setSettingsOpen] = useState(false);

    // Dialog for creating chapters/sections
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogType, setDialogType] = useState<'chapter' | 'section'>('chapter');
    const [dialogChapterId, setDialogChapterId] = useState<number | undefined>(undefined);

    // Settings panel tabs
    const [settingsTab, setSettingsTab] = useState<'topic' | 'chapter' | 'section'>('topic');
    const [selectedChapterId, setSelectedChapterId] = useState<number | null>(null);

    // Track expanded chapters
    const [expandedChapters, setExpandedChapters] = useState<Set<number>>(() => {
        const activeChapterId = topic.chapters.find((ch) =>
            ch.sections.some((s) => s.id === activeSection?.id)
        )?.id;
        return new Set(activeChapterId ? [activeChapterId] : topic.chapters.slice(0, 1).map((ch) => ch.id));
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

    const topicForm = useForm({
        title: topic.title,
        slug: topic.slug,
        category_id: topic.category_id,
    });

    // Find the selected chapter
    const selectedChapter = topic.chapters.find(ch => ch.id === selectedChapterId);

    // Chapter settings state (using React state instead of useForm for better control)
    const [chapterSettings, setChapterSettings] = useState({
        title: '',
        slug: '',
        is_published: false,
    });
    const [chapterSettingsOriginal, setChapterSettingsOriginal] = useState({
        title: '',
        slug: '',
        is_published: false,
    });
    const [chapterSettingsProcessing, setChapterSettingsProcessing] = useState(false);
    const [chapterSettingsErrors, setChapterSettingsErrors] = useState<Record<string, string>>({});

    const chapterSettingsIsDirty =
        chapterSettings.title !== chapterSettingsOriginal.title ||
        chapterSettings.slug !== chapterSettingsOriginal.slug ||
        chapterSettings.is_published !== chapterSettingsOriginal.is_published;

    const sectionSettingsForm = useForm<{
        slug: string;
        is_published: boolean;
        task_submission: Section['task_submission'];
    }>({
        slug: activeSection?.slug ?? '',
        is_published: activeSection?.is_published ?? false,
        task_submission: activeSection?.task_submission ?? null,
    });

    // --- Section editing state -------------------------------------------
    // The section body is deliberately not a useForm: the editor is the source
    // of truth for the content and we compare against the last persisted
    // snapshot, which keeps "is there anything to save?" unambiguous.
    const editorRef = useRef<RichTextEditorHandle | null>(null);
    const activeSectionIdRef = useRef(activeSection?.id);

    const [sectionTitle, setSectionTitle] = useState(activeSection?.title ?? '');
    const sectionTitleRef = useRef(sectionTitle);
    const sectionContentRef = useRef<EditorContent | null>(
        activeSection?.content ?? null,
    );
    const savedSnapshotRef = useRef(
        snapshot(activeSection?.title ?? '', activeSection?.content ?? null),
    );
    const [isSectionDirty, setIsSectionDirty] = useState(false);
    const [sectionSaveError, setSectionSaveError] = useState<string | null>(null);

    // Counts edits rather than describing them. isDirty stays true while
    // someone keeps typing, so the autosave needs something that actually
    // changes to know it should push its timer back.
    const [changeCount, setChangeCount] = useState(0);

    const recomputeSectionDirty = useCallback(() => {
        setIsSectionDirty(
            snapshot(sectionTitleRef.current, sectionContentRef.current) !== savedSnapshotRef.current,
        );
        setChangeCount((count) => count + 1);
    }, []);

    const updateSectionTitle = useCallback((value: string) => {
        sectionTitleRef.current = value;
        setSectionTitle(value);
        recomputeSectionDirty();
    }, [recomputeSectionDirty]);

    useEffect(() => {
        activeSectionIdRef.current = activeSection?.id;

        const title = activeSection?.title ?? '';
        const content = activeSection?.content ?? null;

        sectionTitleRef.current = title;
        sectionContentRef.current = content;
        savedSnapshotRef.current = snapshot(title, content);
        setSectionTitle(title);
        setIsSectionDirty(false);
        setSectionSaveError(null);

        // Auto-select chapter when section changes
        if (activeSection) {
            const chapter = topic.chapters.find((ch) =>
                ch.sections.some((s) => s.id === activeSection.id)
            );
            if (chapter) {
                setSelectedChapterId(chapter.id);
            }
        }
    }, [activeSection?.id]);

    // Reset chapter settings when selected chapter changes
    useEffect(() => {
        const chapter = topic.chapters.find(ch => ch.id === selectedChapterId);
        if (chapter) {
            const newSettings = {
                title: chapter.title,
                slug: chapter.slug,
                is_published: chapter.is_published,
            };
            setChapterSettings(newSettings);
            setChapterSettingsOriginal(newSettings);
            setChapterSettingsErrors({});
        } else {
            // Reset to empty if no chapter selected
            const emptySettings = {
                title: '',
                slug: '',
                is_published: false,
            };
            setChapterSettings(emptySettings);
            setChapterSettingsOriginal(emptySettings);
            setChapterSettingsErrors({});
        }
    }, [selectedChapterId, topic.chapters]);

    // Reset section settings form when active section changes
    useEffect(() => {
        if (activeSection) {
            sectionSettingsForm.setDefaults({
                slug: activeSection.slug,
                is_published: activeSection.is_published,
                task_submission: activeSection.task_submission ?? null,
            });
            sectionSettingsForm.reset();
        }
    }, [activeSection?.id]);

    const handleEditorChange = useCallback((value: EditorContent) => {
        // Ignore a late change event that belongs to the section we just left.
        if (activeSectionIdRef.current !== activeSection?.id) {
            return;
        }

        sectionContentRef.current = value;
        recomputeSectionDirty();
    }, [activeSection?.id, recomputeSectionDirty]);

    const saveSection = useCallback(async (): Promise<void> => {
        const section = activeSection;
        if (!section) {
            return;
        }

        // Read straight from Editor.js so a save never lags behind the caret.
        const latest = await editorRef.current?.save();
        if (latest) {
            sectionContentRef.current = latest;
        }

        // The user may have switched sections while we were serialising.
        if (activeSectionIdRef.current !== section.id) {
            return;
        }

        const payload = {
            title: sectionTitleRef.current,
            content: sectionContentRef.current,
        };
        const pending = snapshot(payload.title, payload.content);

        // Nothing actually changed (e.g. the user undid their edit). Clear the
        // dirty flag here, otherwise the autosave effect would keep rescheduling.
        if (pending === savedSnapshotRef.current) {
            recomputeSectionDirty();
            return;
        }

        await new Promise<void>((resolve, reject) => {
            router.patch(`/admin/sections/${section.id}`, payload as unknown as Record<string, never>, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onSuccess: () => {
                    savedSnapshotRef.current = pending;
                    setSectionSaveError(null);
                    recomputeSectionDirty();
                    resolve();
                },
                onError: (errors) => {
                    setSectionSaveError(Object.values(errors)[0] ?? 'Speichern fehlgeschlagen.');
                    reject(new Error('save failed'));
                },
            });
        });
    }, [activeSection, recomputeSectionDirty]);

    const autosave = useAutosave({
        isDirty: isSectionDirty,
        save: saveSection,
        enabled: Boolean(activeSection),
        changeKey: changeCount,
    });

    // Cmd/Ctrl+S saves immediately instead of triggering the browser dialog.
    const { saveNow } = autosave;

    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                void saveNow();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [saveNow]);

    // The section body autosaves; only the manually-saved panels can still hold
    // work that a reload would throw away.
    const hasUnsavedPanelChanges = topicForm.isDirty || chapterSettingsIsDirty || sectionSettingsForm.isDirty;

    useEffect(() => {
        const stillWorking = hasUnsavedPanelChanges || isSectionDirty;
        if (!stillWorking) {
            return;
        }

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => window.removeEventListener('beforeunload', handleBeforeUnload);
    }, [hasUnsavedPanelChanges, isSectionDirty]);

    /** Flushes the current section, then runs the navigation. */
    const leaveSection = useCallback(async (go: () => void) => {
        if (isSectionDirty) {
            try {
                await autosave.saveNow();
            } catch {
                if (!confirm('Der Abschnitt konnte nicht gespeichert werden. Trotzdem fortfahren?')) {
                    return;
                }
            }
        }

        if (hasUnsavedPanelChanges && !confirm('In den Einstellungen gibt es ungespeicherte Änderungen. Trotzdem fortfahren?')) {
            return;
        }

        go();
    }, [isSectionDirty, hasUnsavedPanelChanges, autosave]);

    const handleTopicSave = () => {
        topicForm.put(`/admin/topics/${topic.id}`, { preserveScroll: true });
    };

    const handleSectionSave = () => {
        void autosave.saveNow();
    };

    const handleBackClick = () => {
        void leaveSection(() => router.visit('/admin/topics'));
    };

    const handleChapterSettingsSave = () => {
        if (!selectedChapter) return;

        setChapterSettingsProcessing(true);
        setChapterSettingsErrors({});

        router.patch(`/admin/chapters/${selectedChapter.id}`, chapterSettings, {
            preserveScroll: true,
            onSuccess: () => {
                // Update the original values to the new saved values
                setChapterSettingsOriginal({ ...chapterSettings });
            },
            onError: (errors) => {
                setChapterSettingsErrors(errors as Record<string, string>);
            },
            onFinish: () => {
                setChapterSettingsProcessing(false);
            },
        });
    };

    const handleSectionSettingsSave = () => {
        if (!activeSection) return;

        sectionSettingsForm.patch(`/admin/sections/${activeSection.id}`, {
            preserveScroll: true,
        });
    };

    const handleNavigateToSection = (sectionId: number) => {
        if (sectionId === activeSection?.id) {
            return;
        }

        void leaveSection(() => {
            router.visit(`/admin/topics/${topic.id}/edit/${sectionId}`, {
                preserveScroll: true,
            });
        });
    };

    const handleCreateChapter = () => {
        setDialogType('chapter');
        setDialogChapterId(undefined);
        setDialogOpen(true);
    };

    const handleDeleteChapter = (chapterId: number) => {
        if (!confirm('Dieses Kapitel und alle Abschnitte wirklich löschen?')) {
            return;
        }
        router.delete(`/admin/chapters/${chapterId}`, { preserveScroll: true });
    };

    const handleCreateSection = (chapterId: number) => {
        setDialogType('section');
        setDialogChapterId(chapterId);
        setDialogOpen(true);
    };

    const handleDeleteSection = (sectionId: number) => {
        if (!confirm('Diesen Abschnitt wirklich löschen?')) {
            return;
        }
        router.delete(`/admin/sections/${sectionId}`, { preserveScroll: true });
    };

    const totalSections = topic.chapters.reduce((acc, ch) => acc + ch.sections.length, 0);

    // Chapter editing state
    const [editingChapterId, setEditingChapterId] = useState<number | null>(null);
    const [editingChapterTitle, setEditingChapterTitle] = useState('');

    const handleSaveChapterTitle = (chapterId: number) => {
        if (editingChapterTitle.trim()) {
            router.patch(`/admin/chapters/${chapterId}`, { title: editingChapterTitle }, { preserveScroll: true });
        }
        setEditingChapterId(null);
        setEditingChapterTitle('');
    };

    const handleCancelEditChapter = () => {
        setEditingChapterId(null);
        setEditingChapterTitle('');
    };

    return (
        <>
            <Head title={`Bearbeiten: ${topic.title}`} />

            {/* Gutenberg-style Full-screen Editor */}
            <div className="flex h-screen flex-col bg-white">
                {/* Top Bar */}
                <header className="flex h-14 shrink-0 items-center justify-between border-b border-zinc-100 bg-white px-4">
                    <div className="flex items-center gap-3">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 text-zinc-500 hover:text-zinc-900"
                            onClick={handleBackClick}
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Button>

                        <div className="hidden h-6 w-px bg-zinc-200 sm:block" />

                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 text-zinc-500 hover:text-zinc-900"
                            onClick={() => setSidebarOpen(!sidebarOpen)}
                        >
                            {sidebarOpen ? (
                                <PanelLeftClose className="h-5 w-5" />
                            ) : (
                                <PanelLeftOpen className="h-5 w-5" />
                            )}
                        </Button>

                        <div className="hidden items-center gap-2 sm:flex">
                            <span className="text-sm font-medium text-zinc-900 truncate max-w-[200px]">
                                {topic.title}
                            </span>
                            {activeSection && (
                                <>
                                    <ChevronRight className="h-4 w-4 text-zinc-300" />
                                    <span className="text-sm text-zinc-500 truncate max-w-[180px]">
                                        {activeSection.title}
                                    </span>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <SaveIndicator
                            status={autosave.status}
                            lastSavedAt={autosave.lastSavedAt}
                            errorMessage={sectionSaveError}
                        />

                        <TopicHistory topicId={topic.id} />

                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 text-zinc-500 hover:text-zinc-900"
                            onClick={() => setSettingsOpen(!settingsOpen)}
                        >
                            <Settings className="h-5 w-5" />
                        </Button>

                        <Button
                            onClick={handleSectionSave}
                            disabled={autosave.status === 'saving' || !activeSection}
                            title="Speichern (⌘/Strg + S)"
                            className="h-9 bg-zinc-900 px-4 text-sm font-medium text-white hover:bg-zinc-800"
                        >
                            <Save className="mr-2 h-4 w-4" />
                            {autosave.status === 'saving' ? 'Speichern…' : 'Speichern'}
                        </Button>
                    </div>
                </header>

                {/* Main Content Area */}
                <div className="flex flex-1 overflow-hidden">
                    {/* Left Sidebar - Document Structure */}
                    <aside
                        className={cn(
                            'flex h-full w-72 shrink-0 flex-col border-r border-zinc-100 bg-zinc-50/50 transition-all duration-200',
                            sidebarOpen ? 'translate-x-0' : '-translate-x-full absolute lg:relative lg:-ml-72',
                        )}
                    >
                        <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-3">
                            <h2 className="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                                Kursstruktur
                            </h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 gap-1.5 text-xs font-medium text-zinc-600 hover:text-zinc-900"
                                onClick={handleCreateChapter}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Kapitel
                            </Button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-3">
                            {topic.chapters.length > 0 ? (
                                <SortableCourseStructure
                                    topicId={topic.id}
                                    chapters={topic.chapters}
                                    activeSection={activeSection}
                                    expandedChapters={expandedChapters}
                                    editingChapterId={editingChapterId}
                                    editingChapterTitle={editingChapterTitle}
                                    onToggleChapter={toggleChapter}
                                    onNavigateToSection={handleNavigateToSection}
                                    onCreateChapter={handleCreateChapter}
                                    onCreateSection={handleCreateSection}
                                    onChapterClick={(chapterId) => {
                                        setSelectedChapterId(chapterId);
                                        setSettingsTab('chapter');
                                        setSettingsOpen(true);
                                    }}
                                    onEditChapterTitle={(chapterId, title) => {
                                        setEditingChapterId(chapterId);
                                        setEditingChapterTitle(title);
                                    }}
                                    onSaveChapterTitle={handleSaveChapterTitle}
                                    onCancelEditChapter={handleCancelEditChapter}
                                    setEditingChapterTitle={setEditingChapterTitle}
                                />
                            ) : (
                                <button
                                    type="button"
                                    onClick={handleCreateChapter}
                                    className="flex w-full flex-col items-center gap-2 rounded-xl border-2 border-dashed border-zinc-200 px-4 py-8 text-zinc-400 transition-colors hover:border-zinc-300 hover:text-zinc-500"
                                >
                                    <FolderOpen className="h-8 w-8" />
                                    <span className="text-sm font-medium">Erstes Kapitel anlegen</span>
                                </button>
                            )}
                        </div>
                    </aside>

                    {/* Editor Area */}
                    <main className="flex flex-1 flex-col overflow-hidden bg-white">
                        {activeSection ? (
                            <>
                                {/* Section Title */}
                                <div className="shrink-0 border-b border-zinc-100 py-6 pl-16 pr-8 lg:pl-20 lg:pr-12">
                                    <input
                                        type="text"
                                        value={sectionTitle}
                                        onChange={(event) => updateSectionTitle(event.target.value)}
                                        placeholder="Titel eingeben…"
                                        className="w-full border-none bg-transparent text-3xl font-bold text-zinc-900 placeholder:text-zinc-300 focus:outline-none focus:ring-0"
                                    />
                                </div>

                                {/* Editor */}
                                <div className="flex-1 overflow-y-auto py-6 pl-16 pr-8 lg:pl-20 lg:pr-12">
                                    <RichTextEditor
                                        key={activeSection.id}
                                        ref={editorRef}
                                        initialValue={activeSection.content ?? undefined}
                                        onChange={handleEditorChange}
                                        className="gutenberg-editor min-h-[60vh] border-0 bg-transparent shadow-none"
                                        placeholder="Beginne zu schreiben oder drücke / für Blöcke…"
                                    />
                                </div>
                            </>
                        ) : (
                            <div className="flex flex-1 flex-col items-center justify-center gap-4 text-zinc-400">
                                <FileText className="h-16 w-16 text-zinc-200" />
                                <div className="text-center">
                                    <p className="text-lg font-medium text-zinc-500">Kein Abschnitt ausgewählt</p>
                                    <p className="mt-1 text-sm">
                                        Wähle einen Abschnitt aus der Seitenleiste oder erstelle einen neuen.
                                    </p>
                                </div>
                            </div>
                        )}
                    </main>

                    {/* Right Sidebar - Settings */}
                    <aside
                        className={cn(
                            'flex h-full w-80 shrink-0 flex-col border-l border-zinc-100 bg-white transition-all duration-200',
                            settingsOpen ? 'translate-x-0' : 'translate-x-full absolute right-0 lg:relative lg:mr-0 lg:hidden',
                        )}
                    >
                        <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-3">
                            <h2 className="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                                Einstellungen
                            </h2>
                            <button
                                type="button"
                                onClick={() => setSettingsOpen(false)}
                                className="flex h-6 w-6 items-center justify-center rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 lg:hidden"
                            >
                                ×
                            </button>
                        </div>

                        {/* Tabs */}
                        <div className="flex border-b border-zinc-200">
                            <button
                                type="button"
                                onClick={() => setSettingsTab('topic')}
                                className={cn(
                                    'flex-1 px-4 py-2 text-xs font-medium transition-colors',
                                    settingsTab === 'topic'
                                        ? 'border-b-2 border-zinc-900 text-zinc-900'
                                        : 'text-zinc-500 hover:text-zinc-700'
                                )}
                            >
                                Thema
                            </button>
                            <button
                                type="button"
                                onClick={() => setSettingsTab('chapter')}
                                className={cn(
                                    'flex-1 px-4 py-2 text-xs font-medium transition-colors',
                                    settingsTab === 'chapter'
                                        ? 'border-b-2 border-zinc-900 text-zinc-900'
                                        : 'text-zinc-500 hover:text-zinc-700'
                                )}
                            >
                                Kapitel
                            </button>
                            <button
                                type="button"
                                onClick={() => setSettingsTab('section')}
                                className={cn(
                                    'flex-1 px-4 py-2 text-xs font-medium transition-colors',
                                    settingsTab === 'section'
                                        ? 'border-b-2 border-zinc-900 text-zinc-900'
                                        : 'text-zinc-500 hover:text-zinc-700'
                                )}
                            >
                                Seite
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-4">
                            {/* Topic Settings */}
                            {settingsTab === 'topic' && (
                                <div className="space-y-4">
                                    <div className="space-y-2">
                                        <Label className="text-xs font-semibold">Titel</Label>
                                        <Input
                                            value={topicForm.data.title}
                                            onChange={(e) => topicForm.setData('title', e.target.value)}
                                            onBlur={handleTopicSave}
                                            className="h-9 text-sm"
                                        />
                                        <InputError message={topicForm.errors.title} />
                                    </div>

                                    <div className="space-y-2">
                                        <Label className="text-xs font-semibold">Slug</Label>
                                        <Input
                                            value={topicForm.data.slug}
                                            onChange={(e) => topicForm.setData('slug', e.target.value)}
                                            onBlur={handleTopicSave}
                                            className="h-9 text-sm font-mono"
                                        />
                                        <InputError message={topicForm.errors.slug} />
                                        <p className="text-xs text-muted-foreground">
                                            URL-freundliche Version des Titels
                                        </p>
                                    </div>

                                    <div className="space-y-2">
                                        <Label className="text-xs font-semibold">Kategorie</Label>
                                        <Select
                                            value={String(topicForm.data.category_id)}
                                            onValueChange={(value) => {
                                                topicForm.setData('category_id', parseInt(value));
                                            }}
                                        >
                                            <SelectTrigger className="h-9 text-sm">
                                                <SelectValue placeholder="Kategorie wählen" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {categories.map((category) => (
                                                    <SelectItem key={category.id} value={String(category.id)}>
                                                        {category.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={topicForm.errors.category_id} />
                                    </div>

                                    {topicForm.isDirty && (
                                        <Button
                                            onClick={handleTopicSave}
                                            disabled={topicForm.processing}
                                            className="w-full h-9 text-sm"
                                        >
                                            {topicForm.processing ? 'Speichern...' : 'Änderungen speichern'}
                                        </Button>
                                    )}

                                    <div className="rounded-lg bg-zinc-50 p-3">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-zinc-500">Kapitel</span>
                                            <span className="font-medium text-zinc-900">{topic.chapters.length}</span>
                                        </div>
                                        <div className="mt-2 flex items-center justify-between text-sm">
                                            <span className="text-zinc-500">Seiten</span>
                                            <span className="font-medium text-zinc-900">{totalSections}</span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Chapter Settings */}
                            {settingsTab === 'chapter' && (() => {
                                if (!selectedChapter) {
                                    return (
                                        <div className="flex flex-col items-center justify-center py-8 text-center">
                                            <FolderOpen className="h-12 w-12 text-zinc-300 mb-3" />
                                            <p className="text-sm text-zinc-500">Kein Kapitel ausgewählt</p>
                                            <p className="text-xs text-zinc-400 mt-1">Wähle ein Kapitel aus der Seitenleiste</p>
                                        </div>
                                    );
                                }
                                return (
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label className="text-xs font-semibold">Kapitel-Titel</Label>
                                            <Input
                                                value={chapterSettings.title}
                                                onChange={(e) => setChapterSettings({ ...chapterSettings, title: e.target.value })}
                                                className="h-9 text-sm"
                                            />
                                            <InputError message={chapterSettingsErrors.title} />
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="text-xs font-semibold">Slug</Label>
                                            <Input
                                                value={chapterSettings.slug}
                                                onChange={(e) => setChapterSettings({ ...chapterSettings, slug: e.target.value })}
                                                className="h-9 text-sm font-mono"
                                            />
                                            <InputError message={chapterSettingsErrors.slug} />
                                            <p className="text-xs text-muted-foreground">
                                                URL-freundliche Version des Titels
                                            </p>
                                        </div>

                                        <div className="flex items-center space-x-2">
                                            <Checkbox
                                                id={`chapter-published-${selectedChapter.id}`}
                                                checked={chapterSettings.is_published}
                                                onCheckedChange={(checked) => setChapterSettings({ ...chapterSettings, is_published: checked === true })}
                                            />
                                            <Label
                                                htmlFor={`chapter-published-${selectedChapter.id}`}
                                                className="text-sm font-normal cursor-pointer"
                                            >
                                                Veröffentlicht
                                            </Label>
                                        </div>

                                        {chapterSettingsIsDirty && (
                                            <Button
                                                onClick={handleChapterSettingsSave}
                                                disabled={chapterSettingsProcessing}
                                                className="w-full h-9 text-sm"
                                            >
                                                {chapterSettingsProcessing ? 'Speichern...' : 'Änderungen speichern'}
                                            </Button>
                                        )}

                                        {/* Embed Link Section */}
                                        <div className="pt-4 border-t">
                                            <div className="flex items-center gap-2 mb-3">
                                                <Code className="h-4 w-4 text-zinc-500" />
                                                <Label className="text-xs font-semibold">Embed Code</Label>
                                            </div>
                                            <div className="space-y-2">
                                                <div className="relative">
                                                    <textarea
                                                        readOnly
                                                        value={`<iframe src="${window.location.origin}/embed/chapter/${selectedChapter.id}" width="100%" height="700" style="border: 1px solid #e4e4e7; border-radius: 8px;" allowfullscreen></iframe>`}
                                                        className="w-full h-20 p-3 text-xs font-mono bg-zinc-50 border border-zinc-200 rounded-lg resize-none focus:outline-none focus:ring-2 focus:ring-zinc-900"
                                                    />
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="absolute top-2 right-2 h-7 text-xs"
                                                        onClick={() => {
                                                            navigator.clipboard.writeText(`<iframe src="${window.location.origin}/embed/chapter/${selectedChapter.id}" width="100%" height="700" style="border: 1px solid #e4e4e7; border-radius: 8px;" allowfullscreen></iframe>`);
                                                        }}
                                                    >
                                                        <Copy className="h-3 w-3 mr-1" />
                                                        Kopieren
                                                    </Button>
                                                </div>
                                                <p className="text-xs text-zinc-500">
                                                    Füge diesen Code in Moodle (HTML-Modus) ein.
                                                </p>
                                            </div>
                                        </div>

                                        <div className="pt-4 border-t">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleDeleteChapter(selectedChapter.id)}
                                                className="w-full text-red-600 hover:text-red-700 hover:bg-red-50"
                                            >
                                                <Trash2 className="h-4 w-4 mr-2" />
                                                Kapitel löschen
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })()}

                            {/* Section Settings */}
                            {settingsTab === 'section' && (
                                activeSection ? (
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label className="text-xs font-semibold">Seiten-Titel</Label>
                                            <Input
                                                value={sectionTitle}
                                                onChange={(e) => updateSectionTitle(e.target.value)}
                                                className="h-9 text-sm"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Wird automatisch gespeichert
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="text-xs font-semibold">Slug</Label>
                                            <Input
                                                value={sectionSettingsForm.data.slug}
                                                onChange={(e) => sectionSettingsForm.setData('slug', e.target.value)}
                                                className="h-9 text-sm font-mono"
                                            />
                                            <InputError message={sectionSettingsForm.errors.slug} />
                                            <p className="text-xs text-muted-foreground">
                                                URL-freundliche Version des Titels
                                            </p>
                                        </div>

                                        <div className="flex items-center space-x-2">
                                            <Checkbox
                                                id={`section-published-${activeSection.id}`}
                                                checked={sectionSettingsForm.data.is_published}
                                                onCheckedChange={(checked) => sectionSettingsForm.setData('is_published', checked === true)}
                                            />
                                            <Label
                                                htmlFor={`section-published-${activeSection.id}`}
                                                className="text-sm font-normal cursor-pointer"
                                            >
                                                Veröffentlicht
                                            </Label>
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="text-xs font-semibold">Art der Seite</Label>
                                            {/* Was in Moodle daraus wird, hängt allein hieran. */}
                                            <div className="grid grid-cols-2 gap-2">
                                                {[
                                                    { kind: 'page' as const, label: 'Theorie', icon: FileText },
                                                    { kind: 'task' as const, label: 'Aufgabe', icon: ClipboardList },
                                                ].map(({ kind, label, icon: Icon }) => {
                                                    const active = kind === 'task'
                                                        ? !!sectionSettingsForm.data.task_submission
                                                        : !sectionSettingsForm.data.task_submission;

                                                    return (
                                                        <button
                                                            key={kind}
                                                            type="button"
                                                            onClick={() => sectionSettingsForm.setData(
                                                                'task_submission',
                                                                kind === 'task' ? (sectionSettingsForm.data.task_submission ?? 'none') : null,
                                                            )}
                                                            className={cn(
                                                                'flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                                                active
                                                                    ? 'border-zinc-900 bg-zinc-900 text-white'
                                                                    : 'border-zinc-200 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50',
                                                            )}
                                                        >
                                                            <Icon className="h-4 w-4" />
                                                            {label}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>

                                        {!!sectionSettingsForm.data.task_submission && (
                                            <div className="space-y-2">
                                                <Label className="text-xs font-semibold">Abgabe</Label>
                                                <Select
                                                    value={sectionSettingsForm.data.task_submission}
                                                    onValueChange={(value) => sectionSettingsForm.setData(
                                                        'task_submission',
                                                        value as Section['task_submission'],
                                                    )}
                                                >
                                                    <SelectTrigger className="h-9 text-sm">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">Keine Abgabe</SelectItem>
                                                        <SelectItem value="file">Datei</SelectItem>
                                                        <SelectItem value="text">Text</SelectItem>
                                                        <SelectItem value="both">Datei oder Text</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}

                                        <p className="text-xs text-muted-foreground">
                                            {!sectionSettingsForm.data.task_submission
                                                ? 'In Moodle: eine Textseite, im Kapitel-Buch ein Buchkapitel.'
                                                : `In Moodle: eine Aufgabe${{
                                                    none: ' ohne Abgabe',
                                                    file: ', Abgabe als Datei',
                                                    text: ', Abgabe als Text',
                                                    both: ', Abgabe als Datei oder Text',
                                                }[sectionSettingsForm.data.task_submission]}. Bewertet wird in Moodle.`}
                                        </p>

                                        {sectionSettingsForm.isDirty && (
                                            <Button
                                                onClick={handleSectionSettingsSave}
                                                disabled={sectionSettingsForm.processing}
                                                className="w-full h-9 text-sm"
                                            >
                                                {sectionSettingsForm.processing ? 'Speichern...' : 'Änderungen speichern'}
                                            </Button>
                                        )}

                                        {/* Embed Link Section */}
                                        <div className="pt-4 border-t">
                                            <div className="flex items-center gap-2 mb-3">
                                                <Code className="h-4 w-4 text-zinc-500" />
                                                <Label className="text-xs font-semibold">Embed Code</Label>
                                            </div>
                                            <div className="space-y-2">
                                                <div className="relative">
                                                    <textarea
                                                        readOnly
                                                        value={`<iframe src="${window.location.origin}/embed/section/${activeSection.id}" width="100%" height="700" style="border: 1px solid #e4e4e7; border-radius: 8px;" allowfullscreen></iframe>`}
                                                        className="w-full h-20 p-3 text-xs font-mono bg-zinc-50 border border-zinc-200 rounded-lg resize-none focus:outline-none focus:ring-2 focus:ring-zinc-900"
                                                    />
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="absolute top-2 right-2 h-7 text-xs"
                                                        onClick={() => {
                                                            navigator.clipboard.writeText(`<iframe src="${window.location.origin}/embed/section/${activeSection.id}" width="100%" height="700" style="border: 1px solid #e4e4e7; border-radius: 8px;" allowfullscreen></iframe>`);
                                                        }}
                                                    >
                                                        <Copy className="h-3 w-3 mr-1" />
                                                        Kopieren
                                                    </Button>
                                                </div>
                                                <p className="text-xs text-zinc-500">
                                                    Füge diesen Code in Moodle (HTML-Modus) ein.
                                                </p>
                                            </div>
                                        </div>

                                        <div className="pt-4 border-t">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleDeleteSection(activeSection.id)}
                                                className="w-full text-red-600 hover:text-red-700 hover:bg-red-50"
                                            >
                                                <Trash2 className="h-4 w-4 mr-2" />
                                                Seite löschen
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-8 text-center">
                                        <FileText className="h-12 w-12 text-zinc-300 mb-3" />
                                        <p className="text-sm text-zinc-500">Keine Seite ausgewählt</p>
                                        <p className="text-xs text-zinc-400 mt-1">Wähle eine Seite aus der Seitenleiste</p>
                                    </div>
                                )
                            )}
                        </div>
                    </aside>
                </div>
            </div>

            {/* Dialog for creating chapters/sections */}
            <CreateItemDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                type={dialogType}
                topicId={topic.id}
                chapterId={dialogChapterId}
            />
        </>
    );
}
