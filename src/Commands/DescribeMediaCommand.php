<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Support\Profile;
use Stolic\AiCaptioning\Support\Target;

/**
 * Backfill: finds media of the configured targets and queues describe jobs (dry run unless --apply).
 */
final class DescribeMediaCommand extends Command
{
    protected $signature = 'ai-captioning:describe
        {--model= : Only media of this model (class name or morph alias); defaults to every configured target}
        {--collection=* : Only these collections (defaults to the target\'s collections)}
        {--profile= : Use this profile instead of the target\'s}
        {--only-missing : Only media missing a value for one of the profile\'s fields (e.g. caption or alt)}
        {--limit= : Queue at most this many}
        {--apply : Queue the jobs (without it, this is a dry run)}';

    protected $description = 'Queue AI descriptions (captions, alt text) for existing media';

    public function handle(AiCaptioning $captioning, ApplyDescription $applyDescription): int
    {
        $targets = $this->targets();

        if ($targets === []) {
            $this->components->warn('No matching targets. Configure ai-captioning.targets or pass a configured --model.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $apply = (bool) $this->option('apply');
        $selected = 0;
        $rows = [];

        foreach ($targets as $target) {
            $profile = (string) ($this->option('profile') ?: $target->profile);
            $fields = array_keys(Profile::named($profile)->fields);
            $count = 0;

            foreach ($this->query($target)->lazyById() as $media) {
                if ($limit !== null && $selected >= $limit) {
                    break;
                }

                if ($this->option('only-missing') && ! $this->isMissingAField($applyDescription, $media, $fields)) {
                    continue;
                }

                if ($apply) {
                    $captioning->queue($media, $profile);
                }

                $selected++;
                $count++;
            }

            $rows[] = [$target->model, implode(', ', $this->collectionsFor($target)) ?: '(all)', $profile, $count];
        }

        $this->table(['Model', 'Collections', 'Profile', $apply ? 'Queued' : 'Would queue'], $rows);

        if ($apply) {
            $this->components->info("Queued {$selected} describe job(s).");
        } else {
            $this->components->info("Dry run: {$selected} media item(s) would be queued. Pass --apply to queue them.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<Target>
     */
    private function targets(): array
    {
        $model = $this->option('model');

        if (! is_string($model) || $model === '') {
            return Target::all();
        }

        $class = Relation::getMorphedModel($model) ?? $model;

        return array_values(array_filter(Target::all(), static fn (Target $target): bool => $target->model === $class));
    }

    /**
     * @return list<string>
     */
    private function collectionsFor(Target $target): array
    {
        $requested = array_values(array_filter((array) $this->option('collection')));

        if ($requested === []) {
            return $target->collections;
        }

        return $target->collections === [] ? $requested : array_values(array_intersect($requested, $target->collections));
    }

    /**
     * @return Builder<Media>
     */
    private function query(Target $target): Builder
    {
        /** @var class-string<Media> $mediaClass */
        $mediaClass = config('media-library.media_model', Media::class);
        $collections = $this->collectionsFor($target);

        return $mediaClass::query()
            ->whereIn('model_type', $target->modelTypes())
            ->where('mime_type', 'like', 'image/%')
            ->when($collections !== [], fn (Builder $query) => $query->whereIn('collection_name', $collections))
            ->when($target->collections !== [] && $collections === [], fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /**
     * @param  list<string>  $fields
     */
    private function isMissingAField(ApplyDescription $applyDescription, Media $media, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($applyDescription->value($media, $field) === null) {
                return true;
            }
        }

        return false;
    }
}
