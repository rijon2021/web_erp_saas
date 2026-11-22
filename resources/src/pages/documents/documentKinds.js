import {
    FileImageOutlined, FilePdfOutlined, FileWordOutlined, FileExcelOutlined,
    FilePptOutlined, FileZipOutlined, VideoCameraOutlined, CustomerServiceOutlined,
    FileTextOutlined, FileOutlined,
} from '@ant-design/icons-vue';
import { i18n } from '../../i18n';

const tr = (k, args) => i18n.global.t(k, args || {});

/**
 * One place deciding how a file "reads" in the archive: its icon, its accent
 * colour and its human label. The `kind` string is computed server-side
 * (Document::kindFor) so the filter dropdown, the cards, the table and the
 * preview pane can never disagree about what a file is.
 *
 * Colours are picked to stay legible on both the light and dark antd surfaces —
 * mid-tone hues, never near-white or near-black.
 */
export const DOCUMENT_KINDS = {
    image: { get label() { return tr('Kind_Images'); }, icon: FileImageOutlined, color: '#0891b2' },
    pdf: { get label() { return tr('Kind_PDF'); }, icon: FilePdfOutlined, color: '#dc2626' },
    word: { get label() { return tr('Kind_Documents'); }, icon: FileWordOutlined, color: '#2563eb' },
    excel: { get label() { return tr('Kind_Spreadsheets'); }, icon: FileExcelOutlined, color: '#16a34a' },
    powerpoint: { get label() { return tr('Kind_Presentations'); }, icon: FilePptOutlined, color: '#ea580c' },
    archive: { get label() { return tr('Kind_Archives'); }, icon: FileZipOutlined, color: '#b45309' },
    video: { get label() { return tr('Kind_Video'); }, icon: VideoCameraOutlined, color: '#db2777' },
    audio: { get label() { return tr('Kind_Audio'); }, icon: CustomerServiceOutlined, color: '#7c3aed' },
    text: { get label() { return tr('Kind_Text'); }, icon: FileTextOutlined, color: '#64748b' },
    other: { get label() { return tr('Kind_Other'); }, icon: FileOutlined, color: '#6b7280' },
};

export function kindOf(record) {
    return DOCUMENT_KINDS[record?.kind] || DOCUMENT_KINDS.other;
}

/** Options for the type filter, in the order the tiles read best. */
export const KIND_OPTIONS = Object.entries(DOCUMENT_KINDS)
    .map(([value, k]) => ({ value, get label() { return k.label; } }));

/** "3 days ago" — enough precision for a file list, no dayjs plugin needed. */
export function timeAgo(iso) {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';

    const seconds = Math.round((Date.now() - then) / 1000);
    if (seconds < 60) return tr('just_now');

    // Already in minutes; each step promotes to the next unit once it fits.
    const steps = [
        [60, 'hour'], [24, 'day'], [7, 'week'], [4.35, 'month'], [12, 'year'],
    ];
    let value = seconds / 60;
    let unit = 'minute';
    for (const [size, next] of steps) {
        if (Math.abs(value) < size) break;
        value /= size;
        unit = next;
    }
    const rounded = Math.round(value);
    const unitKey = unit.charAt(0).toUpperCase() + unit.slice(1) + (rounded === 1 ? '' : 's');
    return tr('Doc_Ago_' + unitKey, { n: rounded });
}
