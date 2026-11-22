/**
 * Confirmation for the "Virtual combo stock" switch (Features page and
 * System Settings → Features). Never blocks: it explains what the switch
 * changes and, when the tenant has documents that would be reversed the
 * wrong way, the server's specific warning (`warning`).
 *
 * `warning` describes leaving the SAVED value, so it is only shown when the
 * new position differs from `saved`. Cancel puts the switch back through
 * `revert`.
 */
import { h, createVNode } from 'vue';
import { Modal } from 'ant-design-vue';
import { ExclamationCircleOutlined } from '@ant-design/icons-vue';

export function confirmVirtualComboSwitch({ t, checked, saved, warning, revert }) {
  const risk = checked !== saved && warning ? warning : null;

  Modal.confirm({
    title: t(checked ? 'Virtual_Combo_Stock_Confirm_On_Title' : 'Virtual_Combo_Stock_Confirm_Off_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: () => h('div', [
      h('p', t(checked ? 'Virtual_Combo_Stock_Confirm_On_Body' : 'Virtual_Combo_Stock_Confirm_Off_Body')),
      risk ? h('p', { style: 'color: #d46b08; margin-bottom: 0' }, risk) : null,
    ]),
    okType: risk ? 'danger' : 'primary',
    okText: t('Confirm'),
    cancelText: t('Cancel'),
    onCancel() {
      revert();
    },
  });
}
