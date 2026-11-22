import { ref } from 'vue';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';
import { usePlanStore } from '../stores/plan';

/**
 * Shared state for the AI product writer: whether it can be offered to this
 * user (plan feature + ai_assistant permission + the assistant set up) and its options
 * (languages, tones, lengths). Fetched once per page load and shared by the
 * product form button and the bulk writer.
 */
const options = ref(null);
let pending = null;

export function useAiWriter() {
  const auth = useAuthStore();
  const plan = usePlanStore();

  async function load() {
    if (options.value) return options.value;
    if (!plan.featureEnabled('ai_assistant') || !auth.can('ai_assistant') || !(auth.can('products_add') || auth.can('products_edit'))) {
      options.value = { ready: false };
      return options.value;
    }
    pending = pending || http.get('ai/product-writer/options')
      .then(data => { options.value = data || { ready: false }; })
      .catch(() => { options.value = { ready: false }; })
      .finally(() => { pending = null; });
    await pending;
    return options.value;
  }

  return { options, load };
}
