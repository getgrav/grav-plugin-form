<?php
// Test script for basic captcha image generation

// Setup autoloading
require_once __DIR__ . '/vendor/autoload.php';

// Include the BasicCaptcha class
require_once __DIR__ . '/classes/Captcha/BasicCaptcha.php';

use Grav\Plugin\Form\Captcha\BasicCaptcha;

// Mock Grav instance for testing
class MockGrav {
    public $config;
    public $session;
    
    public function __construct() {
        $this->config = new MockConfig();
        $this->session = new MockSession();
    }
    
    public function offsetGet($offset) {
        return $this->$offset;
    }
}

class MockConfig {
    private $data = [
        'plugins.form.basic_captcha' => [
            'type' => 'math',
            'image' => [
                'width' => 135,
                'height' => 40,
                'bg' => '#ffffff'
            ],
            'chars' => [
                'font' => 'zxx-xed.ttf',
                'size' => 16
            ],
            'math' => [
                'min' => 1,
                'max' => 12,
                'operators' => ['+', '-', '*']
            ]
        ]
    ];
    
    public function get($key) {
        return $this->data[$key] ?? null;
    }
}

class MockSession {
    private $data = [];
    
    public function __set($key, $value) {
        $this->data[$key] = $value;
    }
    
    public function __get($key) {
        return $this->data[$key] ?? null;
    }
}

// Override Grav instance
namespace Grav\Common;
class Grav {
    private static $instance;
    
    public static function instance() {
        if (!self::$instance) {
            self::$instance = new \MockGrav();
        }
        return self::$instance;
    }
}

// Test the captcha
$captcha = new BasicCaptcha();

// Test different types
$types = ['math', 'characters'];

foreach ($types as $type) {
    echo "Testing $type captcha...\n";
    
    // Update config for type
    Grav::instance()->config = new MockConfig();
    $configData = [
        'plugins.form.basic_captcha' => [
            'type' => $type,
            'image' => [
                'width' => 135,
                'height' => 40,
                'bg' => '#ffffff'
            ],
            'chars' => [
                'font' => 'zxx-xed.ttf',
                'size' => 16,
                'length' => 6
            ],
            'math' => [
                'min' => 1,
                'max' => 12,
                'operators' => ['+', '-', '*']
            ]
        ]
    ];
    
    // Generate captcha code
    $code = $captcha->getCaptchaCode();
    echo "  Code: $code\n";
    
    // Create image
    $image = $captcha->createCaptchaImage($code);
    
    // Check image dimensions
    $width = imagesx($image);
    $height = imagesy($image);
    echo "  Image dimensions: {$width}x{$height}\n";
    
    // Save test image
    $filename = "test-captcha-{$type}.jpg";
    imagejpeg($image, $filename);
    echo "  Saved to: $filename\n";
    
    // Clean up
    imagedestroy($image);
    
    echo "\n";
}

echo "Test complete! Check the generated test-captcha-*.jpg files.\n";