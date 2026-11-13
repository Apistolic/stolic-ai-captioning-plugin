<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning;

use Illuminate\Support\Str;
use Laravel\Ai\Files\LocalImage;
use Stolic\AiCaptioning\Agents\ImageDescriberAgent;
use Stolic\AiCaptioning\Contracts\ImageDescriber;
use Stolic\AiCaptioning\Data\ImageDescription;
use Stolic\AiCaptioning\Support\Profile;

/**
 * The default describer: sends the image to a laravel/ai agent built from a profile.
 */
final class LaravelAiImageDescriber implements ImageDescriber
{
    public function describe(string $absolutePath, string $mimeType, array $context, string $profile = 'default'): ImageDescription
    {
        $resolvedProfile = Profile::named($profile);

        $agent = new ImageDescriberAgent(
            instructions: $resolvedProfile->instructionsFor($context),
            fields: $resolvedProfile->schema,
            providerName: $resolvedProfile->provider,
            modelName: $resolvedProfile->model,
            timeoutSeconds: $resolvedProfile->timeout,
        );

        $response = $agent->prompt(
            $resolvedProfile->prompt,
            attachments: [new LocalImage($absolutePath, $mimeType)],
        );

        $structured = method_exists($response, 'toArray') ? (array) $response->toArray() : [];

        return ImageDescription::fromFields(
            profile: $resolvedProfile->name,
            fields: $this->normalise($structured, $resolvedProfile->schema),
            model: $response->meta->model,
            provider: $response->meta->provider,
        );
    }

    /**
     * Keep only the profile's fields and trim strings to their configured maximum length.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    private function normalise(array $structured, array $schema): array
    {
        $fields = [];

        foreach ($schema as $name => $definition) {
            $value = $structured[$name] ?? null;

            if (is_string($value)) {
                $value = trim($value);
                $max = $definition['max'] ?? null;

                if (is_int($max) && $max > 1 && mb_strlen($value) > $max) {
                    $value = rtrim(Str::limit($value, $max - 1, '')).'…';
                }
            }

            $fields[$name] = $value;
        }

        return $fields;
    }
}
