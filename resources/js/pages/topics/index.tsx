import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Category, type Topic } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    BookOpen,
    Calendar,
    Filter,
    FolderOpen,
    MoreHorizontal,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { type MouseEvent, useMemo, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Themen',
        href: '/admin/topics',
    },
];

export default function TopicsIndex({
    topics,
    categories,
}: {
    topics: Topic[];
    categories: Pick<Category, 'id' | 'name'>[];
}) {
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedCategory, setSelectedCategory] = useState<number | null>(null);

    const filteredTopics = useMemo(() => {
        return topics.filter((topic) => {
            const matchesSearch = topic.title.toLowerCase().includes(searchQuery.toLowerCase());
            const matchesCategory = selectedCategory === null || topic.category?.id === selectedCategory;
            return matchesSearch && matchesCategory;
        });
    }, [topics, searchQuery, selectedCategory]);

    const topicCountByCategory = useMemo(() => {
        return topics.reduce<Record<number, number>>((acc, topic) => {
            if (topic.category?.id) {
                acc[topic.category.id] = (acc[topic.category.id] ?? 0) + 1;
            }
            return acc;
        }, {});
    }, [topics]);

    const handleDelete = (topicId: number) => {
        if (!confirm('Dieses Thema wirklich löschen?')) {
            return;
        }
        router.delete(`/admin/topics/${topicId}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Themen" />

            <div className="min-h-[calc(100vh-80px)] bg-zinc-50/50">
                {/* Header */}
                <div className="border-b border-zinc-100 bg-white px-6 py-8 lg:px-10">
                    <div className="mx-auto max-w-6xl">
                        <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-zinc-900">
                                    Themen
                                </h1>
                                <p className="mt-1 text-zinc-500">
                                    Verwalte und organisiere deine Lerninhalte
                                </p>
                            </div>
                            <Button
                                className="h-10 gap-2 bg-zinc-900 px-5 text-white hover:bg-zinc-800"
                                asChild
                            >
                                <Link href="/admin/topics/create">
                                    <Plus className="h-4 w-4" />
                                    Neues Thema
                                </Link>
                            </Button>
                        </div>

                        {/* Search & Filter Bar */}
                        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                                <Input
                                    type="text"
                                    placeholder="Themen durchsuchen..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="h-10 border-zinc-200 bg-zinc-50 pl-10 focus:bg-white"
                                />
                            </div>
                            <div className="flex gap-2">
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            className="h-10 gap-2 border-zinc-200"
                                        >
                                            <Filter className="h-4 w-4" />
                                            {selectedCategory
                                                ? categories.find((c) => c.id === selectedCategory)?.name
                                                : 'Alle Kategorien'}
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-48">
                                        <DropdownMenuItem onClick={() => setSelectedCategory(null)}>
                                            Alle Kategorien
                                        </DropdownMenuItem>
                                        {categories.map((category) => (
                                            <DropdownMenuItem
                                                key={category.id}
                                                onClick={() => setSelectedCategory(category.id)}
                                            >
                                                {category.name}
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-auto bg-zinc-100 text-xs"
                                                >
                                                    {topicCountByCategory[category.id] ?? 0}
                                                </Badge>
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Content */}
                <div className="px-6 py-8 lg:px-10">
                    <div className="mx-auto max-w-6xl">
                        {filteredTopics.length === 0 ? (
                            <div className="rounded-2xl border-2 border-dashed border-zinc-200 bg-white py-16 text-center">
                                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100">
                                    <BookOpen className="h-8 w-8 text-zinc-400" />
                                </div>
                                <h3 className="mt-6 text-lg font-semibold text-zinc-900">
                                    {searchQuery || selectedCategory
                                        ? 'Keine Themen gefunden'
                                        : 'Noch keine Themen'}
                                </h3>
                                <p className="mt-2 text-sm text-zinc-500">
                                    {searchQuery || selectedCategory
                                        ? 'Versuche andere Suchbegriffe oder Filter.'
                                        : 'Erstelle dein erstes Thema, um loszulegen.'}
                                </p>
                                {!searchQuery && !selectedCategory && (
                                    <Button
                                        className="mt-6 bg-zinc-900 text-white hover:bg-zinc-800"
                                        asChild
                                    >
                                        <Link href="/admin/topics/create">
                                            <Plus className="mr-2 h-4 w-4" />
                                            Thema erstellen
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <div className="grid gap-4">
                                {filteredTopics.map((topic) => (
                                    <TopicCard
                                        key={topic.id}
                                        topic={topic}
                                        onDelete={() => handleDelete(topic.id)}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function TopicCard({ topic, onDelete }: { topic: Topic; onDelete: () => void }) {
    return (
        <div className="group rounded-2xl border border-zinc-100 bg-white p-6 shadow-sm transition-all hover:border-zinc-200 hover:shadow-md">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex gap-4">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-zinc-100 transition-colors group-hover:bg-zinc-900 group-hover:text-white">
                        <BookOpen className="h-6 w-6" />
                    </div>
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={`/admin/topics/${topic.id}/edit`}
                                className="text-lg font-semibold text-zinc-900 hover:underline"
                            >
                                {topic.title}
                            </Link>
                            {topic.category && (
                                <Badge
                                    variant="secondary"
                                    className="bg-zinc-100 text-xs font-medium text-zinc-600"
                                >
                                    {topic.category.name}
                                </Badge>
                            )}
                            {topic.is_published === false && (
                                <Badge
                                    variant="outline"
                                    className="text-xs font-medium text-zinc-500"
                                >
                                    Ausgeblendet
                                </Badge>
                            )}
                        </div>
                        <p className="line-clamp-2 text-sm text-zinc-500">
                            {topic.excerpt ?? 'Keine Beschreibung verfügbar.'}
                        </p>
                        <div className="flex flex-wrap items-center gap-4 pt-2 text-xs text-zinc-400">
                            <span className="flex items-center gap-1">
                                <FolderOpen className="h-3.5 w-3.5" />
                                {topic.chapters_count ?? 0} Kapitel
                            </span>
                            <span className="flex items-center gap-1">
                                <BookOpen className="h-3.5 w-3.5" />
                                {topic.sections_count ?? 0} Abschnitte
                            </span>
                            <span className="flex items-center gap-1">
                                <Calendar className="h-3.5 w-3.5" />
                                {formatDate(topic.updated_at)}
                            </span>
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 gap-2 border-zinc-200 text-zinc-700 hover:bg-zinc-50"
                        asChild
                    >
                        <Link href={`/admin/topics/${topic.id}/edit`}>
                            Bearbeiten
                            <ArrowUpRight className="h-4 w-4" />
                        </Link>
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 text-zinc-400 hover:text-zinc-600"
                            >
                                <MoreHorizontal className="h-5 w-5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuItem asChild>
                                <Link href={`/admin/topics/${topic.id}/edit`}>
                                    Bearbeiten
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <a
                                    href={`/themen/${topic.id}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Vorschau
                                    <ArrowUpRight className="ml-auto h-4 w-4" />
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="text-red-600 focus:text-red-600"
                                onClick={(e: MouseEvent) => {
                                    e.preventDefault();
                                    onDelete();
                                }}
                            >
                                <Trash2 className="mr-2 h-4 w-4" />
                                Löschen
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>
    );
}

function formatDate(value?: string) {
    if (!value) {
        return 'unbekannt';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
    }).format(new Date(value));
}
