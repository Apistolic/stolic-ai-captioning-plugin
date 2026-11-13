<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Lets a column or action work on a Media row, or on a host row via ->media(fn (Model $record): ?Media => ...).
 */
trait ResolvesMedia
{
    protected string $describedField = 'caption';

    protected ?Closure $mediaResolver = null;

    public function field(string $field): static
    {
        $this->describedField = $field;

        return $this;
    }

    public function getField(): string
    {
        return $this->describedField;
    }

    /**
     * @param  Closure(Model): ?Media  $resolver
     */
    public function media(Closure $resolver): static
    {
        $this->mediaResolver = $resolver;

        return $this;
    }

    public function resolveMedia(mixed $record): ?Media
    {
        if ($this->mediaResolver !== null && $record instanceof Model) {
            return ($this->mediaResolver)($record);
        }

        return $record instanceof Media ? $record : null;
    }

    /**
     * A readable name for a field: 'caption', 'alt text', or the key with spaces.
     */
    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'alt' => 'alt text',
            default => Str::of($field)->replace(['_', '-'], ' ')->lower()->toString(),
        };
    }
}
