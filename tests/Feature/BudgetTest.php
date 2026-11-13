<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Stolic\AiCaptioning\Agents\ImageDescriberAgent;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Budget\CostEstimator;
use Stolic\AiCaptioning\Budget\SpendLedger;
use Stolic\AiCaptioning\Contracts\ImageDescriber;
use Stolic\AiCaptioning\Data\CostEstimate;
use Stolic\AiCaptioning\Enums\SkipReason;
use Stolic\AiCaptioning\Events\BudgetExceeded;
use Stolic\AiCaptioning\Events\BudgetWarning;
use Stolic\AiCaptioning\Events\ImageDescribed;
use Stolic\AiCaptioning\Events\ImageDescriptionSkipped;
use Stolic\AiCaptioning\Notifications\AiCaptioningBudgetNotification;

/**
 * Fake the agent with a response that reports token usage.
 */
function fakeAgentWithUsage(int $inputTokens = 1_000_000, int $outputTokens = 0, string $model = 'test-model'): void
{
    $fields = ['caption' => 'A library', 'alt' => 'Shelves of books', 'kind' => 'photo', 'people_visible' => false, 'concerns' => []];

    ImageDescriberAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
        $fields,
        (string) json_encode($fields),
        new Usage(promptTokens: $inputTokens, completionTokens: $outputTokens),
        new Meta('gemini', $model),
    ));
}

beforeEach(function (): void {
    config()->set('ai-captioning.auto_describe', false);
    config()->set('ai-captioning.daily_cap', null);
    config()->set('ai-captioning.budget.fallback_cost_usd', 0.002);
    config()->set('ai-captioning.pricing', [
        'test-model' => ['input_per_million' => 1.00, 'output_per_million' => 4.00, 'per_image' => 0.0005],
    ]);
});

it('estimates the cost from token usage and the model price', function (): void {
    $estimate = app(CostEstimator::class)->estimate('gemini', 'test-model', new Usage(
        promptTokens: 1000,
        completionTokens: 200,
        cacheReadInputTokens: 500,
        reasoningTokens: 300,
    ));

    // (1000 + 500) / 1M x $1 + (200 + 300) / 1M x $4 + $0.0005 per image
    expect($estimate->usd)->toBe(0.004)
        ->and($estimate->inputTokens)->toBe(1500)
        ->and($estimate->outputTokens)->toBe(500)
        ->and($estimate->source)->toBe(CostEstimate::SOURCE_USAGE);
});

it('uses the flat fallback when the model has no price or no usage was reported', function (?string $model, ?Usage $usage): void {
    $estimate = app(CostEstimator::class)->estimate('gemini', $model, $usage);

    expect($estimate->usd)->toBe(0.002)
        ->and($estimate->isFallback())->toBeTrue();
})->with([
    'unpriced model' => ['unknown-model', new Usage(promptTokens: 1000, completionTokens: 100)],
    'no usage' => ['test-model', null],
    'zero usage (fakes, some providers)' => ['test-model', new Usage],
]);

it('adds each call to the ledger and passes usage and cost to ImageDescribed', function (): void {
    Event::fake([ImageDescribed::class]);
    fakeAgentWithUsage(inputTokens: 1000, outputTokens: 250);

    app(AiCaptioning::class)->describe(addImage('documents'));
    app(AiCaptioning::class)->describe(addImage('documents'));

    $row = DB::table('ai_captioning_spend')->sole();
    expect($row->provider)->toBe('gemini')
        ->and($row->model)->toBe('test-model')
        ->and((int) $row->calls)->toBe(2)
        ->and((int) $row->input_tokens)->toBe(2000)
        ->and((int) $row->output_tokens)->toBe(500)
        ->and(app(AiCaptioning::class)->spend())->toBe(0.005) // 2 x (0.001 + 0.001 + 0.0005)
        ->and(app(AiCaptioning::class)->spend('total'))->toBe(0.005);

    Event::assertDispatched(ImageDescribed::class, fn (ImageDescribed $event): bool => $event->description->usage?->promptTokens === 1000
        && $event->cost?->usd === 0.0025
        && $event->cost->source === CostEstimate::SOURCE_USAGE);
});

it('counts the fallback for a failed call', function (): void {
    app()->instance(ImageDescriber::class, new class implements ImageDescriber
    {
        public function describe(string $absolutePath, string $mimeType, array $context, string $profile = 'default'): never
        {
            throw new RuntimeException('Provider down');
        }
    });

    expect(fn () => app(AiCaptioning::class)->describe(addImage('documents')))->toThrow(RuntimeException::class);

    $row = DB::table('ai_captioning_spend')->sole();
    expect($row->provider)->toBe('gemini')
        ->and((int) $row->fallback_calls)->toBe(1)
        ->and((float) $row->estimated_usd)->toBe(0.002);
});

