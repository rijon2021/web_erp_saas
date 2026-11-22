<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Gemini through the Gemini API (POST /v1beta/models/{model}:generateContent).
 *
 * Takes the same request AiClient builds for Claude (the SDK's camelCase
 * arguments: system, messages, tools, toolChoice, maxTokens, outputConfig)
 * and translates it; the reply comes back as an AiResponse in Claude's block
 * shape, so the features and the stored chat history stay provider-neutral.
 *
 * Thought signatures: Gemini 3+ signs its parts and requires the signed
 * function-call parts of the current question back unchanged. The raw parts
 * are therefore kept in a `gemini_parts` block next to the neutral blocks and
 * replayed verbatim to the same model; tool calls from any other source get
 * Google's documented placeholder signature.
 */
class GeminiDriver
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Google's documented value for function calls the model didn't produce itself. */
    private const FOREIGN_SIGNATURE = 'skip_thought_signature_validator';

    /** Ids made up for calls Gemini returned without one (older models); never sent back. */
    private const LOCAL_ID_PREFIX = 'gmcall_';

    private const MAX_REMOTE_IMAGE_BYTES = 5_000_000;

    /** Thinking tokens per effort for Gemini 2.5 (within every 2.5 model's allowed range). */
    private const THINKING_BUDGETS = ['low' => 1024, 'medium' => 8192, 'high' => 24576];

    public function __construct(private string $apiKey, private string $model, private string $effort)
    {
    }

    /** @throws AiException with a message safe to show the user */
    public function create(array $args): AiResponse
    {
        $thinking = self::isThinkingModel($this->model);

        $generation = [
            // Thinking counts toward the cap; a higher ceiling only costs what is used.
            'maxOutputTokens' => $thinking ? max(16000, (int) ($args['maxTokens'] ?? 0)) : (int) ($args['maxTokens'] ?? 16000),
        ];
        if ($thinking) {
            $effort = $args['outputConfig']['effort'] ?? $this->effort;
            // Gemini 2.5 takes a token budget; Gemini 3+ a level (and rejects a budget).
            $generation['thinkingConfig'] = str_starts_with($this->model, 'gemini-2.5')
                ? ['thinkingBudget' => self::THINKING_BUDGETS[$effort] ?? self::THINKING_BUDGETS['medium']]
                : ['thinkingLevel' => $effort];
        }
        if (($args['outputConfig']['format']['type'] ?? null) === 'json_schema') {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseJsonSchema'] = $args['outputConfig']['format']['schema'];
        }

        $payload = [
            'contents' => $this->contents($args['messages'] ?? []),
            'generationConfig' => $generation,
        ];
        if ($instructions = $this->instructions($args['system'] ?? null)) {
            $payload['systemInstruction'] = ['parts' => [['text' => $instructions]]];
        }
        if (! empty($args['tools'])) {
            $payload['tools'] = [['functionDeclarations' => array_map(fn ($tool) => $this->declaration($tool), $args['tools'])]];
            if (isset($args['toolChoice'])) {
                $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => $this->toolMode($args['toolChoice'])]];
            }
        }

        return $this->parse($this->send($payload));
    }

    /** Gemini 2.5 and 3+ take a thinking level; older models reject it. */
    public static function isThinkingModel(string $model): bool
    {
        return (bool) preg_match('/^gemini-(2\.5|[3-9]|\d{2})/', $model);
    }

    private function declaration(array $tool): array
    {
        $declaration = ['name' => $tool['name'], 'description' => $tool['description'] ?? ''];
        $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? null;
        // A tool without parameters is declared without a schema.
        if (is_array($schema) && ! empty((array) ($schema['properties'] ?? []))) {
            $declaration['parametersJsonSchema'] = $schema;
        }

        return $declaration;
    }

    private function toolMode(array $choice): string
    {
        return match ($choice['type'] ?? 'auto') {
            'none' => 'NONE',
            'any', 'tool' => 'ANY',
            default => 'AUTO',
        };
    }

    private function send(array $payload): array
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->connectTimeout(15)
                ->timeout(600)
                ->post(sprintf(self::ENDPOINT, rawurlencode($this->model)), $payload);
        } catch (ConnectionException $e) {
            throw new AiException(__('messages.ai_unreachable'), previous: $e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $status = $response->status();
        $error = (array) ($response->json('error') ?? []);
        $detail = is_string($error['message'] ?? null) ? mb_substr($error['message'], 0, 300) : '';
        $reasons = collect($error['details'] ?? [])->pluck('reason')->filter()->all();

        // A bad key comes back as 400 API_KEY_INVALID.
        if (in_array($status, [401, 403], true) || in_array('API_KEY_INVALID', $reasons, true) || str_contains($detail, 'API key not valid')) {
            throw new AiException(__('messages.ai_invalid_key', ['provider' => 'Gemini']));
        }
        // Busy / overloaded. A spent quota is a 429 too, but waiting won't fix it.
        if (in_array($status, [429, 503], true) && ! str_contains(strtolower($detail), 'quota')) {
            throw new AiException(__('messages.ai_rate_limited'));
        }

        report(new \RuntimeException("Gemini API {$status}: ".mb_substr($response->body(), 0, 1000)));

        // Not transient (bad request, unknown model, quota spent, …): show
        // Google's own reason instead of "try again".
        if (in_array($status, [400, 404, 429], true) && $detail !== '') {
            throw new AiException(__('messages.ai_request_rejected', ['code' => $status, 'detail' => $detail]));
        }
        throw new AiException(__('messages.ai_request_failed', ['code' => $status ?: 'API']));
    }

    private function parse(array $data): AiResponse
    {
        $usage = (array) ($data['usageMetadata'] ?? []);
        // promptTokenCount already includes cached tokens; output is billed
        // as response + thinking.
        $input = (int) ($usage['promptTokenCount'] ?? 0) + (int) ($usage['toolUsePromptTokenCount'] ?? 0);
        $output = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
        $model = $data['modelVersion'] ?? $this->model;

        $candidate = $data['candidates'][0] ?? null;
        if (! $candidate) {
            // The prompt itself was blocked.
            return new AiResponse(! empty($data['promptFeedback']['blockReason']) ? 'refusal' : 'end_turn', [], $input, $output, $model);
        }

        $parts = array_values((array) ($candidate['content']['parts'] ?? []));
        $content = [];
        $signed = false;
        $hasCall = false;
        foreach ($parts as $part) {
            $signed = $signed || ! empty($part['thoughtSignature']);
            if (! empty($part['thought'])) {
                continue; // thought summaries aren't part of the answer
            }
            if (isset($part['text'])) {
                $content[] = ['type' => 'text', 'text' => (string) $part['text']];
            } elseif (isset($part['functionCall'])) {
                $call = $part['functionCall'];
                $args = $call['args'] ?? [];
                $content[] = [
                    'type' => 'tool_use',
                    'id' => ! empty($call['id']) ? (string) $call['id'] : self::LOCAL_ID_PREFIX.Str::random(16),
                    'name' => (string) ($call['name'] ?? ''),
                    'input' => is_array($args) ? $args : [],
                ];
                $hasCall = true;
            }
        }
        // The exact parts, signatures included, for replay to this model.
        if ($signed) {
            array_unshift($content, ['type' => 'gemini_parts', 'model' => $this->model, 'parts' => $parts]);
        }

        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        if (in_array($finish, ['MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL', 'TOO_MANY_TOOL_CALLS', 'MISSING_THOUGHT_SIGNATURE', 'MALFORMED_RESPONSE'], true)) {
            report(new \RuntimeException("Gemini finishReason {$finish}"));
            throw new AiException(__('messages.ai_request_failed', ['code' => $finish]));
        }
        $stopReason = match (true) {
            in_array($finish, ['MAX_TOKENS', 'CONTINUATION'], true) => 'max_tokens',
            $hasCall => 'tool_use',
            in_array($finish, ['STOP', 'FINISH_REASON_UNSPECIFIED', 'OTHER'], true) => 'end_turn',
            // SAFETY, RECITATION, BLOCKLIST, PROHIBITED_CONTENT, SPII, LANGUAGE, …
            default => 'refusal',
        };

        return new AiResponse($stopReason, $content, $input, $output, $model);
    }

    private function instructions(mixed $system): string
    {
        if (is_string($system)) {
            return $system;
        }

        return implode("\n\n", array_map(fn ($block) => $block['text'] ?? '', (array) $system));
    }

    /** Claude-shaped messages → Gemini contents. */
    private function contents(array $messages): array
    {
        $contents = [];
        $calls = []; // tool_use id => [function name, whether Gemini knows this id]

        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $content = [['type' => 'text', 'text' => $content]];
            }

            if (($message['role'] ?? 'user') === 'user') {
                $parts = [];
                foreach ($content as $block) {
                    switch ($block['type'] ?? null) {
                        case 'text':
                            $parts[] = ['text' => (string) $block['text']];
                            break;
                        case 'image':
                        case 'document':
                            if ($inline = $this->inlineData($block['source'] ?? [])) {
                                $parts[] = ['inlineData' => $inline];
                            }
                            break;
                        case 'tool_result':
                            [$name, $known] = $calls[$block['tool_use_id']] ?? ['', false];
                            $response = ['name' => $name, 'response' => ['result' => $this->resultValue($block['content'] ?? '')]];
                            if ($known) {
                                $response['id'] = $block['tool_use_id'];
                            }
                            $parts[] = ['functionResponse' => $response];
                            break;
                    }
                }
                $this->append($contents, 'user', $parts);

                continue;
            }

            // Assistant turn: Gemini's own parts go back verbatim to the model
            // that wrote them; anything else is rebuilt from the neutral blocks.
            $raw = collect($content)->first(fn ($block) => ($block['type'] ?? null) === 'gemini_parts' && ($block['model'] ?? null) === $this->model);
            $rawIds = [];
            foreach ((array) ($raw['parts'] ?? []) as $part) {
                if (! empty($part['functionCall']['id'])) {
                    $rawIds[$part['functionCall']['id']] = true;
                }
            }

            $parts = [];
            $signedFirstCall = false;
            foreach ($content as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    if (! $raw && ($block['type'] ?? null) === 'text' && ($block['text'] ?? '') !== '') {
                        $parts[] = ['text' => $block['text']];
                    }

                    continue;
                }
                $calls[$block['id']] = [$block['name'], $raw ? isset($rawIds[$block['id']]) : false];
                if ($raw) {
                    continue;
                }
                $part = ['functionCall' => ['name' => $block['name'], 'args' => (object) ($block['input'] ?? [])]];
                // Parallel calls carry one signature, on the first call.
                if (! $signedFirstCall) {
                    $part['thoughtSignature'] = self::FOREIGN_SIGNATURE;
                    $signedFirstCall = true;
                }
                $parts[] = $part;
            }
            if ($raw) {
                $parts = $raw['parts'];
            }
            $this->append($contents, 'model', $parts);
        }

        return $contents;
    }

    /** Adds a turn, merging into the previous one of the same role (a dropped turn can leave two in a row). */
    private function append(array &$contents, string $role, array $parts): void
    {
        if (! $parts) {
            return;
        }
        $last = array_key_last($contents);
        if ($last !== null && $contents[$last]['role'] === $role) {
            array_push($contents[$last]['parts'], ...$parts);

            return;
        }
        $contents[] = ['role' => $role, 'parts' => array_values($parts)];
    }

    /** Tool output as a JSON value (the tools return JSON strings). */
    private function resultValue(mixed $content): mixed
    {
        if (is_array($content)) {
            $content = implode("\n", array_map(fn ($block) => $block['text'] ?? '', $content));
        }
        $decoded = json_decode((string) $content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $content;
    }

    /** A Claude image/document source as Gemini inline data (remote images are fetched here). */
    private function inlineData(array $source): ?array
    {
        if (($source['type'] ?? null) === 'base64') {
            return ['mimeType' => $source['media_type'], 'data' => $source['data']];
        }
        if (($source['type'] ?? null) === 'url') {
            return $this->fetchImage((string) $source['url']);
        }

        return null;
    }

    /**
     * Downloads a public product photo. The URL is tenant-supplied, so only
     * https to a public address is fetched, without following redirects;
     * anything else just leaves the image out.
     */
    private function fetchImage(string $url): ?array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! $host) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $max = self::MAX_REMOTE_IMAGE_BYTES;
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                // Connect to the address checked above, so the name can't be
                // re-pointed at an internal one between the check and the request.
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]],
                // Stop oversized downloads early instead of buffering them.
                'on_headers' => function ($r) use ($max) {
                    if ((int) $r->getHeaderLine('Content-Length') > $max) {
                        throw new \RuntimeException('Image too large');
                    }
                },
                'progress' => function ($total, $downloaded) use ($max) {
                    if ($downloaded > $max) {
                        throw new \RuntimeException('Image too large');
                    }
                },
            ])->connectTimeout(5)->timeout(15)->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        $body = $response->body();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! $response->successful() || strlen($body) > self::MAX_REMOTE_IMAGE_BYTES
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        return ['mimeType' => $mime, 'data' => base64_encode($body)];
    }
}
