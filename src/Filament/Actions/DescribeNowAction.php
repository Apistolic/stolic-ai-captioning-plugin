<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Actions;

use Filament\Actions\Action;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Filament\Concerns\ResolvesMedia;

/**
 * "Describe now": queues a describe job for the row's media (the overwrite rule still applies when it runs).
 */
class DescribeNowAction extends Action
{
    use ResolvesMedia;

    protected ?string $profile = null;

    public static function getDefaultName(): ?string
    {
        return 'describeNow';
    }

    public function profile(?string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Describe now');

        $this->icon('heroicon-o-sparkles');

        $this->visible(fn (mixed $record): bool => $this->resolveMedia($record) !== null);

        $this->successNotificationTitle('AI description queued');

        $this->action(function (mixed $record, AiCaptioning $captioning): void {
            $media = $this->resolveMedia($record);

            if ($media === null) {
                $this->failure();

                return;
            }

            $captioning->queue($media, $this->profile);
            $this->success();
        });
    }
}
