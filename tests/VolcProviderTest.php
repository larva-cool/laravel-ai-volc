<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Larva\Ai\Volc\Gateways\VolcAudioGateway;
use Larva\Ai\Volc\Gateways\VolcImageGateway;

/**
 * Class VolcProviderTest
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcProviderTest extends TestCase
{
    public function test_it_provides_volc_gateways(): void
    {
        $provider = $this->makeProvider();

        $this->assertInstanceOf(VolcAudioGateway::class, $provider->audioGateway());
        $this->assertInstanceOf(VolcImageGateway::class, $provider->imageGateway());
        $this->assertSame($provider->audioGateway(), $provider->audioGateway());
        $this->assertSame($provider->imageGateway(), $provider->imageGateway());
    }

    public function test_default_models_have_fallbacks(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('doubao-seed-tts-2.0', $provider->defaultAudioModel());
        $this->assertSame('doubao-seedream-5-0-pro-260628', $provider->defaultImageModel());
        $this->assertSame('doubao-seedance-2-5-260628', $provider->defaultVideoModel());
    }

    public function test_default_models_can_be_configured(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'audio' => ['default' => 'custom-tts'],
                'image' => ['default' => 'custom-seedream'],
                'video' => ['default' => 'custom-seedance'],
            ],
        ]);

        $this->assertSame('custom-tts', $provider->defaultAudioModel());
        $this->assertSame('custom-seedream', $provider->defaultImageModel());
        $this->assertSame('custom-seedance', $provider->defaultVideoModel());
    }

    public function test_default_image_options_map_aspect_ratios(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame(['size' => '1024x1024'], $provider->defaultImageOptions('1:1'));
        $this->assertSame(['size' => '1024x1536'], $provider->defaultImageOptions('2:3'));
        $this->assertSame(['size' => '1536x1024'], $provider->defaultImageOptions('3:2'));
        $this->assertSame(['size' => '2048x2048'], $provider->defaultImageOptions('2048x2048'));
    }

    public function test_default_image_options_map_quality_tiers(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame(['size' => '1K'], $provider->defaultImageOptions(null, 'low'));
        $this->assertSame(['size' => '4K'], $provider->defaultImageOptions(null, 'high'));
        $this->assertSame([], $provider->defaultImageOptions(null, 'medium'));
        $this->assertSame([], $provider->defaultImageOptions());
    }

    public function test_explicit_size_wins_over_quality(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame(['size' => '1536x1024'], $provider->defaultImageOptions('3:2', 'high'));
    }

    public function test_missing_url_throws_exception(): void
    {
        $provider = $this->makeProvider(['url' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The [volc] provider requires a 'url' to be configured.");

        $provider->createVideoTask('prompt');
    }

    public function test_create_video_task(): void
    {
        Http::fake([
            '*/contents/generations/tasks' => Http::response(['id' => 'cgt-20261023001']),
        ]);

        $provider = $this->makeProvider();

        $result = $provider->createVideoTask('一只猫在草地上奔跑', [
            'images' => [
                ['url' => 'https://example.com/first.png', 'role' => 'first_frame'],
                ['url' => 'https://example.com/ref.png'],
            ],
            'duration' => 5,
            'resolution' => '1080p',
            'ratio' => '16:9',
            'watermark' => false,
        ]);

        $this->assertSame(['id' => 'cgt-20261023001'], $result);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-api-key')
                && $data['model'] === 'doubao-seedance-2-5-260628'
                && $data['content'][0] === ['type' => 'text', 'text' => '一只猫在草地上奔跑']
                && $data['content'][1] === ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/first.png', 'role' => 'first_frame']]
                && $data['content'][2] === ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/ref.png']]
                && $data['duration'] === 5
                && $data['resolution'] === '1080p'
                && $data['ratio'] === '16:9'
                && $data['watermark'] === false;
        });
    }

    public function test_create_video_task_uses_configured_model(): void
    {
        Http::fake([
            '*/contents/generations/tasks' => Http::response(['id' => 'cgt-abc']),
        ]);

        $provider = $this->makeProvider([
            'models' => ['video' => ['default' => 'custom-seedance']],
        ]);

        $provider->createVideoTask('prompt', ['model' => 'explicit-model']);

        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'explicit-model');
    }

    public function test_get_video_result(): void
    {
        Http::fake([
            '*/contents/generations/tasks/cgt-20261023001' => Http::response([
                'id' => 'cgt-20261023001',
                'status' => 'succeeded',
                'content' => ['video_url' => 'https://example.com/video.mp4'],
            ]),
        ]);

        $provider = $this->makeProvider();

        $result = $provider->getVideoResult('cgt-20261023001');

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('https://example.com/video.mp4', $result['content']['video_url']);
    }
}
