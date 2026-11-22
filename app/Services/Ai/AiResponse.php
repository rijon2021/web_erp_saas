<?php

namespace App\Services\Ai;

use Anthropic\Beta\Messages\BetaMessage;

/**
 * One model response, the same shape whichever provider produced it.
 *
 * `content` is a list of plain wire-format blocks in Claude's shape (text,
 * tool_use, thinking, …) so it can be stored and replayed as-is; ChatGPT's
 * and Gemini's replies are translated into it, with their provider-only
 * state kept as `openai_reasoning` / `gemini_parts` blocks that only their
 * own driver sends back (and the others drop).
 * `stopReason` uses Claude's names: end_turn, tool_use, max_tokens, refusal.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $stopReason,
        public readonly array $content,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?string $model = null,
    ) {
    }

    public static function fromAnthropic(BetaMessage $message): self
    {
        $u = $message->usage;

        return new self(
            (string) $message->stopReason,
            array_map(fn ($block) => json_decode(json_encode($block), true), $message->content),
            // Uncached + cache writes + cache reads.
            (int) ($u->inputTokens ?? 0) + (int) ($u->cacheCreationInputTokens ?? 0) + (int) ($u->cacheReadInputTokens ?? 0),
            (int) ($u->outputTokens ?? 0),
            $message->model,
        );
    }

    /** All text blocks joined. */
    public function text(): string
    {
        $parts = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parts[] = $block['text'] ?? '';
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /** The first text block that decodes to a JSON object (structured output). */
    public function json(): ?array
    {
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parsed = json_decode($block['text'] ?? '', true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    /** @return array<int, array{id: string, name: string, input: array}> */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $input = $block['input'] ?? [];
                $calls[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => is_array($input) ? $input : []];
            }
        }

        return $calls;
    }
}
