import { ref, computed, createVNode, getCurrentInstance, onBeforeUnmount } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ExclamationCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { i18n, t } from '../i18n';
import { BIG_LIST_ROWS, noteListTotal, forgetList, onBigExportRefused } from '../lib/rowLimit';

/**
 * The server-side list pattern every legacy CRUD page repeats, in one place.
 *
 * Legacy list endpoints all take the same query params (as vue-good-table sent):
 *   page, SortField, SortType, search, limit
 * and answer { <resource>: [...], totalRows: n }.
 *
 *   const crud = useCrudTable('brands', { rowsKey: 'brands' });
 *   crud.fetchRows();
 *
 * rowsKey is optional: without it the first array in the payload is used.
 *
 * `endpoint` may also be a function returning the path, for pages that switch
 * between sibling endpoints (e.g. the fleet report tabs) and want ONE instance
 * so the filters, page size and search survive the switch.
 */
/**
 * "All" page size: a limit far above any real dataset, so one page holds
 * everything. The legacy endpoints plug `limit` straight into the query.
 */
export const ALL_PAGE_SIZE = 100000;
export const PAGE_SIZE_OPTIONS = ['10', '25', '50', '100', String(ALL_PAGE_SIZE)];
const FALLBACK_PAGE_SIZE = 100;
/** The page sizes to offer: no "All" once a list is too big to load at once. */
export const pageSizesFor = (total, options = PAGE_SIZE_OPTIONS) =>
    (Number(total) > BIG_LIST_ROWS ? options.filter(o => Number(o) < ALL_PAGE_SIZE) : options);
/** Labels the ALL_PAGE_SIZE sentinel "All" in the size-changer select. */
export const buildPageSizeOptionText = ({ value }) =>
    (Number(value) >= ALL_PAGE_SIZE ? t('All', 'All') : `${value}`);

function tooBigText(key, fallback) {
    const count = BIG_LIST_ROWS.toLocaleString();
    const out = i18n.global.t(key, { count });
    return out === key ? fallback.replace('{count}', count) : out;
}

// Export buttons turn any failure into a generic error toast in their catch,
// which runs before this timer: replace it with the reason.
onBigExportRefused(() => setTimeout(() => {
    message.destroy();
    message.warning({
        content: tooBigText('List_Too_Big_Export', 'This list has more than {count} rows, which is too many to export at once. Use search or filters to narrow it down.'),
        duration: 8,
    });
}, 0));

/**
 * A 422 is the server deliberately explaining why an action is not allowed
 * (e.g. a unit still has sub-units), so show that reason. Any other failure is
 * infrastructure noise the user can't act on — keep the friendly generic text.
 */
function actionError(e, fallbackKey, fallbackText) {
    if (e?.status === 422 && e?.data?.message) return e.data.message;
    return t(fallbackKey, fallbackText);
}

