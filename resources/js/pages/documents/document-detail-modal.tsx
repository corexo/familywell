import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Building2,
    Calendar,
    Check,
    Clock,
    Copy,
    Download,
    ExternalLink,
    FileCheck2,
    FileCode2,
    FileDigit,
    FileText,
    Receipt,
    Save,
    ShieldAlert,
    ShieldCheck,
    Tag,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

export type DocumentItem = {
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

const CATEGORY_META: Record<
    string,
    { label: string; icon: React.ComponentType<{ className?: string }>; color: string }
> = {
    invoice: { label: 'Rechnung', icon: Receipt, color: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20' },
    receipt: { label: 'Quittung', icon: FileCheck2, color: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20' },
    contract: { label: 'Vertrag', icon: FileCode2, color: 'bg-violet-500/10 text-violet-600 dark:text-violet-400 border-violet-500/20' },
    warranty: { label: 'Garantie', icon: ShieldCheck, color: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20' },
    insurance: { label: 'Versicherung', icon: ShieldAlert, color: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border-cyan-500/20' },
    tax: { label: 'Steuern', icon: FileDigit, color: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20' },
    other: { label: 'Sonstiges', icon: FileText, color: 'bg-muted text-muted-foreground border-border' },
};

function formatBytes(bytes: number | null): string {
    if (!bytes) return '';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${sizes[i]}`;
}

type Props = {
    doc: DocumentItem | null;
    isOpen: boolean;
    onClose: () => void;
    onDelete: (id: number) => void;
    categories: string[];
};

export default function DocumentDetailModal({ doc, isOpen, onClose, onDelete, categories }: Props) {
    if (!doc) return null;

    const [copiedOcr, setCopiedOcr] = useState(false);
    const [tagInput, setTagInput] = useState('');
    const [tagsList, setTagsList] = useState<string[]>(doc.tags || []);

    const { data, setData, patch, processing, isDirty } = useForm({
        title: doc.title,
        category: doc.category,
        vendor: doc.vendor || '',
        amount: doc.amount !== null ? doc.amount.toString() : '',
        currency: doc.currency || 'EUR',
        issued_on: doc.issuedOn || '',
        due_on: doc.dueOn || '',
        expires_on: doc.expiresOn || '',
        notes: doc.notes || '',
        tags: doc.tags ? doc.tags.join(', ') : '',
    });

    useEffect(() => {
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
            tags: doc.tags ? doc.tags.join(', ') : '',
        });
        setTagsList(doc.tags || []);
    }, [doc]);

    const handleAddTag = () => {
        const trimmed = tagInput.trim().toLowerCase();
        if (trimmed && !tagsList.includes(trimmed)) {
            const next = [...tagsList, trimmed];
            setTagsList(next);
            setData('tags', next.join(', '));
            setTagInput('');
        }
    };

    const handleRemoveTag = (tagToRemove: string) => {
        const next = tagsList.filter((t) => t !== tagToRemove);
        setTagsList(next);
        setData('tags', next.join(', '));
    };

    const handleSave = (e: React.FormEvent) => {
        e.preventDefault();
        patch(`/m/documents/${doc.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                // Keep open or update state
            },
        });
    };

    const copyOcrText = () => {
        if (!doc.contentText) return;
        navigator.clipboard.writeText(doc.contentText);
        setCopiedOcr(true);
        setTimeout(() => setCopiedOcr(false), 2000);
    };

    const meta = CATEGORY_META[data.category] || CATEGORY_META.other;
    const Icon = meta.icon;
    const isPdf = doc.mimeType === 'application/pdf' || doc.fileName?.toLowerCase().endsWith('.pdf');
    const isImage = doc.mimeType?.startsWith('image/') || /\.(png|jpe?g|webp|gif)$/i.test(doc.fileName || '');

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-[96vw] w-[1400px] h-[92vh] max-h-[92vh] p-0 flex flex-col overflow-hidden sm:rounded-2xl border bg-background shadow-2xl">
                {/* Top Bar / Header */}
                <DialogHeader className="px-5 py-3.5 border-b flex flex-row items-center justify-between gap-4 bg-muted/20 shrink-0">
                    <div className="flex items-center gap-3 min-w-0">
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium shrink-0 ${meta.color}`}
                        >
                            <Icon className="size-3.5" />
                            {meta.label}
                        </span>

                        <DialogTitle className="text-base font-semibold truncate text-foreground">
                            {doc.title}
                        </DialogTitle>

                        {doc.hasOcr && (
                            <span className="inline-flex items-center rounded bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 shrink-0">
                                OCR erfasst
                            </span>
                        )}

                        {doc.isOverdue && (
                            <Badge variant="destructive" className="gap-1 text-xs shrink-0 font-normal">
                                <AlertCircle className="size-3" /> Überfällig
                            </Badge>
                        )}
                        {doc.isExpiring && !doc.isOverdue && (
                            <Badge className="gap-1 bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-300 text-xs shrink-0 font-normal">
                                <Clock className="size-3" /> Läuft bald ab
                            </Badge>
                        )}
                    </div>

                    <div className="flex items-center gap-2 pr-6 shrink-0">
                        {doc.fileUrl && (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    asChild
                                    className="gap-1.5 h-8 text-xs font-medium"
                                >
                                    <a href={doc.fileUrl} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink className="size-3.5" />
                                        In neuem Tab
                                    </a>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    asChild
                                    className="gap-1.5 h-8 text-xs font-medium"
                                >
                                    <a href={doc.fileUrl} download={doc.fileName || true}>
                                        <Download className="size-3.5" />
                                        Herunterladen
                                    </a>
                                </Button>
                            </>
                        )}

                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                if (confirm(`Dokument "${doc.title}" wirklich löschen?`)) {
                                    onDelete(doc.id);
                                    onClose();
                                }
                            }}
                            className="gap-1.5 h-8 text-xs text-destructive hover:text-destructive hover:bg-destructive/10"
                        >
                            <Trash2 className="size-3.5" />
                            Löschen
                        </Button>
                    </div>
                </DialogHeader>

                {/* Main Split Layout: Left Data / Right PDF Preview */}
                <div className="flex-1 flex flex-col md:flex-row overflow-hidden">
                    {/* Left Panel: Metadata & Editor (Scrollable) */}
                    <div className="w-full md:w-[440px] lg:w-[480px] border-r bg-card flex flex-col shrink-0 h-full overflow-hidden">
                        <form onSubmit={handleSave} className="flex flex-col h-full">
                            <div className="flex-1 overflow-y-auto p-5 space-y-4">
                                {/* Title */}
                                <div className="space-y-1.5">
                                    <Label htmlFor="detail-title" className="text-xs font-medium text-muted-foreground">
                                        Titel / Bezeichnung
                                    </Label>
                                    <Input
                                        id="detail-title"
                                        value={data.title}
                                        onChange={(e) => setData('title', e.target.value)}
                                        className="font-medium"
                                        required
                                    />
                                </div>

                                {/* Category & Vendor */}
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="detail-category" className="text-xs font-medium text-muted-foreground">
                                            Kategorie
                                        </Label>
                                        <Select
                                            value={data.category}
                                            onValueChange={(val) => setData('category', val)}
                                        >
                                            <SelectTrigger id="detail-category" className="w-full">
                                                <SelectValue />
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
                                        <Label htmlFor="detail-vendor" className="text-xs font-medium text-muted-foreground">
                                            Aussteller / Bank / Händler
                                        </Label>
                                        <Input
                                            id="detail-vendor"
                                            value={data.vendor}
                                            onChange={(e) => setData('vendor', e.target.value)}
                                            placeholder="z.B. Sparkasse, Telekom"
                                        />
                                    </div>
                                </div>

                                {/* Amount & Currency */}
                                <div className="grid grid-cols-3 gap-3">
                                    <div className="col-span-2 space-y-1.5">
                                        <Label htmlFor="detail-amount" className="text-xs font-medium text-muted-foreground">
                                            Betrag (€)
                                        </Label>
                                        <Input
                                            id="detail-amount"
                                            type="number"
                                            step="0.01"
                                            value={data.amount}
                                            onChange={(e) => setData('amount', e.target.value)}
                                            placeholder="0,00"
                                            className="font-semibold"
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="detail-currency" className="text-xs font-medium text-muted-foreground">
                                            Währung
                                        </Label>
                                        <Input
                                            id="detail-currency"
                                            value={data.currency}
                                            onChange={(e) => setData('currency', e.target.value)}
                                        />
                                    </div>
                                </div>

                                {/* Dates: Issued, Due, Expires */}
                                <div className="grid grid-cols-3 gap-2 pt-1">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="detail-issued" className="text-[11px] font-medium text-muted-foreground flex items-center gap-1">
                                            <Calendar className="size-3" /> Ausgestellt
                                        </Label>
                                        <Input
                                            id="detail-issued"
                                            type="date"
                                            value={data.issued_on}
                                            onChange={(e) => setData('issued_on', e.target.value)}
                                            className="text-xs px-2"
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="detail-due" className="text-[11px] font-medium text-muted-foreground flex items-center gap-1">
                                            <Clock className="size-3" /> Fällig
                                        </Label>
                                        <Input
                                            id="detail-due"
                                            type="date"
                                            value={data.due_on}
                                            onChange={(e) => setData('due_on', e.target.value)}
                                            className="text-xs px-2"
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="detail-expires" className="text-[11px] font-medium text-muted-foreground flex items-center gap-1">
                                            <ShieldAlert className="size-3" /> Ablauf
                                        </Label>
                                        <Input
                                            id="detail-expires"
                                            type="date"
                                            value={data.expires_on}
                                            onChange={(e) => setData('expires_on', e.target.value)}
                                            className="text-xs px-2"
                                        />
                                    </div>
                                </div>

                                {/* Tags */}
                                <div className="space-y-2 pt-1">
                                    <Label className="text-xs font-medium text-muted-foreground flex items-center justify-between">
                                        <span>Schlagwörter (Tags)</span>
                                        <span className="text-[10px] text-muted-foreground font-normal">Enter zum Hinzufügen</span>
                                    </Label>
                                    <div className="flex flex-wrap gap-1.5 min-h-7 p-2 rounded-lg border bg-muted/20">
                                        {tagsList.map((tag) => (
                                            <span
                                                key={tag}
                                                className="inline-flex items-center gap-1 rounded bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
                                            >
                                                #{tag}
                                                <button
                                                    type="button"
                                                    onClick={() => handleRemoveTag(tag)}
                                                    className="hover:text-destructive"
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            </span>
                                        ))}
                                        <input
                                            type="text"
                                            placeholder={tagsList.length === 0 ? "Neues Tag eingeben..." : "+ Tag"}
                                            value={tagInput}
                                            onChange={(e) => setTagInput(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    handleAddTag();
                                                }
                                            }}
                                            className="bg-transparent text-xs outline-hidden flex-1 min-w-[90px]"
                                        />
                                    </div>
                                </div>

                                {/* Notes */}
                                <div className="space-y-1.5">
                                    <Label htmlFor="detail-notes" className="text-xs font-medium text-muted-foreground">
                                        Notizen / Bemerkungen
                                    </Label>
                                    <Textarea
                                        id="detail-notes"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        placeholder="Zusätzliche Infos, Aktenzeichen, Kundennummer..."
                                        rows={3}
                                    />
                                </div>

                                {/* Extracted OCR Text Section */}
                                {doc.contentText && (
                                    <div className="space-y-1.5 pt-2 border-t">
                                        <div className="flex items-center justify-between">
                                            <Label className="text-xs font-medium text-muted-foreground flex items-center gap-1.5">
                                                <FileText className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                                Erkannter Volltext (OCR)
                                            </Label>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={copyOcrText}
                                                className="h-6 px-2 text-[11px] gap-1 text-muted-foreground hover:text-foreground"
                                            >
                                                {copiedOcr ? (
                                                    <>
                                                        <Check className="size-3 text-emerald-600" />
                                                        Kopiert
                                                    </>
                                                ) : (
                                                    <>
                                                        <Copy className="size-3" />
                                                        Text kopieren
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                        <div className="max-h-48 overflow-y-auto rounded-lg border bg-muted/40 p-3 text-[11px] font-mono text-muted-foreground whitespace-pre-wrap select-text leading-relaxed">
                                            {doc.contentText}
                                        </div>
                                    </div>
                                )}

                                {/* File info summary */}
                                <div className="text-[11px] text-muted-foreground pt-2 border-t flex items-center justify-between">
                                    <span className="truncate max-w-[240px]">{doc.fileName}</span>
                                    <span>{formatBytes(doc.fileSize)}</span>
                                </div>
                            </div>

                            {/* Sticky Save Bar */}
                            <div className="p-4 border-t bg-muted/20 flex items-center justify-between shrink-0">
                                <span className="text-xs text-muted-foreground">
                                    {isDirty ? 'Ungespeicherte Änderungen' : 'Alle Daten aktuell'}
                                </span>
                                <Button type="submit" disabled={processing} className="gap-2">
                                    <Save className="size-4" />
                                    {processing ? 'Speichere...' : 'Änderungen speichern'}
                                </Button>
                            </div>
                        </form>
                    </div>

                    {/* Right Panel: Embedded Document Viewer (PDF or Image) */}
                    <div className="flex-1 bg-muted/30 flex flex-col overflow-hidden relative min-h-[400px]">
                        {doc.hasFile && doc.fileUrl ? (
                            isPdf ? (
                                <iframe
                                    src={`${doc.fileUrl}#view=FitH`}
                                    className="w-full h-full border-0 bg-background"
                                    title={doc.title}
                                />
                            ) : isImage ? (
                                <div className="w-full h-full flex items-center justify-center p-6 overflow-auto bg-muted/10">
                                    <img
                                        src={doc.fileUrl}
                                        alt={doc.title}
                                        className="max-w-full max-h-full object-contain rounded-lg shadow-md border"
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center h-full text-center p-8">
                                    <FileText className="size-16 text-muted-foreground/60 mb-3" />
                                    <h4 className="text-base font-semibold">{doc.fileName}</h4>
                                    <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                                        Für diesen Dateityp ist keine direkte Web-Vorschau verfügbar.
                                    </p>
                                    <Button asChild className="mt-4 gap-2">
                                        <a href={doc.fileUrl} download={doc.fileName || true}>
                                            <Download className="size-4" /> Datei herunterladen
                                        </a>
                                    </Button>
                                </div>
                            )
                        ) : (
                            <div className="flex flex-col items-center justify-center h-full text-center p-8">
                                <FileText className="size-16 text-muted-foreground/40 mb-3" />
                                <h4 className="text-base font-semibold text-muted-foreground">Keine Datei hinterlegt</h4>
                                <p className="text-sm text-muted-foreground mt-1">
                                    Diesem Eintrag ist kein Dokument oder PDF zugeordnet.
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
