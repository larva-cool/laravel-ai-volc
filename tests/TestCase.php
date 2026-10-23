<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Larva\Ai\Volc\Providers\VolcProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Class TestCase
 *
 * 提供最小化的容器与 Http 门面环境，使测试无需完整的 Laravel 应用即可运行。
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class TestCase extends BaseTestCase
{
    /**
     * 测试容器
     */
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        $this->app->instance(HttpFactory::class, new HttpFactory());

        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * 构造一个测试用 VolcProvider 实例
     */
    protected function makeProvider(array $config = []): VolcProvider
    {
        return new VolcProvider(
            array_merge([
                'driver' => 'volc',
                'name' => 'volc',
                'key' => 'test-api-key',
                'url' => 'https://ark.cn-beijing.volces.com/api/v3',
            ], $config),
            new Dispatcher($this->app),
        );
    }
}