export function useCrudTable(endpoint, options = {}) {
    const resolveEndpoint = () => (typeof endpoint === 'function' ? endpoint() : endpoint);

    const {
        rowsKey = null,
        limit: initialLimit = 10,
        sortField: initialSortField = 'id',
        sortType: initialSortType = 'desc',
        params = null,          // () => ({ extra: 'query params' })
        // For endpoints that nest their payload, e.g. report/top_suppliers
        // answers { data: { rows, totalRows, kpis }, warehouses }:
        //   select: p => ({ rows: p.data?.rows || [], total: p.data?.totalRows || 0 })
        select = null,
        onError = null,
        // The documents use flat routes (e.g. sales_delete_by_selection)
        // instead of the usual `${endpoint}/delete/by_selection`.
        bulkDeleteEndpoint = null,
        // Endpoints with a server-side page cap pass their own sizes (no "All").
        pageSizeOptions = PAGE_SIZE_OPTIONS,
    } = options;

    const rows = ref([]);
    const total = ref(0);
    const loading = ref(false);
    const page = ref(1);
    const limit = ref(initialLimit);
    const search = ref('');
    const sortField = ref(initialSortField);
    const sortType = ref(initialSortType);
    const selectedIds = ref([]);
    // Last raw response: several endpoints ship extra lists alongside the rows
    // (e.g. units returns Units_base for the base-unit select).
    const payload = ref(null);

    function pickRows(payload) {
        if (!payload || typeof payload !== 'object') return [];
        if (rowsKey && Array.isArray(payload[rowsKey])) return payload[rowsKey];
        const firstArray = Object.values(payload).find(v => Array.isArray(v));
        return firstArray || [];
    }

    const queryParams = () => ({
        page: page.value,
        SortField: sortField.value,
        SortType: sortType.value,
        search: search.value,
        limit: limit.value,
        ...(params ? params() : {}),
    });

    /**
     * "All" picked on a filtered list stays selected when the filter is
     * cleared, so the next load could ask for far too many rows. Count first
     * with a one-row request and fall back to normal paging. Endpoints that
     * report no total are left alone.
     */
    // Endpoint + filters (page/limit aside) of the last probe. The same list
    // reloaded or re-sorted is not counted again when it already fit: the
    // probe exists for a changed filter, not for every fetch.
    let probedSig = null;
    function listSig() {
        const { page: _page, limit: _limit, ...rest } = queryParams() || {};
        return resolveEndpoint() + JSON.stringify(rest);
    }
    async function leaveAllIfTooBig() {
        const sig = listSig();
        if (sig === probedSig && Number(total.value) <= BIG_LIST_ROWS) return;
        const probe = await http.get(resolveEndpoint(), { ...queryParams(), page: 1, limit: 1 });
        const count = Number(select ? select(probe)?.total : probe?.totalRows);
        probedSig = sig;
        if (!(count > BIG_LIST_ROWS)) return;
        limit.value = FALLBACK_PAGE_SIZE;
        page.value = 1;
        message.warning({
            content: tooBigText('List_Too_Big_All', 'This list has more than {count} rows, so the All option is off. Showing 100 rows per page. Use search or filters to narrow it down.'),
            duration: 8,
        });
    }

    let notedEndpoint = null;
    function noteTotal() {
        const endpoint = resolveEndpoint();
        if (notedEndpoint !== null && notedEndpoint !== endpoint) forgetList(notedEndpoint);
        notedEndpoint = endpoint;
        noteListTotal(endpoint, total.value);
    }
    if (getCurrentInstance()) {
        onBeforeUnmount(() => { if (notedEndpoint !== null) forgetList(notedEndpoint); });
    }

    async function fetchRows() {
        loading.value = true;
        try {
            if (limit.value >= ALL_PAGE_SIZE) await leaveAllIfTooBig();
            const data = await http.get(resolveEndpoint(), queryParams());
            payload.value = data;
            if (select) {
                const picked = select(data) || {};
                rows.value = Array.isArray(picked.rows) ? picked.rows : [];
                total.value = Number(picked.total) || rows.value.length;
            } else {
                rows.value = pickRows(data);
                total.value = Number(data?.totalRows) || rows.value.length;
            }
            selectedIds.value = [];
            noteTotal();
        } catch (e) {
            if (onError) onError(e);
            else message.error(t('InvalidData', 'Could not load data'));
        } finally {
            loading.value = false;
        }
    }

    /** Reset to page 1 and refetch — use after search or filter changes. */
    function reload() {
        page.value = 1;
        return fetchRows();
    }

    const pageSizeChoices = computed(() => pageSizesFor(total.value, pageSizeOptions));

    const pagination = computed(() => ({
        current: page.value,
        pageSize: limit.value,
        total: total.value,
        showSizeChanger: true,
        pageSizeOptions: pageSizeChoices.value,
        buildOptionText: buildPageSizeOptionText,
        showTotal: n => `${n}`,
    }));

    /** Wire to <a-table @change>. */
    function onChange(pag, _filters, sorter) {
        const pageChanged = !!pag && (pag.current !== page.value || pag.pageSize !== limit.value);
        if (pag) {
            page.value = pag.current;
            limit.value = pag.pageSize;
        }
        // A function sorter is applied to the loaded rows by a-table itself.
        // Refetching for it only re-downloads the same page — with "All"
        // selected on a big catalogue, that is thousands of rows per header
        // click — so a sort-only change stops here.
        if (!pageChanged && sorter && sorter.column && typeof sorter.column.sorter === 'function') {
            return Promise.resolve();
        }
        // Only columns declaring `sorter: true` sort server-side. Function
        // sorters (auto-injected by DataTable) are handled by a-table on the
        // loaded rows — their fields usually aren't real DB columns, so they
        // must never reach the backend as SortField. `sorter.column` is also
        // unset when a sort is cleared; keeping the previous SortField then
        // matches the old behavior.
        if (sorter && sorter.field && sorter.column && typeof sorter.column.sorter !== 'function') {
            sortField.value = sorter.field;
            sortType.value = sorter.order === 'ascend' ? 'asc' : 'desc';
        }
        return fetchRows();
    }

    /**
     * Delete with the standard confirmation. `label` names the record in the
     * dialog; `detail` adds a line saying what the delete will change;
     * `deleteEndpoint` overrides the default `${endpoint}/${id}`.
     */
    function remove(record, { label = null, detail = null, deleteEndpoint = null } = {}) {
        const name = label || record?.name || '';
        const text = name
            ? `${t('Delete_Text', "You won't be able to revert this!")} — ${name}`
            : t('Delete_Text', "You won't be able to revert this!");
        Modal.confirm({
            title: t('Delete_Title', 'Are you sure?'),
            icon: createVNode(ExclamationCircleOutlined),
            content: detail
                ? createVNode('div', null, [createVNode('p', null, text), createVNode('p', { style: 'font-weight: 600' }, detail)])
                : text,
            okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
            okType: 'danger',
            cancelText: t('Delete_cancelButtonText', 'Cancel'),
            async onOk() {
                try {
                    await http.delete(deleteEndpoint || `${resolveEndpoint()}/${record.id}`);
                    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
                    // Step back a page when the last row of the page is removed.
                    if (rows.value.length === 1 && page.value > 1) page.value--;
                    await fetchRows();
                } catch (e) {
                    message.error(actionError(e, 'InvalidData', 'Could not delete this record'));
                }
            },
        });
    }

    /** Bind to <a-table :row-selection> when a page supports bulk delete. */
    const rowSelection = computed(() => ({
        selectedRowKeys: selectedIds.value,
        onChange: keys => { selectedIds.value = keys; },
    }));

    /**
     * Bulk delete — the legacy contract is POST {endpoint}/delete/by_selection
     * with { selectedIds }.
     */
    function removeSelected() {
        if (!selectedIds.value.length) return;
        Modal.confirm({
            title: t('Delete_Title', 'Are you sure?'),
            icon: createVNode(ExclamationCircleOutlined),
            content: `${t('Delete_Text', "You won't be able to revert this!")} (${selectedIds.value.length})`,
            okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
            okType: 'danger',
            cancelText: t('Delete_cancelButtonText', 'Cancel'),
            async onOk() {
                try {
                    await http.post(bulkDeleteEndpoint || `${resolveEndpoint()}/delete/by_selection`, { selectedIds: selectedIds.value });
                    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
                    selectedIds.value = [];
                    await fetchRows();
                } catch (e) {
                    message.error(actionError(e, 'Delete_Therewassomethingwronge', 'Could not delete the selection'));
                }
            },
        });
    }

    /** Some edit forms need fields the list doesn't return (e.g. category code). */
    function getRecord(id) {
        return http.get(`${resolveEndpoint()}/${id}`);
    }

    return {
        rows, total, loading, page, limit, search, sortField, sortType, payload,
        selectedIds, rowSelection, pagination, pageSizeChoices,
        fetchRows, reload, onChange, remove, removeSelected, getRecord,
    };
}
