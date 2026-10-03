import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import type { OutputData } from '@editorjs/editorjs';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export interface Category {
    id: number;
    name: string;
    description?: string | null;
    my_topics_count?: number;
    created_at?: string;
    updated_at?: string;
}

export interface Section {
    id: number;
    chapter_id: number;
    title: string;
    slug: string;
    content: OutputData;
    is_published: boolean;
    sort_order: number;
    /** Set when the lesson is a task a Moodle course can take over as an assignment */
    task_submission: 'none' | 'file' | 'text' | 'both' | null;
    created_at: string;
    updated_at: string;
}

export interface Chapter {
    id: number;
    topic_id: number;
    title: string;
    slug: string;
    is_published: boolean;
    sort_order: number;
    sections: Pick<Section, 'id' | 'slug' | 'title' | 'sort_order' | 'is_published' | 'task_submission'>[];
    created_at?: string;
    updated_at?: string;
}

export interface Topic {
    id: number;
    title: string;
    slug: string;
    category_id?: number;
    category?: Pick<Category, 'id' | 'name'> | null;
    chapters?: Chapter[];
    activeSection?: Section | null;
    excerpt?: string | null;
    author?: Pick<User, 'id' | 'name'> | null;
    created_at?: string;
    updated_at?: string;
    chapters_count?: number;
    sections_count?: number;
}
