<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Budget\SpendLedger;
use Stolic\AiCaptioning\Data\CostEstimate;
use Stolic\AiCaptioning\Filament\Widgets\AiCaptionSpendWidget;
use Stolic\AiCaptioning\Tests\Fixtures\Livewire\BudgetSettings;

use function Pest\Livewire\livewire;

function sectionAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('aiCaptionSpend', 'content');
}

beforeEach(function (): void {
    config()->set('ai-captioning.budget.limit_usd', 10.00);
    app(SpendLedger::class)->add(new CostEstimate(3.21, 'gemini', 'test-model'));
});

it('shows the spend, the limit and the running state', function (): void {
    livewire(BudgetSettings::class)
        ->assertSee('AI caption spend')
        ->assertSee('$3.21 of $10.00 ('.now()->format('Y-m').')')
        ->assertSee('Running')
        ->assertActionVisible(sectionAction('pauseAiCaptioning'))
        ->assertDontSee('Resume AI captions');
});

it('pauses with a reason and resumes from the settings section', function (): void {
    livewire(BudgetSettings::class)
        ->callAction(sectionAction('pauseAiCaptioning'), ['reason' => 'Reviewing captions'])
        ->assertHasNoActionErrors();

    expect(app(AiCaptioning::class)->isPaused())->toBeTrue()
        ->and(app(AiCaptioning::class)->budgetStatus()->pauseReason)->toBe('Reviewing captions');

    livewire(BudgetSettings::class)
        ->assertSee('Paused')
        ->callAction(sectionAction('resumeAiCaptioning'))
        ->assertHasNoActionErrors();

    expect(app(AiCaptioning::class)->isPaused())->toBeFalse();
});

it('renders the dashboard widget with the paused budget state', function (): void {
    app(SpendLedger::class)->add(new CostEstimate(7.00, 'gemini', 'test-model'));
    app(AiCaptioning::class)->budget()->check();

    livewire(AiCaptionSpendWidget::class)
        ->assertSee('$10.21 of $10.00')
        ->assertSee('102% of the budget')
        ->assertSee('Paused (budget reached)');
});
