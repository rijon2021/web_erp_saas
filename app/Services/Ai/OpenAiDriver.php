<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ChatGPT through the OpenAI Responses API (POST /v1/responses).
 *
 * Takes the same request AiClient builds for Claude (the SDK's camelCase
 * arguments: system, messages, tools, toolChoice, maxTokens, outputConfig)
 * and translates it; the reply comes back as an AiResponse in Claude's block
 * shape, so the features and the stored chat history stay provider-neutral.
 *
 * Requests are stateless (store: false). On reasoning models the encrypted
 * reasoning items are requested and replayed within the same model, which
 * keeps the tool-calling loop coherent.
 */
class OpenAiDriver
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(private string $apiKey, private string $model, private string $effort)
    {
    }

    /** @throws AiException with a message safe to show the user */
    public function create(array $args): AiResponse
    {
        $reasoning = self::isReasoningModel($this->model);

        $payload = [
            'model' => $this->model,
            'input' => $this->input($args['messages'] ?? []),
            'store' => false,
            // Reasoning tokens count toward the cap on these models; a higher
            // ceiling only costs what is actually used.
            'max_output_tokens' => $reasoning ? max(16000, (int) ($args['maxTokens'] ?? 0)) : (int) ($args['maxTokens'] ?? 16000),
        ];

        if ($instructions = $this->instructions($args['system'] ?? null)) {
            $payload['instructions'] = $instructions;
        }
        if ($reasoning) {
            $payload['reasoning'] = ['effort' => $args['outputConfig']['effort'] ?? $this->effort];
            $payload['include'] = ['reasoning.encrypted_content'];
        }
        if (! empty($args['tools'])) {
            $payload['tools'] = array_map(fn ($tool) => [
                'type' => 'function',
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['inputSchema'] ?? $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass],
                // Optional parameters aren't allowed in strict mode.
                'strict' => false,
            ], $args['tools']);
            if (isset($args['toolChoice'])) {
                $payload['tool_choice'] = $this->toolChoice($args['toolChoice']);
            }
        }
        if (($args['outputConfig']['format']['type'] ?? null) === 'json_schema') {
            $payload['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => 'result',
                'schema' => $args['outputConfig']['format']['schema'],
                'strict' => true,
            ]];
        }

        return $this->parse($this->send($payload));
    }

    /** GPT-5 and later, and the o-series (fine-tunes too), take reasoning settings; older chat models reject them. */
    public static function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(ft:)?(o\d|gpt-([5-9]|\d{2}))/', $model);
    }

    private function send(array $payload): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->connectTimeout(15)
                ->timeout(600)
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            throw new AiException(__('messages.ai_unreachable'), previous: $e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $status = $response->status();
        $error = (array) ($response->json('error') ?? []);
        $detail = is_string($error['message'] ?? null) ? mb_substr($error['message'], 0, 300) : '';

        if ($status === 401) {
            throw new AiException(__('messages.ai_invalid_key', ['provider' => 'OpenAI']));
        }
        // Out of credit is a 429 too, but waiting won't fix it.
        if ($status === 429 && ($error['code'] ?? null) !== 'insufficient_quota') {
            throw new AiException(__('messages.ai_rate_limited'));
        }

        report(new \RuntimeException("OpenAI API {$status}: ".mb_substr($response->body(), 0, 1000)));

        // Not transient (bad request, model not enabled for the project, no
        // credit, …): show OpenAI's own reason instead of "try again".
        if (in_array($status, [400, 403, 404, 429], true) && $detail !== '') {
            throw new AiException(__('messages.ai_request_rejected', ['code' => $status, 'detail' => $detail]));
        }
        throw new AiException(__('messages.ai_request_failed', ['code' => $status ?: 'API']));
    }

    private function parse(array $data): AiResponse
    {
        $content = [];
        $refused = false;
        $hasCall = false;
        $reasoningAt = null; // index of the reasoning block the next tool calls belong to

        foreach ($data['output'] ?? [] as $item) {
            switch ($item['type'] ?? null) {
                case 'reasoning':
                    $reasoningAt = null;
                    if (! empty($item['encrypted_content']) && ! empty($item['id'])) {
                        $reasoningAt = count($content);
                        $content[] = ['type' => 'openai_reasoning', 'model' => $this->model, 'item' => [
                            'type' => 'reasoning',
                            'id' => $item['id'],
                            'summary' => $item['summary'] ?? [],
                            'encrypted_content' => $item['encrypted_content'],
                        ], 'call_item_ids' => []];
                    }
                    break;
                case 'message':
                    foreach ($item['content'] ?? [] as $part) {
                        if (($part['type'] ?? null) === 'output_text') {
                            $content[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
                        } elseif (($part['type'] ?? null) === 'refusal') {
                            $refused = true;
                        }
                    }
                    break;
                case 'function_call':
                    $input = json_decode((string) ($item['arguments'] ?? ''), true);
                    // The call's own item id travels with the reasoning block, so a
                    // replay echoes both items exactly as OpenAI returned them.
                    if ($reasoningAt !== null && ! empty($item['id']) && ! empty($item['call_id'])) {
                        $content[$reasoningAt]['call_item_ids'][$item['call_id']] = $item['id'];
                    }
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => (string) ($item['call_id'] ?? ''),
                        'name' => (string) ($item['name'] ?? ''),
                        'input' => is_array($input) ? $input : [],
                    ];
                    $hasCall = true;
                    break;
            }
        }

        $incomplete = ($data['status'] ?? null) === 'incomplete' ? ($data['incomplete_details']['reason'] ?? null) : null;
        $stopReason = match (true) {
            $refused || $incomplete === 'content_filter' => 'refusal',
            $incomplete === 'max_output_tokens' => 'max_tokens',
            $hasCall => 'tool_use',
            default => 'end_turn',
        };

        return new AiResponse(
            $stopReason,
            $content,
            (int) ($data['usage']['input_tokens'] ?? 0),
            (int) ($data['usage']['output_tokens'] ?? 0),
            $data['model'] ?? $this->model,
        );
    }

    private function instructions(mixed $system): string
    {
        if (is_string($system)) {
            return $system;
        }

        return implode("\n\n", array_map(fn ($block) => $block['text'] ?? '', (array) $system));
    }

    /** Claude-shaped messages → Responses API input items. */
    private function input(array $messages): array
    {
        $messages = array_values($messages);

        // The current question: the last user message that isn't only tool
        // results. Reasoning is replayed only after it (OpenAI ignores older
        // reasoning anyway, and an old item breaks the request once the key
        // belongs to another organization).
        $turnStart = -1;
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) === 'user' && ! $this->onlyToolResults($message['content'] ?? '')) {
                $turnStart = $index;
            }
        }

        $items = [];
        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $items[] = ['role' => $role, 'content' => $content];

                continue;
            }

            if ($role === 'user') {
                $parts = [];
                foreach ($content as $block) {
                    switch ($block['type'] ?? null) {
                        case 'text':
                            $parts[] = ['type' => 'input_text', 'text' => $block['text']];
                            break;
                        case 'image':
                            $parts[] = ['type' => 'input_image', 'image_url' => $this->sourceUrl($block['source']), 'detail' => 'auto'];
                            break;
                        case 'document':
                            $parts[] = ['type' => 'input_file', 'filename' => 'document.pdf', 'file_data' => $this->sourceUrl($block['source'])];
                            break;
                        case 'tool_result':
                            $items[] = ['type' => 'function_call_output', 'call_id' => $block['tool_use_id'], 'output' => $this->resultText($block['content'] ?? '')];
                            break;
                    }
                }
                if ($parts) {
                    $items[] = ['role' => 'user', 'content' => $parts];
                }

                continue;
            }

            // Assistant turns. Claude's thinking blocks can't be replayed here
            // and are dropped. Reasoning items go back only within the current
            // question, only to the model that wrote them, and only ahead of the
            // tool call they led to — that's where they matter, and the API
            // rejects one without its output item.
            $content = array_values($content);
            $callIds = [];
            foreach ($content as $i => $block) {
                switch ($block['type'] ?? null) {
                    case 'text':
                        if (($block['text'] ?? '') !== '') {
                            $items[] = ['role' => 'assistant', 'content' => $block['text']];
                        }
                        break;
                    case 'tool_use':
                        $call = [
                            'type' => 'function_call',
                            'call_id' => $block['id'],
                            'name' => $block['name'],
                            'arguments' => json_encode((object) ($block['input'] ?? []), JSON_UNESCAPED_UNICODE),
                        ];
                        if (isset($callIds[$block['id']])) {
                            $call['id'] = $callIds[$block['id']];
                        }
                        $items[] = $call;
                        break;
                    case 'openai_reasoning':
                        if ($index > $turnStart
                            && ($block['model'] ?? null) === $this->model && ! empty($block['item'])
                            && (($content[$i + 1]['type'] ?? null) === 'tool_use')) {
                            $items[] = $block['item'];
                            $callIds = (array) ($block['call_item_ids'] ?? []);
                        }
                        break;
                }
            }
        }

        return $items;
    }

    private function onlyToolResults(mixed $content): bool
    {
        if (! is_array($content) || ! $content) {
            return false;
        }
        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'tool_result') {
                return false;
            }
        }

        return true;
    }

    private function sourceUrl(array $source): string
    {
        return ($source['type'] ?? null) === 'url'
            ? $source['url']
            : 'data:'.$source['media_type'].';base64,'.$source['data'];
    }

    private function resultText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode("\n", array_map(fn ($block) => $block['text'] ?? '', (array) $content));
    }

    private function toolChoice(array $choice): string|array
    {
        return match ($choice['type'] ?? 'auto') {
            'none' => 'none',
            'any' => 'required',
            'tool' => ['type' => 'function', 'name' => $choice['name']],
            default => 'auto',
        };
    }
}
