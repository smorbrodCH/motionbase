import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { type Category, type Topic } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, BookOpen, FolderOpen, Lock, User } from 'lucide-react';

interface PublicTopicsIndexProps {
    topics: Topic[];
    categories: Pick<Category, 'id' | 'name'>[];
    filters: {
        category?: string | number | null;
    };
}

export default function PublicTopicsIndex({ topics, categories, filters }: PublicTopicsIndexProps) {
    const handleFilterChange = (categoryId: string) => {
        router.visit(categoryId ? `/themen?category=${categoryId}` : '/themen', {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <PublicLayout
            headline="Themen entdecken"
            description="Durchstöbere unsere Kurse und finde genau das Wissen, das du suchst."
        >
            <Head title="Themen" />

            {/* Category Filter */}
            <div className="mb-8 flex flex-wrap gap-2">
                <button
                    onClick={() => handleFilterChange('')}
                    className={`rounded-full px-4 py-2 text-sm font-medium transition-all ${
                        !filters.category
                            ? 'bg-zinc-900 text-white'
                            : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900'
                    }`}
                >
                    Alle Themen
                </button>
                {categories.map((category) => (
                    <button
                        key={category.id}
                        onClick={() => handleFilterChange(String(category.id))}
                        className={`rounded-full px-4 py-2 text-sm font-medium transition-all ${
                            String(filters.category) === String(category.id)
                                ? 'bg-zinc-900 text-white'
                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900'
                        }`}
                    >
                        {category.name}
                    </button>
                ))}
            </div>

            {/* Topics Grid */}
            {topics.length === 0 ? (
                <div className="rounded-2xl border-2 border-dashed border-zinc-200 py-20 text-center">
                    <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100">
                        <BookOpen className="h-8 w-8 text-zinc-400" />
                    </div>
                    <h3 className="mt-6 text-lg font-semibold text-zinc-900">
                        Keine Themen gefunden
                    </h3>
                    <p className="mt-2 text-sm text-zinc-500 max-w-sm mx-auto">
                        Aktuell sind keine Themen in dieser Kategorie verfügbar.
                    </p>
                    {filters.category && (
                        <Button
                            variant="outline"
                            className="mt-6"
                            onClick={() => handleFilterChange('')}
                        >
                            Alle Themen anzeigen
                        </Button>
                    )}
                </div>
            ) : (
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {topics.map((topic) => (
                        <TopicCard key={topic.id} topic={topic} />
                    ))}
                </div>
            )}
        </PublicLayout>
    );
}

function TopicCard({ topic }: { topic: Topic }) {
    if (topic.in_revision) {
        return <RevisionCard topic={topic} />;
    }

    return (
        <Link
            href={`/themen/${topic.slug}`}
            className="group flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg"
        >
            {/* Header */}
            <div className="flex items-start justify-between gap-4">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-zinc-100 transition-colors group-hover:bg-zinc-900 group-hover:text-white">
                    <BookOpen className="h-6 w-6" />
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    {topic.category && (
                        <Badge
                            variant="secondary"
                            className="bg-zinc-100 text-xs font-medium text-zinc-600"
                        >
                            {topic.category.name}
                        </Badge>
                    )}
                    <Badge
                        variant="outline"
                        className="border-emerald-200 bg-emerald-50 text-xs font-medium text-emerald-800"
                    >
                        Veröffentlicht
                    </Badge>
                </div>
            </div>

            {/* Content */}
            <div className="mt-5 flex-1">
                <h3 className="text-lg font-semibold text-zinc-900 group-hover:text-zinc-700">
                    {topic.title}
                </h3>
                {topic.excerpt && (
                    <p className="mt-2 line-clamp-2 text-sm text-zinc-500">
                        {topic.excerpt}
                    </p>
                )}
            </div>

            {/* Footer */}
            <div className="mt-6 flex items-center justify-between border-t border-zinc-100 pt-4">
                <div className="flex items-center gap-4 text-xs text-zinc-400">
                    {topic.chapters_count !== undefined && (
                        <span className="flex items-center gap-1">
                            <FolderOpen className="h-3.5 w-3.5" />
                            {topic.chapters_count} Kapitel
                        </span>
                    )}
                    {topic.author && (
                        <span className="flex items-center gap-1">
                            <User className="h-3.5 w-3.5" />
                            {topic.author.name}
                        </span>
                    )}
                </div>
                <span className="flex items-center gap-1 text-sm font-medium text-zinc-900 opacity-0 transition-opacity group-hover:opacity-100">
                    Öffnen
                    <ArrowRight className="h-4 w-4" />
                </span>
            </div>
        </Link>
    );
}

/** Ein Kurs in Überarbeitung: sichtbar, aber nicht zu öffnen. */
function RevisionCard({ topic }: { topic: Topic }) {
    return (
        <div className="flex flex-col rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-6"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-400">
                    <Lock className="h-6 w-6" aria-hidden="true" />
                </div>
                <Badge className="border-amber-200 bg-amber-50 text-xs font-medium text-amber-800" variant="outline">
                    In Überarbeitung
                </Badge>
            </div>

            <div className="mt-5 flex-1">
                <h3 className="text-lg font-semibold text-zinc-500">{topic.title}</h3>
                <p className="mt-2 text-sm text-zinc-500">
                    Dieser Kurs wird gerade überarbeitet und ist bald wieder verfügbar.
                </p>
            </div>

            {topic.category && (
                <div className="mt-6 border-t border-zinc-200 pt-4 text-xs text-zinc-500">{topic.category.name}</div>
            )}
        </div>
    );
}
