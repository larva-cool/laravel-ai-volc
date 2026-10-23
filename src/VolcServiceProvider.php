<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Larva\Ai\Volc;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Larva\Ai\Volc\Providers\VolcProvider;

/**
 * 火山方舟 Laravel AI 驱动注册
 *
 * 通过包自动发现（Package Discovery）将 volc 驱动注册到 Laravel AI，
 * 之后即可在 config/ai.php 中以 'driver' => 'volc' 配置火山方舟接入点。
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcServiceProvider extends ServiceProvider
{
    /**
     * Register the Volc driver with the AI manager.
     */
    public function boot(): void
    {
        $this->app->afterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('volc', fn ($app, array $config): VolcProvider => new VolcProvider(
                $config,
                $app->make(Dispatcher::class),
            ));
        });
    }
}
