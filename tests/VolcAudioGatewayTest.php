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

/**
 * Class VolcAudioGatewayTest
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcAudioGatewayTest extends TestCase
{
    public function test_generate_audio_maps_placeholder_voices(): void
    {
        Http::fake([
            '*/audio/speech' => Http::response('binary-audio-content'),
        ]);

        $provider = $this->makeProvider([
            'models' => [
                'audio' => [
                    'voice' => 'zh_female_custom',
                    'male_voice' => 'zh_male_custom',
                ],
            ],
        ]);

        $response = $provider->audioGateway()->generateAudio(
            $provider, 'doubao-seed-tts-2.0', '你好，世界', 'default-female',
        );

        $this->assertSame('binary-audio-content', $response->content());
        $this->assertSame('audio/mpeg', $response->mimeType());
        $this->assertSame('volc', $response->meta->provider);
        $this->assertSame('doubao-seed-tts-2.0', $response->meta->model);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-api-key')
                && $data['model'] === 'doubao-seed-tts-2.0'
                && $data['input'] === '你好，世界'
                && $data['voice'] === 'zh_female_custom'
                && $data['response_format'] === 'mp3'
                && $data['speed'] === 1.0;
        });
    }

    public function test_generate_audio_uses_builtin_default_voices(): void
    {
        Http::fake([
            '*/audio/speech' => Http::response('audio'),
        ]);

        $provider = $this->makeProvider();

        $provider->audioGateway()->generateAudio($provider, 'doubao-seed-tts-2.0', '你好', 'default-female');
        Http::assertSent(fn (Request $request): bool => $request->data()['voice'] === 'zh_female_gaolengyujie_uranus_bigtts');

        $provider->audioGateway()->generateAudio($provider, 'doubao-seed-tts-2.0', '你好', 'default-male');
        Http::assertSent(fn (Request $request): bool => $request->data()['voice'] === 'zh_male_qingshuangnanda_mars_bigtts');
    }

    public function test_generate_audio_passes_through_explicit_voice(): void
    {
        Http::fake([
            '*/audio/speech' => Http::response('audio'),
        ]);

        $provider = $this->makeProvider();

        $provider->audioGateway()->generateAudio($provider, 'doubao-seed-tts-2.0', '你好', 'zh_female_wanwanxiaohe_moon_bigtts');

        Http::assertSent(fn (Request $request): bool => $request->data()['voice'] === 'zh_female_wanwanxiaohe_moon_bigtts');
    }

    public function test_generate_audio_supports_configured_format_and_speed(): void
    {
        Http::fake([
            '*/audio/speech' => Http::response('audio'),
        ]);

        $provider = $this->makeProvider([
            'models' => [
                'audio' => [
                    'response_format' => 'wav',
                    'speed' => 1.5,
                ],
            ],
        ]);

        $response = $provider->audioGateway()->generateAudio($provider, 'doubao-seed-tts-2.0', '你好', 'default-female');

        $this->assertSame('audio/wav', $response->mimeType());

        Http::assertSent(fn (Request $request): bool => $request->data()['response_format'] === 'wav'
            && $request->data()['speed'] === 1.5);
    }
}
