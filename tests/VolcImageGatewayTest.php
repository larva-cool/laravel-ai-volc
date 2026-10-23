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
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;

/**
 * Class VolcImageGatewayTest
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcImageGatewayTest extends TestCase
{
    public function test_generate_image(): void
    {
        Http::fake([
            '*/images/generations' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('fake-image-binary')],
                ],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 0],
            ]),
        ]);

        $provider = $this->makeProvider();

        $response = $provider->imageGateway()->generateImage(
            $provider, 'doubao-seedream-5-0-pro-260628', '一只柴犬', size: '1:1', quality: 'high',
        );

        $this->assertCount(1, $response->images);
        $this->assertSame('fake-image-binary', (string) $response->firstImage());
        $this->assertSame('image/png', $response->firstImage()->mime());
        $this->assertSame(12, $response->usage->promptTokens);
        $this->assertSame('volc', $response->meta->provider);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-api-key')
                && $data['model'] === 'doubao-seedream-5-0-pro-260628'
                && $data['prompt'] === '一只柴犬'
                && $data['size'] === '1024x1024'
                && $data['response_format'] === 'b64_json'
                && ! array_key_exists('image', $data)
                && ! array_key_exists('watermark', $data);
        });
    }

    public function test_generate_image_maps_remote_and_base64_attachments(): void
    {
        Http::fake([
            '*/images/generations' => Http::response(['data' => []]),
        ]);

        $provider = $this->makeProvider();

        $provider->imageGateway()->generateImage(
            $provider,
            'doubao-seedream-5-0-pro-260628',
            '将两张图合成一张',
            [
                new RemoteImage('https://example.com/ref.png'),
                new Base64Image(base64_encode('png-binary'), 'image/png'),
            ],
        );

        Http::assertSent(fn (Request $request): bool => $request->data()['image'] === [
            'https://example.com/ref.png',
            'data:image/png;base64,'.base64_encode('png-binary'),
        ]);
    }

    public function test_generate_image_single_attachment_is_string(): void
    {
        Http::fake([
            '*/images/generations' => Http::response(['data' => []]),
        ]);

        $provider = $this->makeProvider();

        $provider->imageGateway()->generateImage(
            $provider,
            'doubao-seedream-5-0-pro-260628',
            '参考图重绘',
            [new RemoteImage('https://example.com/ref.png')],
        );

        Http::assertSent(fn (Request $request): bool => $request->data()['image'] === 'https://example.com/ref.png');
    }

    public function test_generate_image_applies_configured_watermark(): void
    {
        Http::fake([
            '*/images/generations' => Http::response(['data' => []]),
        ]);

        $provider = $this->makeProvider([
            'models' => ['image' => ['watermark' => true]],
        ]);

        $provider->imageGateway()->generateImage($provider, 'doubao-seedream-5-0-pro-260628', '带水印');

        Http::assertSent(fn (Request $request): bool => $request->data()['watermark'] === true);
    }
}
