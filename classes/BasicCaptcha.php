<?php

namespace Grav\Plugin\Form;

use GdImage;
use Grav\Common\Grav;

class BasicCaptcha
{
    protected $session = null;
    protected $key = 'basic_captcha_code';

    public function __construct()
    {
        $this->session = Grav::instance()['session'];
    }

    public function getCaptchaCode($length = null): string
    {
        $config = Grav::instance()['config']->get('plugins.form.basic_captcha');
        $type = $config['type'] ?? 'characters';

        if ($type == 'math') {
            $min = $config['math']['min'] ?? 1;
            $max = $config['math']['max'] ?? 12;
            $operators = $config['math']['operators'] ?? ['+','-','*'];

            $first_num = random_int($min, $max);
            $second_num = random_int($min, $max);
            $operator = $operators[array_rand($operators)];

            // calculator
            if ($operator === '-') {
                if ($first_num < $second_num) {
                    $result = "$second_num-$first_num";
                    $captcha_code = $second_num-$first_num;
                } else {
                    $result = "$first_num-$second_num";
                    $captcha_code = $first_num - $second_num;
                }
            } elseif ($operator === '*') {
                $result = "{$first_num}x{$second_num}";
                $captcha_code = $first_num - $second_num;
            } elseif ($operator === '/') {
                $result = "$first_num/ second_num";
                $captcha_code = $first_num / $second_num;
            } elseif ($operator === '+') {
                $result = "$first_num+$second_num";
                $captcha_code = $first_num + $second_num;
            }
        } else {
            if ($length === null) {
                $length = $config['chars']['length'] ?? 6;
            }
            $random_alpha = md5(random_bytes(64));
            $captcha_code = substr($random_alpha, 0, $length);
            $result = $captcha_code;
        }


        $this->setSession($this->key, $captcha_code);
        return $result;
    }

    public function setSession($key, $value): void
    {
        $this->session->$key = $value;
    }

    public function getSession($key = null): ?string
    {
        if ($key === null) {
            $key = $this->key;
        }
        return $this->session->$key ?? null;
    }

    public function createCaptchaImage($captcha_code)
    {
        $config = Grav::instance()['config']->get('plugins.form.basic_captcha');
        $font = $config['chars']['font'] ?? 'zxx-xed.ttf';

        $target_layer = imagecreatetruecolor($config['chars']['box_width'], $config['chars']['box_height']);

        $bg = $this->hexToRgb($config['chars']['bg'] ?? '#ffffff');
        $text = $this->hexToRgb($config['chars']['text'] ?? '#000000');

        $captcha_background = imagecolorallocate($target_layer, $bg[0], $bg[1], $bg[2]);
        $captcha_text_color = imagecolorallocate($target_layer, $text[0], $text[1], $text[2]);

        $font_path = __DIR__ . '/../fonts/' . $font;

        imagefill($target_layer, 0, 0, $captcha_background);

        imagefttext($target_layer, $config['chars']['size'], 0, $config['chars']['start_x'], $config['chars']['start_y'], $captcha_text_color, $font_path, $captcha_code);
        return $target_layer;
    }

    public function renderCaptchaImage($imageData): void
    {
        header("Content-type: image/jpeg");
        imagejpeg($imageData);
    }

    public function validateCaptcha($formData): bool
    {
        $isValid = false;
        $capchaSessionData = $this->getSession();

        if ($capchaSessionData == $formData) {
            $isValid = true;
        }
        return $isValid;
    }

    private function hexToRgb($hex): array
    {
        return sscanf($hex, "#%02x%02x%02x");
    }

}

