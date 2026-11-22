/**
 * "Items" Excel export for the document lists (sales, returns, purchases,
 * purchase returns, adjustments): one row per product line with its code and
 * category, so the sheet can be matched back to a catalogue by code.
 *
 * `docs` are the rows the list's own export fetched for its current filters;
 * their ids go to POST document-lines/{type}, which re-scopes them server-side.
 */
import http from './http';
import { exportExcel } from './exporters';
import { t } from '../i18n';

// The party column's [translation key, fallback] per document type.
const PARTY = {
    sales: ['Customer', 'Customer'],
    sale_returns: ['Customer', 'Customer'],
    purchases: ['Supplier', 'Supplier'],
    purchase_returns: ['Supplier', 'Supplier'],
};

export async function exportDocumentLines(type, docs, filename) {
    const ids = (docs || []).map(d => d.id).filter(Boolean);
    const data = await http.post(`document-lines/${type}`, { ids });
    const lines = data?.lines || [];

    const columns = [
        { title: t('date', 'Date'), dataIndex: 'date' },
        { title: t('Reference', 'Reference'), dataIndex: 'Ref' },
        ...(PARTY[type] ? [{ title: t(...PARTY[type]), dataIndex: 'party_name' }] : []),
        { title: t('warehouse', 'Warehouse'), dataIndex: 'warehouse_name' },
        { title: t('Code', 'Code'), dataIndex: 'code' },
        { title: t('ProductName', 'Product'), dataIndex: 'product_name' },
        { title: t('Category', 'Category'), dataIndex: 'category_name' },
        { title: t('Quantity', 'Quantity'), dataIndex: 'quantity' },
        ...(type === 'adjustments' ? [] : [
            { title: t('Unit_Price', 'Unit price'), dataIndex: 'unit_price' },
            { title: t('Discount', 'Discount'), dataIndex: 'discount' },
            { title: t('Tax', 'Tax'), dataIndex: 'tax' },
            { title: t('Total', 'Total'), dataIndex: 'total' },
        ]),
    ];

    await exportExcel(filename, columns, lines);
}