it('warns once per period when the spend crosses warn_at', function (): void {
    Event::fake([BudgetWarning::class, BudgetExceeded::class]);
    app(AiCaptioning::class)->budgetUsing(fn (): float => 10.0);
    app(SpendLedger::class)->add(new CostEstimate(7.9, 'gemini', 'test-model'));
    fakeAgentWithUsage(inputTokens: 50_000); // $0.0505 per call

    app(AiCaptioning::class)->describe(addImage('documents')); // $7.9505: below 80%
    Event::assertNotDispatched(BudgetWarning::class);

    app(AiCaptioning::class)->describe(addImage('documents')); // $8.001: warn
    app(AiCaptioning::class)->describe(addImage('documents')); // $8.0515: already warned this period

    Event::assertDispatchedTimes(BudgetWarning::class, 1);
    Event::assertDispatched(BudgetWarning::class, fn (BudgetWarning $event): bool => abs($event->spendUsd - 8.001) < 0.000001
        && $event->limitUsd === 10.0
        && $event->warnAt === 0.8
        && $event->period === 'month'
        && $event->periodKey === now()->format('Y-m'));
    Event::assertNotDispatched(BudgetExceeded::class);
    expect(app(AiCaptioning::class)->isPaused())->toBeFalse();
});

it('pauses at the limit, dispatches BudgetExceeded once and skips later calls without asking the model', function (): void {
    Event::fake([BudgetExceeded::class, BudgetWarning::class, ImageDescriptionSkipped::class]);
    config()->set('ai-captioning.budget.limit_usd', 10.00);
    fakeAgentWithUsage(inputTokens: 6_000_000); // ~$6 per call

    app(AiCaptioning::class)->describe(addImage('documents'));
    app(AiCaptioning::class)->describe(addImage('documents')); // ~$12: pause

    expect(app(AiCaptioning::class)->isPaused())->toBeTrue()
        ->and(app(AiCaptioning::class)->budgetStatus()->pauseKind)->toBe('budget');

    $media = addImage('documents');
    expect(app(AiCaptioning::class)->describe($media))->toBeNull()
        ->and(app(AiCaptioning::class)->describe($media))->toBeNull();

    ImageDescriberAgent::assertPrompted(fn (): bool => true);
    expect(DB::table('ai_captioning_spend')->sum('calls'))->toEqual(2);
    Event::assertDispatchedTimes(BudgetExceeded::class, 1);
    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event): bool => $event->limitUsd === 10.0 && $event->spendUsd > 12.0);
    Event::assertNotDispatched(BudgetWarning::class);
    Event::assertDispatched(ImageDescriptionSkipped::class, fn (ImageDescriptionSkipped $event): bool => $event->reason === SkipReason::BudgetExceeded);
});

it('pauses before calling when the spend already reached a lowered limit', function (): void {
    Event::fake([BudgetExceeded::class]);
    fakeAgentWithUsage(inputTokens: 1_000_000);
    app(AiCaptioning::class)->describe(addImage('documents'));
    app(AiCaptioning::class)->budgetUsing(fn (): float => 0.5);

    expect(app(AiCaptioning::class)->describe(addImage('documents')))->toBeNull()
        ->and(app(AiCaptioning::class)->isPaused())->toBeTrue();
    expect(DB::table('ai_captioning_spend')->sum('calls'))->toEqual(1);
    Event::assertDispatchedTimes(BudgetExceeded::class, 1);
});

it('lifts a budget pause in a new month but keeps a manual pause', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 20));
    config()->set('ai-captioning.budget.limit_usd', 1.00);
    fakeAgentWithUsage(inputTokens: 2_000_000);

    app(AiCaptioning::class)->describe(addImage('documents'));
    expect(app(AiCaptioning::class)->budgetStatus()->pauseKind)->toBe('budget');

    $this->travelTo(now()->setDate(2026, 12, 1));
    expect(app(AiCaptioning::class)->isPaused())->toBeFalse()
        ->and(app(AiCaptioning::class)->spend())->toBe(0.0)
        ->and(app(AiCaptioning::class)->spend('2026-11'))->toBe(2.0005);

    app(AiCaptioning::class)->pause('Checking captions by hand');
    $this->travelTo(now()->setDate(2027, 1, 3));

    expect(app(AiCaptioning::class)->isPaused())->toBeTrue()
        ->and(app(AiCaptioning::class)->describe(addImage('documents')))->toBeNull()
        ->and(app(AiCaptioning::class)->budgetStatus()->pauseReason)->toBe('Checking captions by hand');
});

