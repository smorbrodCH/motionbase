import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { ClipboardList, FileText } from 'lucide-react';
import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';

interface CreateItemDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    type: 'chapter' | 'section';
    topicId?: number;
    chapterId?: number;
}

export function CreateItemDialog({ open, onOpenChange, type, topicId, chapterId }: CreateItemDialogProps) {
    const [title, setTitle] = useState('');
    const [slug, setSlug] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [autoSlug, setAutoSlug] = useState(true);
    // Theorie oder Aufgabe: bestimmt, was in Moodle daraus wird.
    const [kind, setKind] = useState<'page' | 'task'>('page');

    // Auto-generate slug from title
    useEffect(() => {
        if (autoSlug && title) {
            const generatedSlug = title
                .toLowerCase()
                .replace(/[äöüß]/g, (match) => {
                    const map: Record<string, string> = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss' };
                    return map[match] || match;
                })
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            setSlug(generatedSlug);
        }
    }, [title, autoSlug]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!title.trim()) return;

        setIsSubmitting(true);

        if (type === 'chapter' && topicId) {
            router.post(`/admin/topics/${topicId}/chapters`, {
                title: title.trim(),
                slug: slug.trim() || undefined,
            }, {
                onSuccess: () => {
                    setTitle('');
                    setSlug('');
                    setAutoSlug(true);
                    onOpenChange(false);
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            });
        } else if (type === 'section' && chapterId) {
            router.post(`/admin/chapters/${chapterId}/sections`, {
                title: title.trim(),
                slug: slug.trim() || undefined,
                kind,
            }, {
                onSuccess: () => {
                    setTitle('');
                    setSlug('');
                    setAutoSlug(true);
                    setKind('page');
                    onOpenChange(false);
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            });
        }
    };

    const handleCancel = () => {
        setTitle('');
        setSlug('');
        setAutoSlug(true);
        setKind('page');
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>
                            {type === 'chapter' ? 'Neues Kapitel' : 'Neue Seite'}
                        </DialogTitle>
                        <DialogDescription>
                            {type === 'chapter'
                                ? 'Gib einen Titel für das neue Kapitel ein.'
                                : 'Theorie oder Aufgabe? Danach richtet sich, was Moodle daraus macht.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        {type === 'section' && (
                            <div className="grid grid-cols-2 gap-2">
                                {[
                                    { value: 'page' as const, label: 'Theorie', hint: 'Wird eine Textseite', icon: FileText },
                                    { value: 'task' as const, label: 'Aufgabe', hint: 'Wird eine Aufgabe', icon: ClipboardList },
                                ].map(({ value, label, hint, icon: Icon }) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => setKind(value)}
                                        aria-pressed={kind === value}
                                        className={cn(
                                            'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors',
                                            kind === value
                                                ? 'border-zinc-900 bg-zinc-50 ring-1 ring-zinc-900'
                                                : 'border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50',
                                        )}
                                    >
                                        <Icon className={cn('h-4 w-4', kind === value ? 'text-zinc-900' : 'text-zinc-400')} />
                                        <span className="text-sm font-medium text-zinc-900">{label}</span>
                                        <span className="text-xs text-muted-foreground">{hint}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="title">Titel</Label>
                            <Input
                                id="title"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                placeholder={type === 'chapter' ? 'z.B. Einführung' : kind === 'task' ? 'z.B. Eigene Kurve bauen' : 'z.B. Grundlagen'}
                                autoFocus
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="slug">Slug</Label>
                            <Input
                                id="slug"
                                value={slug}
                                onChange={(e) => {
                                    setSlug(e.target.value);
                                    setAutoSlug(false);
                                }}
                                placeholder="automatisch generiert"
                                className="font-mono text-sm"
                            />
                            <p className="text-xs text-muted-foreground">
                                Wird automatisch aus dem Titel generiert. Du kannst es manuell ändern.
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleCancel}
                            disabled={isSubmitting}
                        >
                            Abbrechen
                        </Button>
                        <Button type="submit" disabled={!title.trim() || isSubmitting}>
                            {isSubmitting ? 'Erstellen...' : 'Erstellen'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
