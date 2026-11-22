import { ref } from 'vue';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';
import { usePlanStore } from '../stores/plan';

/**
 * Whether the AI assistant is set up and this user may use it (plan feature
 * `ai_assistant` + permission)
 * (GET ai/status, fetched once per page load and shared).
 */
const status = ref(null);
let pending = null;

export function useAiStatus() {
  const auth = useAuthStore();
  const plan = usePlanStore();

  async function load() {
    if (status.value) return status.value;
    if (!plan.featureEnabled('ai_assistant') || !auth.can('ai_assistant')) {
      status.value = { ready: false };
      return status.value;
    }
    pending = pending || http.get('ai/status')
      .then(data => { status.value = data || { ready: false }; })
      .catch(() => { status.value = { ready: false }; })
      .finally(() => { pending = null; });
    await pending;
    return status.value;
  }

  return { status, load };
}
