import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import DocumentDetailModal from './document-detail-modal';
import {
    AlertCircle,
    Building2,
    Calendar,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    Clock,
    Download,
    Edit3,
    ExternalLink,
    Eye,
    FileCheck2,
    FileCode2,
    FileDigit,
    FileText,
    Filter,
    FolderKanban,
    LayoutGrid,
    List,
    MoreVertical,
    Plus,
    Receipt,
    Search,
    ShieldAlert,
    ShieldCheck,
    Tag,
    Trash2,
    UploadCloud,
    Wallet,
} from 'lucide-react';
import { FormEvent, useMemo, useRef, useState } from 'react';

type DocumentItem = {
    id: number;
    title: string;
    category: string;
    vendor: string | null;
    amount: number | null;
    currency: string;
    issuedOn: string | null;
    dueOn: string | null;
    expiresOn: string | null;
    isExpiring: boolean;
    isOverdue: boolean;
    hasFile: boolean;
    hasOcr?: boolean;
    contentText?: string | null;
    fileName: string | null;
    fileSize: number | null;
    mimeType: string | null;
    fileUrl: string | null;
    notes: string | null;
    tags: string[];
    createdAt: string;
};

type PaginationInfo = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
    hasMorePages: boolean;
    prevPageUrl: string | null;
    nextPageUrl: string | null;
};

type Props = {
    documents: DocumentItem[];
    pagination?: PaginationInfo;
    categories: string[];
    allTags: string[];
    stats: {
        total: number;
        totalAmount: number;
        expiringCount: number;
        overdueCount: number;
    };
    filters: {
        category: string | null;
        search: string;
        tag: string;
        status: string | null;
    };
};

