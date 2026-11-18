<?php

use Grav\Plugin\Form\TwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FormAttributesTest extends TestCase
{
    public function testKeyValueAttributesKeepPlainNames(): void
    {
        $list = (new TwigExtension())->formAttributes(['placeholder' => 'Name', 'data-ok' => '2', 'aria-label' => 'x', 'style' => 'width:50%']);

        self::assertSame(['placeholder', 'data-ok', 'aria-label', 'style'], array_column($list, 'name'));
        self::assertSame('Name', $list[0]['value']);
    }

    public function testNameValueListStyleIsSupported(): void
    {
        $list = (new TwigExtension())->formAttributes([['name' => 'rows', 'value' => 4], ['name' => 'onclick', 'value' => 'x()']]);

        self::assertSame([['name' => 'rows', 'value' => 4]], $list);
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafeNamesAreDropped(string $name): void
    {
        self::assertSame([], (new TwigExtension())->formAttributes([$name => '1']));
        self::assertSame([], (new TwigExtension())->formAttributes([['name' => $name, 'value' => '1']]));
    }

    public static function unsafeNames(): array
    {
        return [
            ['onclick'],
            ['ONFOCUS'],
            ['data-a autofocus onfocus=alert(1)//'],
            ['x"y'],
            ['a>b'],
            ['style' . "\n"],
            ['formaction'],
            ['srcdoc'],
            ['xmlns'],
            ['1abc'],
            [''],
        ];
    }

    public function testDatasetPrefixIsChecked(): void
    {
        $list = (new TwigExtension())->formAttributes(['keep' => 'yes', 'x onpointerdown=alert(1) y' => '1'], 'data-');

        self::assertSame([['name' => 'data-keep', 'value' => 'yes']], $list);
    }

    public function testNonIterableGivesEmptyList(): void
    {
        self::assertSame([], (new TwigExtension())->formAttributes(null));
        self::assertSame([], (new TwigExtension())->formAttributes('onclick'));
    }

    public function testFilterRendersInTwig(): void
    {
        $twig = new Environment(new ArrayLoader([
            't' => '<input{% for a in attrs|form_attributes %} {{ a.name }}="{{ a.value|e(\'html_attr\') }}"{% endfor %}>',
        ]));
        $twig->addExtension(new TwigExtension());

        $html = $twig->render('t', ['attrs' => ['data-a autofocus onfocus=alert(1)//' => '1', 'onclick' => 'x()', 'placeholder' => 'a"b']]);

        self::assertSame('<input placeholder="a&quot;b">', $html);
    }

    /**
     * Every template loop over a form definition's `attributes` or `datasets`
     * goes through `form_attributes`, and no template prints a map key as an
     * attribute name directly.
     */
    public function testTemplatesPrintAttributeNamesOnlyThroughTheFilter(): void
    {
        $dir = dirname(__DIR__, 2) . '/templates';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        $problems = [];
        foreach ($files as $file) {
            if (substr($file->getFilename(), -5) !== '.twig') {
                continue;
            }
            foreach (file($file->getPathname()) as $i => $line) {
                $where = substr($file->getPathname(), strlen($dir) + 1) . ':' . ($i + 1);
                if (preg_match('/\{%-?\s*for\s[^%]*\.(attributes|datasets)\b[^%]*%\}/', $line) && strpos($line, 'form_attributes') === false) {
                    $problems[] = $where . ' loop without form_attributes';
                }
                if (preg_match_all('/\{\{-?\s*([\w.]+)\s*-?\}\}="/', $line, $m)) {
                    foreach ($m[1] as $var) {
                        if ($var !== 'attribute.name') {
                            $problems[] = $where . ' prints {{ ' . $var . ' }} as an attribute name';
                        }
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }
}
