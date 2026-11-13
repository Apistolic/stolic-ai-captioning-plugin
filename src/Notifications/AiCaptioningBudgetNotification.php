<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Stolic\AiCaptioning\Data\BudgetStatus;
use Stolic\AiCaptioning\Events\BudgetExceeded;
use Stolic\AiCaptioning\Events\BudgetWarning;

/**
 * An optional, ready-made mail notification for BudgetWarning / BudgetExceeded. The host routes it:
 *
 *   Event::listen(BudgetExceeded::class, fn (BudgetExceeded $event) => Notification::route('mail', ['ops@example.com'])
 *       ->notify(AiCaptioningBudgetNotification::fromEvent($event)));
 */
class AiCaptioningBudgetNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly bool $exceeded,
        public readonly float $spendUsd,
        public readonly float $limitUsd,
        public readonly string $periodKey,
        public readonly ?string $appName = null,
    ) {}

    public static function fromEvent(BudgetWarning|BudgetExceeded $event): static
    {
        return new static(
            exceeded: $event instanceof BudgetExceeded,
            spendUsd: $event->spendUsd,
            limitUsd: $event->limitUsd,
            periodKey: $event->periodKey,
        );
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function subject(): string
    {
        $app = $this->appName ?? (string) config('app.name');

        return $this->exceeded
            ? "{$app}: AI captioning paused, budget reached"
            : "{$app}: AI captioning spend at ".round($this->usedPercent()).'% of the budget';
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $period = $this->periodKey === 'total' ? 'in total' : "in {$this->periodKey}";
        $spend = BudgetStatus::money($this->spendUsd);
        $limit = BudgetStatus::money($this->limitUsd);

        $message = (new MailMessage)
            ->subject($this->subject())
            ->line("The estimated AI captioning spend {$period} is {$spend} of the {$limit} limit.");

        return $this->exceeded
            ? $message
                ->line('AI captioning is paused: new images are not described until the next period, a higher limit, or a manual resume.')
                ->line('Resume with `php artisan ai-captioning:budget --resume` after raising the limit.')
            : $message->line('AI captioning pauses automatically when the limit is reached.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'exceeded' => $this->exceeded,
            'spend_usd' => $this->spendUsd,
            'limit_usd' => $this->limitUsd,
            'period' => $this->periodKey,
        ];
    }

    private function usedPercent(): float
    {
        return $this->limitUsd > 0 ? $this->spendUsd / $this->limitUsd * 100 : 100.0;
    }
}
