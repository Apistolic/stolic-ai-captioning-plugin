<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Stolic\AiCaptioning\AiCaptioningServiceProvider;
use Stolic\AiCaptioning\Tests\Fixtures\Models\Post;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $migration = include __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
        $migration->up();

        (include __DIR__.'/../database/migrations/create_ai_captioning_budget_tables.php')->up();
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.public.root');

        if (is_string($root) && is_dir($root)) {
            app('files')->deleteDirectory($root);
        }

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            AiServiceProvider::class,
            AiCaptioningServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/ai-captioning-tests-'.getmypid(),
            'url' => '/storage',
        ]);
        $app['config']->set('media-library.disk_name', 'public');
        $app['config']->set('media-library.queue_conversions_by_default', false);
        $app['config']->set('ai-captioning.targets', [
            [
                'model' => Post::class,
                'collections' => ['gallery', 'featured'],
                'conversion' => 'web',
                'profile' => 'default',
            ],
        ]);
    }
}
