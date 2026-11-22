<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One chat exchange: replay the conversation, let the model (Claude or
 * ChatGPT) call the read-only data tools until it answers, store the exchange.
 *
 * History is append-only: each exchange's exact API messages (assistant
 * content incl. thinking and tool calls, plus the tool results) are stored in
 * ai_messages.transcript and replayed unchanged on the next question — the
 * models only accept thinking blocks in the history that produced them, and
 * an unchanged prefix keeps the prompt cache warm. A failed or refused
 * exchange stores no transcript, so it is simply not replayed.
 *
 * Transcripts are in Claude's block shape whichever provider answered
 * (see AiResponse), so a conversation carries on after the provider is
 * switched; each driver drops the other's reasoning blocks.
 */
class AiAssistant
{
    private const MAX_TOOL_ROUNDS = 8;

    /** Conversation history replayed per request (oldest exchanges dropped beyond this). */
    private const MAX_EXCHANGES = 20;

    public function __construct(private AiClient $client)
    {
    }

    public function ask(AiConversation $conversation, User $user, string $question): AiMessage
    {
        $scope = new AiScope($user);
        $tools = new AiDataTools($scope);
        $definitions = $tools->definitions();

        $history = $this->history($conversation);
        // The per-turn context (date, user, visibility) travels inside the
        // stored user message, so the system prompt and tools never change.
        $turn = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $this->turnContext($user, $scope, $tools)],
            ['type' => 'text', 'text' => $question],
        ]]];
        $toolsUsed = [];
        $tokens = ['input' => 0, 'output' => 0];
        $final = null;

        try {
            for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
                $args = [
                    'system' => [['type' => 'text', 'text' => self::SYSTEM_PROMPT]],
                    'messages' => array_merge($history, $turn),
                    'tools' => $definitions,
                    // Safety net for replayed thinking blocks whose history no
                    // longer matches (oldest exchanges trimmed, model switched in
                    // settings): drop them instead of failing the request.
                    'thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']],
                    'betas' => ['thinking-binding-controls-2026-08-01'],
                ];
                // Out of rounds: ask for the answer with what it has.
                if ($round === self::MAX_TOOL_ROUNDS) {
                    $args['toolChoice'] = ['type' => 'none'];
                }

                $response = $this->client->create($args);
                $used = AiClient::usage($response);
                $tokens['input'] += $used['input'];
                $tokens['output'] += $used['output'];

                if ($response->stopReason === 'refusal') {
                    // Not replayable (no usable content); shown as a friendly error.
                    return $this->store($conversation, $question, null, null, $toolsUsed, $tokens, __('messages.ai_refused'));
                }

                $turn[] = ['role' => 'assistant', 'content' => $response->content];

                if ($response->stopReason !== 'tool_use') {
                    $final = $response;
                    break;
                }

                $results = [];
                foreach ($response->toolCalls() as $call) {
                    $toolsUsed[] = ['name' => $call['name'], 'input' => $call['input']];
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $call['id'],
                        'content' => $tools->run($call['name'], $call['input']),
                    ];
                }
                // All results of one round go back in a single user message.
                $turn[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiException $e) {
            return $this->store($conversation, $question, null, null, $toolsUsed, $tokens, $e->getMessage());
        }

        $answer = $final ? $final->text() : '';
        if ($final && $final->stopReason === 'max_tokens') {
            $answer .= "\n\n_".__('messages.ai_truncated').'_';
        }

        return $this->store($conversation, $question, $turn, $answer, $toolsUsed, $tokens, null);
    }

    /** Stored transcripts, oldest first, ready to send back unchanged. */
    private function history(AiConversation $conversation): array
    {
        $rows = AiMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('transcript')
            ->orderByDesc('id')
            ->limit(self::MAX_EXCHANGES)
            ->get()
            ->reverse();

        $messages = [];
        foreach ($rows as $row) {
            foreach ($row->transcript ?? [] as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function store(AiConversation $conversation, string $question, ?array $transcript, ?string $answer, array $toolsUsed, array $tokens, ?string $error): AiMessage
    {
        $message = AiMessage::create([
            'conversation_id' => $conversation->id,
            'question' => $question,
            'answer' => $answer,
            'transcript' => $transcript,
            'tools_used' => $toolsUsed ?: null,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'input_tokens' => $tokens['input'],
            'output_tokens' => $tokens['output'],
            'created_at' => now(),
        ]);

        $conversation->input_tokens += $tokens['input'];
        $conversation->output_tokens += $tokens['output'];
        if (! $conversation->title) {
            $conversation->title = Str::limit(preg_replace('/\s+/', ' ', $question), 80);
        }
        $conversation->touch();
        $conversation->save();

        return $message;
    }

    /**
     * Frozen system prompt: identical on every request of every conversation.
     * Anything that varies (date, user, permissions) goes in turnContext().
     */
    private const SYSTEM_PROMPT = <<<'TXT'
You are the business assistant inside Stocky, an inventory, POS and accounting system. You help the signed-in staff member understand their business by answering questions with the data tools provided.

Each user message begins with a <context> block written by Stocky (not by the user): today's date, the company and base currency, who is asking, which warehouses and records they can see, and which tools they may use. Always rely on the most recent <context>.

How to work:
- Answer from tool results only. Never invent figures; if the tools can't answer, say what's missing and which Stocky report or screen would show it.
- Use the tools freely and in parallel when a question needs several figures. Resolve relative dates ("last month", "this week", "yesterday") against today's date in the latest context, and state the exact period you used.
- Only call tools listed as available in the latest context. Tools return only what this user may see; if something is outside their access, tell them plainly — don't guess around it.
- All amounts are in the base currency. Profit figures from the tools are estimates (line totals minus current product cost); say so when you report them.
- You are read-only: you cannot create, edit or delete anything. If asked to, explain where in Stocky they can do it.

How to answer:
- Lead with the direct answer, then the supporting numbers. Keep it short; this is a chat panel.
- Use Markdown: short paragraphs, bullet lists, and tables for comparisons or rankings (keep tables under about 12 rows).
- Format money with the currency symbol and two decimals, and use thousands separators.
- Reply in the language the user writes in.
TXT;

    /** Per-turn facts, stored with the user message so history replays unchanged. */
    private function turnContext(User $user, AiScope $scope, AiDataTools $tools): string
    {
        $setting = Setting::whereNull('deleted_at')->first();
        $currency = $setting ? \App\Models\Currency::find($setting->currency_id) : null;
        $warehouses = \Illuminate\Support\Facades\DB::table('warehouses')->whereIn('id', $scope->warehouseIds)->whereNull('deleted_at')->pluck('name')->implode(', ');

        return sprintf(
            "<context>\nToday: %s (%s).\nCompany: %s.\nBase currency: %s%s.\nSigned-in user: %s.\nWarehouses visible to this user: %s.\nRecord visibility: %s.\nTools available to this user: %s.\n</context>",
            now()->toDateString(),
            now()->format('l'),
            $setting->CompanyName ?? 'Stocky',
            $currency->code ?? '',
            $currency && $currency->symbol ? " ({$currency->symbol})" : '',
            trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: $user->username,
            $warehouses ?: 'none',
            $scope->allRecords ? 'all records' : 'only records this user created',
            implode(', ', $tools->available())
        );
    }
}