it('keeps a budget pause for period total until the limit is raised', function (): void {
    config()->set('ai-captioning.budget.period', 'total');
    config()->set('ai-captioning.budget.limit_usd', 1.00);
    fakeAgentWithUsage(inputTokens: 2_000_000);

    app(AiCaptioning::class)->describe(addImage('documents'));
    $this->travel(40)->days();

    expect(app(AiCaptioning::class)->isPaused())->toBeTrue();

    config()->set('ai-captioning.budget.limit_usd', 5.00);
    expect(app(AiCaptioning::class)->isPaused())->toBeFalse();
});

it('skips with Paused while manually paused and runs again after resume', function (): void {
    Event::fake([ImageDescriptionSkipped::class]);
    fakeAgentWithUsage(inputTokens: 1000);
    app(AiCaptioning::class)->pause('Maintenance');

    expect(app(AiCaptioning::class)->describe(addImage('documents')))->toBeNull();
    ImageDescriberAgent::assertNeverPrompted();
    Event::assertDispatched(ImageDescriptionSkipped::class, fn (ImageDescriptionSkipped $event): bool => $event->reason === SkipReason::Paused);

    app(AiCaptioning::class)->resume();

    expect(app(AiCaptioning::class)->isPaused())->toBeFalse()
        ->and(app(AiCaptioning::class)->describe(addImage('documents')))->not->toBeNull();
});

it('does not repeat BudgetExceeded when resumed while still over the limit', function (): void {
    Event::fake([BudgetExceeded::class]);
    config()->set('ai-captioning.budget.limit_usd', 1.00);
    fakeAgentWithUsage(inputTokens: 2_000_000);
    app(AiCaptioning::class)->describe(addImage('documents'));

    app(AiCaptioning::class)->resume();

    expect(app(AiCaptioning::class)->describe(addImage('documents')))->toBeNull()
        ->and(app(AiCaptioning::class)->isPaused())->toBeTrue();
    Event::assertDispatchedTimes(BudgetExceeded::class, 1);
});

it('shows the budget and pauses and resumes from the command', function (): void {
    config()->set('ai-captioning.budget.limit_usd', 10.00);
    fakeAgentWithUsage(inputTokens: 1_000_000);
    app(AiCaptioning::class)->describe(addImage('documents'));

    $this->artisan('ai-captioning:budget')
        ->expectsOutputToContain('month ('.now()->format('Y-m').')')
        ->expectsOutputToContain('$1.00 (1 calls)')
        ->expectsOutputToContain('$10.00')
        ->expectsOutputToContain('80% ($8.00)')
        ->expectsOutputToContain('Running')
        ->assertSuccessful();

    $this->artisan('ai-captioning:budget', ['--pause' => true, '--reason' => 'Too many logos'])
        ->expectsOutputToContain('AI captioning paused.')
        ->expectsOutputToContain('Too many logos')
        ->assertSuccessful();
    expect(app(AiCaptioning::class)->isPaused())->toBeTrue();

    $this->artisan('ai-captioning:budget', ['--resume' => true])
        ->expectsOutputToContain('AI captioning resumed.')
        ->assertSuccessful();
    expect(app(AiCaptioning::class)->isPaused())->toBeFalse();
});

it('records nothing when the budget is disabled', function (): void {
    config()->set('ai-captioning.budget.enabled', false);
    config()->set('ai-captioning.budget.limit_usd', 0);
    fakeAgentWithUsage(inputTokens: 1000);

    expect(app(AiCaptioning::class)->describe(addImage('documents')))->not->toBeNull()
        ->and(DB::table('ai_captioning_spend')->count())->toBe(0);
});

it('builds a mail notification from the budget events', function (): void {
    Notification::fake();

    Notification::route('mail', 'ops@example.com')
        ->notify(AiCaptioningBudgetNotification::fromEvent(new BudgetExceeded(10.42, 10.0, 'month', '2026-11')));

    Notification::assertSentOnDemand(AiCaptioningBudgetNotification::class, function (AiCaptioningBudgetNotification $notification): bool {
        $mail = $notification->toMail(null);

        return $notification->exceeded
            && str_contains((string) $mail->subject, 'AI captioning paused')
            && str_contains(implode(' ', $mail->introLines), '$10.42 of the $10.00 limit');
    });

    expect(AiCaptioningBudgetNotification::fromEvent(new BudgetWarning(8.0, 10.0, 'month', '2026-11', 0.8))->subject())
        ->toContain('80% of the budget');
});
