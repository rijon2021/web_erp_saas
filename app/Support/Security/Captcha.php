<?php

namespace App\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Login CAPTCHA.
 *
 *  - builtin:   self-hosted image challenge (GD). The answer lives in the
 *               session, hashed, single use, 5 minutes. Hosts without GD get a
 *               short sum question instead of an image.
 *  - recaptcha: Google reCAPTCHA v2 ("I'm not a robot" checkbox)
 *  - turnstile: Cloudflare Turnstile
 *  - hcaptcha:  hCaptcha
 *
 * External providers are verified server-side against their siteverify
 * endpoint; a provider outage fails closed (the attempt is rejected).
 */
final class Captcha
{
    public const FIELD = 'captcha';

    public const EXTERNAL = [
        'recaptcha' => [
            'label' => 'Google reCAPTCHA v2',
            'script' => 'https://www.google.com/recaptcha/api.js',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
            'field' => 'g-recaptcha-response',
            'class' => 'g-recaptcha',
        ],
        'turnstile' => [
            'label' => 'Cloudflare Turnstile',
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'field' => 'cf-turnstile-response',
            'class' => 'cf-turnstile',
        ],
        'hcaptcha' => [
            'label' => 'hCaptcha',
            'script' => 'https://js.hcaptcha.com/1/api.js',
            'verify' => 'https://api.hcaptcha.com/siteverify',
            'field' => 'h-captcha-response',
            'class' => 'h-captcha',
        ],
    ];

    private const TTL_SECONDS = 300;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private array $options,
        private string $sessionKey,
        private string $imageUrl,
    ) {
    }

    public function provider(): string
    {
        $provider = $this->options['captcha_provider'];

        // An external provider without keys can't render: fall back to the
        // built-in challenge rather than leaving the form unprotected.
        if ($provider !== 'builtin' && ($this->options['captcha_site_key'] === '' || SecurityOptions::secret($this->options) === '')) {
            return 'builtin';
        }

        return $provider;
    }

    /** View data for resources/views/partials/login-captcha.blade.php. */
    public function widget(): array
    {
        $provider = $this->provider();

        if ($provider !== 'builtin') {
            $p = self::EXTERNAL[$provider];

            return [
                'provider' => $provider,
                'script' => $p['script'],
                'class' => $p['class'],
                'site_key' => $this->options['captcha_site_key'],
            ];
        }

        if (! self::hasGd()) {
            return ['provider' => 'builtin', 'mode' => 'question', 'question' => $this->issueQuestion()];
        }

        return ['provider' => 'builtin', 'mode' => 'image', 'image_url' => $this->imageUrl.'?t='.bin2hex(random_bytes(4))];
    }

    public function verify(Request $request): bool
    {
        $provider = $this->provider();

        if ($provider === 'builtin') {
            return $this->verifyBuiltin($request);
        }

        $token = (string) $request->input(self::EXTERNAL[$provider]['field'], '');

        return self::verifyToken($provider, SecurityOptions::secret($this->options), $token, $request->ip());
    }

    /** Also used by the settings pages to prove new keys work before saving. */
    public static function verifyToken(string $provider, string $secret, string $token, ?string $ip): bool
    {
        if (! isset(self::EXTERNAL[$provider]) || $secret === '' || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post(self::EXTERNAL[$provider]['verify'], array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $response->ok() && $response->json('success') === true;
        } catch (\Throwable $e) {
            Log::warning('Login CAPTCHA verification unreachable', ['provider' => $provider, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** PNG body for the built-in image challenge; stores the new answer. */
    public function image(Request $request): string
    {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $this->remember($request, $code);

        return self::render($code);
    }

    private function issueQuestion(): string
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $this->remember(request(), (string) ($a + $b));

        return "{$a} + {$b} = ?";
    }

    private function remember(Request $request, string $answer): void
    {
        $request->session()->put($this->sessionKey, [
            'hash' => hash('sha256', strtoupper($answer)),
            'expires' => time() + self::TTL_SECONDS,
        ]);
    }

    private function verifyBuiltin(Request $request): bool
    {
        $stored = $request->session()->pull($this->sessionKey);
        $answer = strtoupper(preg_replace('/\s+/', '', (string) $request->input(self::FIELD, '')));

        if (! is_array($stored) || $answer === '' || ($stored['expires'] ?? 0) < time()) {
            return false;
        }

        return hash_equals((string) ($stored['hash'] ?? ''), hash('sha256', $answer));
    }

    public static function hasGd(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    private static function render(string $code): string
    {
        $w = 170;
        $h = 56;
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, random_int(236, 250), random_int(236, 250), random_int(236, 250)));

        for ($i = 0; $i < 7; $i++) {
            $c = imagecolorallocate($img, random_int(150, 210), random_int(150, 210), random_int(150, 210));
            imagesetthickness($img, random_int(1, 2));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        for ($i = 0; $i < 400; $i++) {
            $c = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        $font = self::font();
        $x = 14;
        foreach (str_split($code) as $char) {
            $c = imagecolorallocate($img, random_int(20, 90), random_int(20, 90), random_int(60, 130));
            if ($font && function_exists('imagettftext')) {
                imagettftext($img, random_int(22, 27), random_int(-22, 22), $x, random_int(36, 44), $c, $font, $char);
                $x += random_int(28, 31);
            } else {
                imagestring($img, 5, $x, random_int(14, 26), $char, $c);
                $x += 30;
            }
        }

        // One line through the text makes naive OCR segmentation harder.
        imagesetthickness($img, 2);
        imageline($img, 6, random_int(20, 36), $w - 6, random_int(20, 36), imagecolorallocate($img, 70, 80, 120));

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private static function font(): ?string
    {
        foreach ([
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'),
            public_path('fonts/Vazirmatn-Bold.ttf'),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
