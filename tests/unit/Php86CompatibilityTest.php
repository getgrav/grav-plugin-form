<?php

use Grav\Plugin\Form\Captcha\BasicCaptcha;
use Grav\Plugin\Form\Form;
use Grav\Plugin\FormPlugin;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

/**
 * Regression tests for PHP 8.6 compatibility fixes (while remaining PHP 7.3+ compatible).
 */
class Php86CompatibilityTest extends TestCase
{
    /**
     * Run a callback and fail if any E_DEPRECATED / E_USER_DEPRECATED is triggered.
     *
     * @param callable $callback
     * @return mixed
     */
    private function assertNoDeprecation(callable $callback)
    {
        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$deprecations): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                $deprecations[] = $errstr . ' (' . basename($errfile) . ':' . $errline . ')';
                return true;
            }
            return false;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], array_values(array_unique($deprecations)), 'Unexpected deprecation(s): ' . implode('; ', array_unique($deprecations)));

        return $result;
    }

    /**
     * @param object $object
     * @param string $property
     * @param mixed $value
     * @return void
     */
    private function setProperty($object, string $property, $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue($object, $value);
    }

    /**
     * @param object $object
     * @param string $property
     * @return mixed
     */
    private function getProperty($object, string $property)
    {
        $reflection = new ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->getValue($object);
    }

    public function testNoCaseStatementEndsWithASemicolon(): void
    {
        // `case 'x';` is a compile-time deprecation in PHP 8.6, so check the source rather than run it.
        $root = dirname(__DIR__, 2);
        $paths = [$root . '/form.php'];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/classes', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) === '.php') {
                $paths[] = $file->getPathname();
            }
        }

        $problems = [];
        foreach ($paths as $path) {
            foreach (file($path) as $i => $line) {
                if (preg_match('/^\s*case\s[^:]*;\s*$/', $line)) {
                    $problems[] = substr($path, strlen($root) + 1) . ':' . ($i + 1) . ' ' . trim($line);
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testFormGetErrorsReturnsErrors(): void
    {
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $setErrors = new ReflectionMethod($form, 'setErrors');
        if (PHP_VERSION_ID < 80100) {
            $setErrors->setAccessible(true);
        }
        $setErrors->invoke($form, ['Something failed']);

        self::assertSame(['Something failed'], $form->get('errors'));
    }

    public function testFormIgnoresUniqueIdWithNulByte(): void
    {
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();

        $form->setUniqueId('abc123');
        $form->setUniqueId("abc\0/../../x");
        self::assertSame('abc123', $form->getUniqueId());

        $form->setUniqueId('def456');
        self::assertSame('def456', $form->getUniqueId());
    }

    public function testAddFormWithNullRouteDoesNotUseNullArrayOffset(): void
    {
        $language = new class {
            public function getLanguage()
            {
                return 'en';
            }
        };
        $cache = new class {
            public $saved = [];
            public function fetch($id)
            {
                return false;
            }
            public function save($id, $data)
            {
                $this->saved[$id] = $data;
            }
        };
        $pages = new class {
            public function getPagesCacheId()
            {
                return 'pages';
            }
        };

        $grav = new Container();
        $grav['language'] = $language;
        $grav['cache'] = $cache;
        $grav['pages'] = $pages;

        $plugin = (new ReflectionClass(FormPlugin::class))->newInstanceWithoutConstructor();
        $this->setProperty($plugin, 'grav', $grav);
        $this->setProperty($plugin, 'name', 'form');
        $this->setProperty($plugin, 'config', ['plugins.form' => ['debug' => false]]);

        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $this->setProperty($form, 'name', 'contact');

        $this->assertNoDeprecation(function () use ($plugin, $form): void {
            $plugin->addForm(null, $form);
        });

        $forms = $this->getProperty($plugin, 'forms');
        self::assertSame($form, $forms['en']['']['contact']);
    }

    public function testBasicCaptchaImagesRenderWithoutDeprecation(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            $this->markTestSkipped('GD with FreeType is not available');
        }

        $config = [
            'image' => ['width' => 135, 'height' => 40, 'bg' => '#ffffff'],
            // Sizes that don't divide evenly, so the drawing math produces fractions.
            'chars' => ['length' => 7, 'font' => 'zxx-xed.ttf', 'size' => 24, 'box_width' => 203, 'box_height' => 71, 'bg' => '#ffffff', 'text' => '#000000'],
            'math' => ['min' => 1, 'max' => 12, 'operators' => ['+', '-', '*']],
            'dots' => ['rows' => 3, 'cols' => 5, 'min' => 2, 'max' => 5],
        ];

        foreach (['characters', 'math', 'dotcount', 'position'] as $type) {
            $captcha = (new ReflectionClass(BasicCaptcha::class))->newInstanceWithoutConstructor();
            $this->setProperty($captcha, 'config', ['type' => $type] + $config);
            $this->setProperty($captcha, 'session', new stdClass());

            $image = $this->assertNoDeprecation(function () use ($captcha) {
                $image = null;
                for ($i = 0; $i < 10; $i++) {
                    $image = $captcha->createCaptchaImage($captcha->getCaptchaCode());
                }
                $this->assertNotFalse($image);

                return $image;
            });

            self::assertGreaterThan(0, imagesx($image), $type);
        }
    }
}
