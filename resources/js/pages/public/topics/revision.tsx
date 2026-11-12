import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { type Category } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';

interface PublicTopicRevisionProps {
    topic: {
        id: number;
        slug: string;
        title: string;
        category: Pick<Category, 'id' | 'name'> | null;
    };
}

/** Ein Kurs in Überarbeitung: Titel und Hinweis statt Inhalt. */
export default function PublicTopicRevision({ topic }: PublicTopicRevisionProps) {
    return (
        <PublicLayout>
            <Head title={`${topic.title} – In Überarbeitung`} />

            <div className="mx-auto max-w-xl rounded-2xl border border-amber-200 bg-amber-50 px-6 py-12 text-center">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <Lock className="h-7 w-7" aria-hidden="true" />
                </div>
                <p className="mt-6 text-xs font-semibold uppercase tracking-wider text-amber-800">In Überarbeitung</p>
                <h1 className="mt-2 text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">{topic.title}</h1>
                <p className="mt-3 text-sm text-zinc-600">
                    Dieser Kurs wird gerade überarbeitet und ist bald wieder verfügbar.
                </p>
                <Button asChild variant="outline" className="mt-8">
                    <Link href="/themen">Alle Themen anzeigen</Link>
                </Button>
            </div>
        </PublicLayout>
    );
}
