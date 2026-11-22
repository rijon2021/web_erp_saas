/**
 * The server answers a stock import whose rows were already posted (and still
 * stand) with { duplicate: true, message } instead of importing. This asks the
 * user; on "Import again" the page resends with confirm_duplicate=1.
 */
import { Modal } from 'ant-design-vue';
import { t } from '../i18n';

export function isDuplicateImport(data) {
    return !!(data && typeof data === 'object' && data.duplicate === true);
}

export function confirmDuplicateImport(data) {
    return new Promise((resolve) => {
        Modal.confirm({
            title: t('Imp_Duplicate_Title', 'This file was already imported'),
            content: data?.message || '',
            okText: t('Imp_Duplicate_Import_Again', 'Import again'),
            okType: 'danger',
            cancelText: t('Cancel', 'Cancel'),
            onOk: () => resolve(true),
            onCancel: () => resolve(false),
        });
    });
}
