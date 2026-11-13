<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Actions;

use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Data\ImageDescription;
use Stolic\AiCaptioning\Support\Profile;

/**
 * The one place that writes AI descriptions onto media custom properties.
 *
 * The overwrite rule for each applied field (caption, alt, ...):
 *  - an empty value is filled with the suggestion, origin 'ai';
 *  - a value whose origin is 'ai' is refreshed;
 *  - any other value (a human origin such as 'school' or 'staff', or a non-empty value without an origin)
 *    is never replaced: only the suggestion key is updated.
 * Replacing a human value takes an explicit useSuggestion() call (the approval action).
 */
final class ApplyDescription
{
    public const string ORIGIN_AI = 'ai';

    public const string FILLED = 'filled';

    public const string REFRESHED = 'refreshed';

    public const string KEPT = 'kept';

    /**
     * Apply a description and save the media item.
     *
     * @return array<string, string> Field => outcome (filled, refreshed or kept).
     */
    public function apply(Media $media, ImageDescription $description): array
    {
        $profile = Profile::named($description->profile);
        $outcomes = [];

        foreach ($profile->fields as $field => $keys) {
            if (! array_key_exists($field, $description->fields)) {
                continue;
            }

            $suggestion = $this->normaliseValue($description->fields[$field]);
            $media->setCustomProperty($keys['suggestion'], $suggestion);

            if ($suggestion === null) {
                $outcomes[$field] = self::KEPT;

                continue;
            }

            $current = $this->normaliseValue($media->getCustomProperty($keys['value']));
            $origin = $media->getCustomProperty($keys['origin']);

            if ($current === null) {
                $media->setCustomProperty($keys['value'], $suggestion);
                $media->setCustomProperty($keys['origin'], self::ORIGIN_AI);
                $outcomes[$field] = self::FILLED;
            } elseif ($origin === self::ORIGIN_AI) {
                $media->setCustomProperty($keys['value'], $suggestion);
                $outcomes[$field] = self::REFRESHED;
            } else {
                $outcomes[$field] = self::KEPT;
            }
        }

        foreach ($profile->metadata as $source => $propertyKey) {
            $value = match ($source) {
                'described_at' => $description->describedAt->toIso8601String(),
                'model' => $description->model,
                'profile' => $description->profile,
                'provider' => $description->provider,
                default => array_key_exists($source, $description->fields) ? $description->fields[$source] : null,
            };

            if ($value !== null || array_key_exists($source, $description->fields)) {
                $media->setCustomProperty($propertyKey, $value);
            }
        }

        $media->save();

        return $outcomes;
    }

    /**
     * Overwrite the field's value with the stored AI suggestion (after a person approved it) and set its origin to 'ai'.
     *
     * @return bool False when there is no suggestion to use.
     */
    public function useSuggestion(Media $media, string $field): bool
    {
        $keys = $this->keysFor($field);
        $suggestion = $this->suggestion($media, $field);

        if ($suggestion === null) {
            return false;
        }

        $media->setCustomProperty($keys['value'], $suggestion);
        $media->setCustomProperty($keys['origin'], self::ORIGIN_AI);
        $media->save();

        return true;
    }

    /**
     * Record that a person wrote the field's current value (e.g. origin 'staff' or 'school'), so later AI runs keep it.
     */
    public function markEditedBy(Media $media, string $field, string $origin): void
    {
        $origin = trim($origin);

        if ($origin === '') {
            throw new InvalidArgumentException('An origin is required to mark a human edit.');
        }

        $media->setCustomProperty($this->keysFor($field)['origin'], $origin);
        $media->save();
    }

    public function value(Media $media, string $field): ?string
    {
        return $this->normaliseValue($media->getCustomProperty($this->keysFor($field)['value']));
    }

    public function suggestion(Media $media, string $field): ?string
    {
        return $this->normaliseValue($media->getCustomProperty($this->keysFor($field)['suggestion']));
    }

    public function origin(Media $media, string $field): ?string
    {
        $origin = $media->getCustomProperty($this->keysFor($field)['origin']);

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * True when the field has a value that a person wrote (any origin other than 'ai', or none recorded).
     */
    public function hasHumanValue(Media $media, string $field): bool
    {
        return $this->value($media, $field) !== null && $this->origin($media, $field) !== self::ORIGIN_AI;
    }

    /**
     * True when a suggestion exists and differs from the current value.
     */
    public function suggestionDiffers(Media $media, string $field): bool
    {
        $suggestion = $this->suggestion($media, $field);

        return $suggestion !== null && $suggestion !== $this->value($media, $field);
    }

    /**
     * @return array{value: string, origin: string, suggestion: string}
     */
    public function keysFor(string $field): array
    {
        $keys = config("ai-captioning.properties.fields.{$field}");

        if (! is_array($keys) || ! isset($keys['value'], $keys['origin'], $keys['suggestion'])) {
            throw new InvalidArgumentException("The field [{$field}] has no value, origin and suggestion keys in ai-captioning.properties.fields.");
        }

        return $keys;
    }

    private function normaliseValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