const CATEGORY_META: Record<string, { label: string; icon: any; color: string }> = {
    invoice: { label: 'Rechnung', icon: FileText, color: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-200 dark:border-blue-800' },
    receipt: { label: 'Beleg', icon: Receipt, color: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800' },
    contract: { label: 'Vertrag', icon: FileCheck2, color: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-200 dark:border-purple-800' },
    warranty: { label: 'Garantie', icon: ShieldCheck, color: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800' },
    insurance: { label: 'Versicherung', icon: ShieldAlert, color: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800' },
    tax: { label: 'Steuer', icon: Building2, color: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border-cyan-200 dark:border-cyan-800' },
    other: { label: 'Sonstiges', icon: FileCode2, color: 'bg-stone-500/10 text-stone-600 dark:text-stone-400 border-stone-200 dark:border-stone-800' },
};

function formatBytes(bytes: number | null): string {
    if (!bytes) return '';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${sizes[i]}`;
}

export default function DocumentsIndex({ documents, pagination, categories, allTags, stats, filters }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dokumente', href: '/m/documents' }];

    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editingDoc, setEditingDoc] = useState<DocumentItem | null>(null);
    const [viewingDoc, setViewingDoc] = useState<DocumentItem | null>(null);
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [selectedCategory, setSelectedCategory] = useState<string>(filters.category || 'all');
    const [selectedStatus, setSelectedStatus] = useState<string>(filters.status || 'all');
    const [searchTerm, setSearchTerm] = useState<string>(filters.search || '');
    const [selectedTag, setSelectedTag] = useState<string>(filters.tag || '');

    const fileInputRef = useRef<HTMLInputElement>(null);

    const { data, setData, post, patch, processing, reset, errors } = useForm<{
        title: string;
        category: string;
        vendor: string;
        amount: string;
        currency: string;
        issued_on: string;
        due_on: string;
        expires_on: string;
        notes: string;
        tags: string;
        file: File | null;
    }>({
        title: '',
        category: 'invoice',
        vendor: '',
        amount: '',
        currency: 'EUR',
        issued_on: '',
        due_on: '',
        expires_on: '',
        notes: '',
        tags: '',
        file: null,
    });

    const openAdd = () => {
        setEditingDoc(null);
        reset();
        setIsAddOpen(true);
    };

    const openEdit = (doc: DocumentItem) => {
        setEditingDoc(doc);
        setData({
            title: doc.title,
            category: doc.category,
            vendor: doc.vendor || '',
            amount: doc.amount !== null ? doc.amount.toString() : '',
            currency: doc.currency || 'EUR',
            issued_on: doc.issuedOn || '',
            due_on: doc.dueOn || '',
            expires_on: doc.expiresOn || '',
            notes: doc.notes || '',
            tags: doc.tags.join(', '),
            file: null,
        });
        setIsAddOpen(true);
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (editingDoc) {
            patch(`/m/documents/${editingDoc.id}`, {
                onSuccess: () => {
                    setIsAddOpen(false);
                    setEditingDoc(null);
                },
            });
        } else {
            post('/m/documents', {
                onSuccess: () => {
                    setIsAddOpen(false);
                    reset();
                },
            });
        }
    };

    const handleDelete = (id: number) => {
        if (confirm('Möchtest du dieses Dokument wirklich in den Papierkorb verschieben?')) {
            router.delete(`/m/documents/${id}`);
        }
    };

    const applyFilters = (newCategory?: string, newStatus?: string, newTag?: string, newSearch?: string) => {
        const cat = newCategory !== undefined ? newCategory : selectedCategory;
        const stat = newStatus !== undefined ? newStatus : selectedStatus;
        const tg = newTag !== undefined ? newTag : selectedTag;
        const s = newSearch !== undefined ? newSearch : searchTerm;

        router.get(
            '/m/documents',
            {
                ...(cat !== 'all' ? { category: cat } : {}),
                ...(stat !== 'all' ? { status: stat } : {}),
                ...(tg ? { tag: tg } : {}),
                ...(s ? { search: s } : {}),
            },
            { preserveState: true, replace: true }
        );
    };

    const filteredDocs = useMemo(() => {
        if (pagination) {
            return documents;
        }
        return documents.filter((doc) => {
            if (selectedCategory !== 'all' && doc.category !== selectedCategory) return false;
            if (selectedStatus === 'expiring' && !doc.isExpiring) return false;
            if (selectedStatus === 'overdue' && !doc.isOverdue) return false;
            if (selectedTag && !doc.tags.includes(selectedTag)) return false;
            if (searchTerm) {
                const term = searchTerm.toLowerCase();
                const matchTitle = doc.title.toLowerCase().includes(term);
                const matchVendor = (doc.vendor || '').toLowerCase().includes(term);
                const matchNotes = (doc.notes || '').toLowerCase().includes(term);
                if (!matchTitle && !matchVendor && !matchNotes) return false;
            }
            return true;
        });
    }, [documents, pagination, selectedCategory, selectedStatus, selectedTag, searchTerm]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dokumente & Belege" />

            <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 md:px-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">Dokumente & Belege</h1>
                        <p className="text-sm text-muted-foreground">
                            Zentrale Ablage für Rechnungen, Kassenbelege, Garantien und Verträge.
                        </p>
                    </div>
                    <Button onClick={openAdd} className="shrink-0 gap-2">
                        <Plus className="size-4" />
                        Dokument hinzufügen
                    </Button>
                </div>

                {/* Stats Cards */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
                    <div className="rounded-2xl border bg-card p-4 shadow-sm">
                        <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                            <FileText className="size-4 text-primary" />
                            Gesamt
                        </div>
                        <div className="mt-2 text-2xl font-bold text-foreground">{stats.total}</div>
                        <div className="text-xs text-muted-foreground">Dokumente abgelegt</div>
                    </div>

                    <div className="rounded-2xl border bg-card p-4 shadow-sm">
                        <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                            <Wallet className="size-4 text-emerald-600" />
                            Rechnungen
                        </div>
                        <div className="mt-2 text-2xl font-bold text-foreground">
                            € {stats.totalAmount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                        </div>
                        <div className="text-xs text-muted-foreground">Erfasste Summe</div>
                    </div>

                    <div
                        onClick={() => {
                            setSelectedStatus(selectedStatus === 'expiring' ? 'all' : 'expiring');
                            applyFilters(undefined, selectedStatus === 'expiring' ? 'all' : 'expiring');
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition-colors ${
                            selectedStatus === 'expiring' ? 'border-amber-500 bg-amber-500/10' : 'bg-card hover:border-amber-500/50'
                        }`}
                    >
                        <div className="flex items-center gap-2 text-xs font-medium text-amber-600 dark:text-amber-400">
                            <Clock className="size-4" />
                            Bald ablaufend
                        </div>
                        <div className="mt-2 text-2xl font-bold text-foreground">{stats.expiringCount}</div>
                        <div className="text-xs text-muted-foreground">in den nächsten 30 Tagen</div>
                    </div>

                    <div
                        onClick={() => {
                            setSelectedStatus(selectedStatus === 'overdue' ? 'all' : 'overdue');
                            applyFilters(undefined, selectedStatus === 'overdue' ? 'all' : 'overdue');
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition-colors ${
                            selectedStatus === 'overdue' ? 'border-rose-500 bg-rose-500/10' : 'bg-card hover:border-rose-500/50'
                        }`}
                    >
                        <div className="flex items-center gap-2 text-xs font-medium text-rose-600 dark:text-rose-400">
                            <AlertCircle className="size-4" />
                            Überfällig
                        </div>
                        <div className="mt-2 text-2xl font-bold text-foreground">{stats.overdueCount}</div>
                        <div className="text-xs text-muted-foreground">Frist überschritten</div>
                    </div>
                </div>

                {/* Filters & Search */}
                <div className="space-y-3">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Nach Titel, Händler oder Notizen suchen…"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                className="pl-9"
                            />
                        </div>

                        {/* Category filter pills */}
                        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0" data-scroll-x>
                            <Button
                                variant={selectedCategory === 'all' ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => {
                                    setSelectedCategory('all');
                                    applyFilters('all');
                                }}
                                className="rounded-full text-xs"
                            >
                                Alle
                            </Button>
                            {categories.map((cat) => {
                                const meta = CATEGORY_META[cat] || { label: cat, icon: FileText };
                                const Icon = meta.icon;
                                const isSelected = selectedCategory === cat;
                                return (
                                    <Button
                                        key={cat}
                                        variant={isSelected ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => {
                                            const next = isSelected ? 'all' : cat;
                                            setSelectedCategory(next);
                                            applyFilters(next);
                                        }}
                                        className="gap-1.5 rounded-full text-xs"
                                    >
                                        <Icon className="size-3.5" />
                                        {meta.label}
                                    </Button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Tag filter chips */}
                    {allTags.length > 0 && (
                        <div className="flex items-center gap-1.5 overflow-x-auto text-xs" data-scroll-x>
                            <span className="flex items-center gap-1 text-muted-foreground">
                                <Tag className="size-3" /> Tags:
                            </span>
                            {allTags.map((tag) => (
                                <Badge
                                    key={tag}
                                    variant={selectedTag === tag ? 'default' : 'outline'}
                                    className="cursor-pointer font-normal"
                                    onClick={() => {
                                        const next = selectedTag === tag ? '' : tag;
                                        setSelectedTag(next);
                                        applyFilters(undefined, undefined, next);
                                    }}
                                >
                                    #{tag}
                                </Badge>
                            ))}
                        </div>
                    )}
                </div>

                {/* View Toolbar: Count & Grid/List Toggle */}
                <div className="flex items-center justify-between gap-4 pt-1">
                    <span className="text-xs font-medium text-muted-foreground">
                        {pagination ? pagination.total : filteredDocs.length} {((pagination ? pagination.total : filteredDocs.length) === 1) ? 'Dokument' : 'Dokumente'}
                        {pagination && pagination.total !== stats.total && (
                            <span className="ml-1 text-muted-foreground/75 font-normal">
                                (gefiltert aus {stats.total})
                            </span>
                        )}
                    </span>

                    <div className="flex items-center rounded-lg border bg-muted/30 p-0.5">
                        <Button
                            type="button"
                            variant={viewMode === 'grid' ? 'default' : 'ghost'}
                            size="sm"
                            onClick={() => setViewMode('grid')}
                            className="h-7 px-2.5 gap-1.5 text-xs rounded-md"
                            title="Kachelansicht"
                        >
                            <LayoutGrid className="size-3.5" />
                            Kacheln
                        </Button>
                        <Button
                            type="button"
                            variant={viewMode === 'list' ? 'default' : 'ghost'}
                            size="sm"
                            onClick={() => setViewMode('list')}
                            className="h-7 px-2.5 gap-1.5 text-xs rounded-md"
                            title="Listenansicht"
                        >
                            <List className="size-3.5" />
                            Liste
                        </Button>
                    </div>
                </div>

                {/* Documents Content */}
                {filteredDocs.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed py-16 text-center">
                        <div className="flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                            <FileText className="size-7" />
                        </div>
                        <h3 className="mt-4 text-base font-semibold text-foreground">Keine Dokumente gefunden</h3>
                        <p className="mt-1 text-sm text-muted-foreground max-w-sm">
                            {searchTerm || selectedCategory !== 'all' || selectedStatus !== 'all'
                                ? 'Für diese Filtereinstellungen gibt es keine Treffer.'
                                : 'Lege dein erstes Dokument oder eine Rechnung an, um den Überblick zu behalten.'}
                        </p>
                        <Button onClick={openAdd} className="mt-6 gap-2">
                            <Plus className="size-4" /> Dokument anlegen
                        </Button>
                    </div>
                ) : viewMode === 'grid' ? (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {filteredDocs.map((doc) => {
                            const meta = CATEGORY_META[doc.category] || CATEGORY_META.other;
                            const Icon = meta.icon;

                            return (
                                <div
                                    key={doc.id}
                                    onClick={() => setViewingDoc(doc)}
                                    className="group flex flex-col justify-between rounded-2xl border bg-card p-4 shadow-sm transition-all hover:border-primary/50 hover:shadow-md cursor-pointer"
                                >
                                    <div>
                                        {/* Top Row: Category badge & Actions */}
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex items-center gap-1.5">
                                                <span
                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${meta.color}`}
                                                >
                                                    <Icon className="size-3" />
                                                    {meta.label}
                                                </span>
                                                {doc.hasOcr && (
                                                    <span className="inline-flex items-center rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 border border-emerald-500/20" title="Text per OCR / Volltextsuche erfasst">
                                                        OCR
                                                    </span>
                                                )}
                                            </div>

                                            <div onClick={(e) => e.stopPropagation()}>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="icon" className="size-8 text-muted-foreground">
                                                            <MoreVertical className="size-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem onClick={() => setViewingDoc(doc)} className="gap-2 font-medium">
                                                            <Eye className="size-4" />
                                                            Details & Vorschau
                                                        </DropdownMenuItem>
                                                        {doc.fileUrl && (
                                                            <DropdownMenuItem asChild>
                                                                <a href={doc.fileUrl} target="_blank" rel="noopener noreferrer" className="gap-2">
                                                                    <ExternalLink className="size-4" />
                                                                    In neuem Tab öffnen
                                                                </a>
                                                            </DropdownMenuItem>
                                                        )}
                                                        <DropdownMenuItem onClick={() => openEdit(doc)} className="gap-2">
                                                            <Edit3 className="size-4" />
                                                            Schnell bearbeiten
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => handleDelete(doc.id)}
                                                            className="gap-2 text-destructive focus:text-destructive"
                                                        >
                                                            <Trash2 className="size-4" />
                                                            Löschen
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </div>

                                        {/* Title & Vendor */}
                                        <div className="mt-3">
                                            <h3 className="font-semibold text-foreground line-clamp-1 group-hover:text-primary transition-colors">
                                                {doc.title}
                                            </h3>
                                            {doc.vendor && (
                                                <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Building2 className="size-3" />
                                                    <span className="truncate">{doc.vendor}</span>
                                                </div>
                                            )}
                                        </div>

                                        {/* Amount */}
                                        {doc.amount !== null && (
                                            <div className="mt-3 text-lg font-bold text-foreground">
                                                € {doc.amount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                            </div>
                                        )}

                                        {/* Badges: Expiry & Overdue */}
                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            {doc.isOverdue && (
                                                <Badge variant="destructive" className="gap-1 text-[11px] font-normal">
                                                    <AlertCircle className="size-3" /> Überfällig seit {doc.dueOn}
                                                </Badge>
                                            )}
                                            {doc.isExpiring && !doc.isOverdue && (
                                                <Badge className="gap-1 bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-300 text-[11px] font-normal">
                                                    <Clock className="size-3" /> Läuft ab am {doc.expiresOn}
                                                </Badge>
                                            )}
                                        </div>

                                        {/* Notes snippet */}
                                        {doc.notes && (
                                            <p className="mt-2 text-xs text-muted-foreground line-clamp-2">
                                                {doc.notes}
                                            </p>
                                        )}
                                    </div>

                                    {/* Bottom row: file info & tags */}
                                    <div className="mt-4 pt-3 border-t border-border/50 flex flex-col gap-2">
                                        {doc.tags && doc.tags.length > 0 && (
                                            <div className="flex flex-wrap gap-1">
                                                {doc.tags.map((t) => (
                                                    <span key={t} className="text-[11px] text-muted-foreground bg-muted/60 px-1.5 py-0.5 rounded">
                                                        #{t}
                                                    </span>
                                                ))}
                                            </div>
                                        )}

                                        <div className="flex items-center justify-between text-xs text-muted-foreground">
                                            {doc.hasFile ? (
                                                <span className="flex items-center gap-1.5 font-medium text-primary truncate max-w-[180px]">
                                                    <Download className="size-3.5 shrink-0" />
                                                    <span className="truncate">{doc.fileName || 'Datei ansehen'}</span>
                                                    {doc.fileSize && <span className="text-[10px] text-muted-foreground">({formatBytes(doc.fileSize)})</span>}
                                                </span>
                                            ) : (
                                                <span className="text-[11px] text-muted-foreground italic">Keine Datei</span>
                                            )}

                                            {doc.issuedOn && (
                                                <span className="text-[11px] text-muted-foreground">
                                                    {new Date(doc.issuedOn).toLocaleDateString('de-DE')}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    /* List View */
                    <div className="flex flex-col gap-2">
                        {filteredDocs.map((doc) => {
                            const meta = CATEGORY_META[doc.category] || CATEGORY_META.other;
                            const Icon = meta.icon;

                            return (
                                <div
                                    key={doc.id}
                                    onClick={() => setViewingDoc(doc)}
                                    className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border bg-card p-3 sm:px-4 shadow-xs transition-all hover:border-primary/50 hover:bg-muted/20 cursor-pointer"
                                >
                                    {/* Left: Icon, Title, Vendor, Badges */}
                                    <div className="flex items-center gap-3 min-w-0 flex-1">
                                        <span className={`inline-flex items-center justify-center size-9 rounded-lg border shrink-0 ${meta.color}`}>
                                            <Icon className="size-4" />
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <h3 className="font-semibold text-sm text-foreground truncate group-hover:text-primary transition-colors">
                                                    {doc.title}
                                                </h3>
                                                {doc.hasOcr && (
                                                    <span className="inline-flex items-center rounded bg-emerald-500/10 px-1.5 py-0.2 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 shrink-0">
                                                        OCR
                                                    </span>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2 text-xs text-muted-foreground mt-0.5 truncate">
                                                {doc.vendor && (
                                                    <span className="flex items-center gap-1 shrink-0 font-medium text-foreground/80">
                                                        <Building2 className="size-3" />
                                                        {doc.vendor}
                                                    </span>
                                                )}
                                                {doc.vendor && <span>•</span>}
                                                <span>{meta.label}</span>
                                                {doc.fileName && (
                                                    <>
                                                        <span>•</span>
                                                        <span className="truncate max-w-[160px]">{doc.fileName}</span>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Middle & Right: Tags, Date, Amount, Badges, Actions */}
                                    <div className="flex items-center justify-between sm:justify-end gap-3 sm:gap-4 shrink-0 pl-12 sm:pl-0">
                                        {doc.tags && doc.tags.length > 0 && (
                                            <div className="hidden lg:flex items-center gap-1 max-w-[180px] overflow-hidden">
                                                {doc.tags.slice(0, 2).map((t) => (
                                                    <span key={t} className="text-[10px] text-muted-foreground bg-muted/60 px-1.5 py-0.5 rounded truncate">
                                                        #{t}
                                                    </span>
                                                ))}
                                                {doc.tags.length > 2 && (
                                                    <span className="text-[10px] text-muted-foreground">+{doc.tags.length - 2}</span>
                                                )}
                                            </div>
                                        )}

                                        {doc.issuedOn && (
                                            <div className="flex items-center gap-1 text-xs text-muted-foreground whitespace-nowrap">
                                                <Calendar className="size-3.5" />
                                                <span>{new Date(doc.issuedOn).toLocaleDateString('de-DE')}</span>
                                            </div>
                                        )}

                                        {doc.amount !== null && (
                                            <div className="text-sm font-bold text-foreground text-right min-w-[75px]">
                                                € {doc.amount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                            </div>
                                        )}

                                        {doc.isOverdue && (
                                            <Badge variant="destructive" className="text-[10px] px-1.5 py-0.5 font-normal shrink-0">
                                                Überfällig
                                            </Badge>
                                        )}
                                        {doc.isExpiring && !doc.isOverdue && (
                                            <Badge className="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-300 text-[10px] px-1.5 py-0.5 font-normal shrink-0">
                                                Ablauf
                                            </Badge>
                                        )}

                                        <div onClick={(e) => e.stopPropagation()} className="shrink-0">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="size-8 text-muted-foreground">
                                                        <MoreVertical className="size-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onClick={() => setViewingDoc(doc)} className="gap-2 font-medium">
                                                        <Eye className="size-4" />
                                                        Details & Vorschau
                                                    </DropdownMenuItem>
                                                    {doc.fileUrl && (
                                                        <DropdownMenuItem asChild>
                                                            <a href={doc.fileUrl} target="_blank" rel="noopener noreferrer" className="gap-2">
                                                                <ExternalLink className="size-4" />
                                                                In neuem Tab öffnen
                                                            </a>
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem onClick={() => openEdit(doc)} className="gap-2">
                                                        <Edit3 className="size-4" />
                                                        Schnell bearbeiten
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() => handleDelete(doc.id)}
                                                        className="gap-2 text-destructive focus:text-destructive"
                                                    >
                                                        <Trash2 className="size-4" />
                                                        Löschen
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Pagination Controls */}
                {pagination && pagination.lastPage > 1 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-border/60">
                        <div className="text-xs text-muted-foreground">
                            Zeige <span className="font-semibold text-foreground">{pagination.from || 0}</span> bis{' '}
                            <span className="font-semibold text-foreground">{pagination.to || 0}</span> von{' '}
                            <span className="font-semibold text-foreground">{pagination.total}</span> Dokumenten
                            (Seite {pagination.currentPage} von {pagination.lastPage})
                        </div>

                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!pagination.prevPageUrl}
                                onClick={() => pagination.prevPageUrl && router.get(pagination.prevPageUrl, {}, { preserveState: true })}
                                className="h-8 gap-1 text-xs"
                            >
                                <ChevronLeft className="size-3.5" /> Vorherige
                            </Button>

                            <div className="flex items-center gap-1">
                                {pagination.currentPage > 2 && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.get('/m/documents', { ...filters, page: 1 }, { preserveState: true })}
                                        className="h-8 w-8 p-0 text-xs"
                                    >
                                        1
                                    </Button>
                                )}
                                {pagination.currentPage > 3 && (
                                    <span className="text-xs text-muted-foreground px-1">…</span>
                                )}

                                {pagination.currentPage > 1 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => pagination.prevPageUrl && router.get(pagination.prevPageUrl, {}, { preserveState: true })}
                                        className="h-8 w-8 p-0 text-xs"
                                    >
                                        {pagination.currentPage - 1}
                                    </Button>
                                )}

                                <Button
                                    variant="default"
                                    size="sm"
                                    className="h-8 w-8 p-0 text-xs font-semibold"
                                >
                                    {pagination.currentPage}
                                </Button>

                                {pagination.currentPage < pagination.lastPage && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => pagination.nextPageUrl && router.get(pagination.nextPageUrl, {}, { preserveState: true })}
                                        className="h-8 w-8 p-0 text-xs"
                                    >
                                        {pagination.currentPage + 1}
                                    </Button>
                                )}

                                {pagination.currentPage < pagination.lastPage - 2 && (
                                    <span className="text-xs text-muted-foreground px-1">…</span>
                                )}

                                {pagination.currentPage < pagination.lastPage - 1 && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.get('/m/documents', { ...filters, page: pagination.lastPage }, { preserveState: true })}
                                        className="h-8 w-8 p-0 text-xs"
                                    >
                                        {pagination.lastPage}
                                    </Button>
                                )}
                            </div>

                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!pagination.nextPageUrl}
                                onClick={() => pagination.nextPageUrl && router.get(pagination.nextPageUrl, {}, { preserveState: true })}
                                className="h-8 gap-1 text-xs"
                            >
                                Nächste <ChevronRight className="size-3.5" />
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {/* Add / Edit Modal */}
            <ResponsiveModal
                open={isAddOpen}
                onOpenChange={setIsAddOpen}
                title={editingDoc ? 'Dokument bearbeiten' : 'Neues Dokument anlegen'}
                description="Erfasse eine Rechnung, einen Vertrag, eine Garantie oder einen Beleg."
            >
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="title">Titel / Bezeichnung *</Label>
                        <Input
                            id="title"
                            placeholder="z. B. Telekom Internetrechnung Oktober 2026"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            required
                        />
                        {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="category">Kategorie *</Label>
                            <Select value={data.category} onValueChange={(val) => setData('category', val)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Wählen…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {CATEGORY_META[c]?.label || c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="vendor">Händler / Aussteller</Label>
                            <Input
                                id="vendor"
                                placeholder="z. B. Telekom, IKEA, Allianz"
                                value={data.vendor}
                                onChange={(e) => setData('vendor', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="amount">Betrag (€)</Label>
                            <Input
                                id="amount"
                                type="number"
                                step="0.01"
                                placeholder="z. B. 49.99"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="issued_on">Ausstellungsdatum</Label>
                            <Input
                                id="issued_on"
                                type="date"
                                value={data.issued_on}
                                onChange={(e) => setData('issued_on', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="due_on">Fälligkeit / Zahlungsziel</Label>
                            <Input
                                id="due_on"
                                type="date"
                                value={data.due_on}
                                onChange={(e) => setData('due_on', e.target.value)}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="expires_on">Garantie-/Ablaufdatum</Label>
                            <Input
                                id="expires_on"
                                type="date"
                                value={data.expires_on}
                                onChange={(e) => setData('expires_on', e.target.value)}
                            />
                        </div>
                    </div>

                    {/* File Upload */}
                    <div className="space-y-1.5">
                        <Label htmlFor="file">Datei (PDF oder Bild, max. 12 MB)</Label>
                        <div
                            onClick={() => fileInputRef.current?.click()}
                            className="flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-input p-4 text-center transition-colors hover:border-primary/50 hover:bg-muted/30"
                        >
                            <UploadCloud className="size-6 text-muted-foreground" />
                            <span className="mt-1 text-xs font-medium text-foreground">
                                {data.file ? data.file.name : editingDoc?.hasFile ? `Aktuelle Datei: ${editingDoc.fileName} (Klicken zum Ersetzen)` : 'Klicken oder Datei hierher ziehen'}
                            </span>
                            <span className="text-[11px] text-muted-foreground">PDF, JPG, PNG, WEBP bis 12 MB</span>
                            <input
                                ref={fileInputRef}
                                id="file"
                                type="file"
                                accept=".pdf,image/*"
                                className="hidden"
                                onChange={(e) => {
                                    if (e.target.files && e.target.files[0]) {
                                        setData('file', e.target.files[0]);
                                    }
                                }}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="tags">Tags (kommagetrennt)</Label>
                        <Input
                            id="tags"
                            placeholder="z. B. internet, telekom, homeoffice"
                            value={data.tags}
                            onChange={(e) => setData('tags', e.target.value)}
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="notes">Notizen / Bemerkungen</Label>
                        <Textarea
                            id="notes"
                            placeholder="Rechnungsnummer, Kundennummer, besondere Klauseln etc."
                            rows={3}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setIsAddOpen(false)}>
                            Abbrechen
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {editingDoc ? 'Änderungen speichern' : 'Dokument speichern'}
                        </Button>
                    </div>
                </form>
            </ResponsiveModal>

            {/* Split Screen Document Detail & PDF Preview Modal */}
            <DocumentDetailModal
                doc={viewingDoc}
                isOpen={!!viewingDoc}
                onClose={() => setViewingDoc(null)}
                onDelete={handleDelete}
                categories={categories}
            />
        </AppLayout>
    );
}
