<?php

use Grav\Plugin\FormPlugin;
use PHPUnit\Framework\TestCase;

/**
 * A FilePond field stores the same value as a file field, so it needs the same field type defaults. Without them
 * the uploaded file list is filtered as text and becomes an empty string.
 */
class FilePondFieldTest extends TestCase
{
    public function testFilePondHasTheFileFieldDefaults(): void
    {
        require_once dirname(__DIR__, 2) . '/form.php';
        $plugin = (new ReflectionClass(FormPlugin::class))->newInstanceWithoutConstructor();
        $types = $plugin->getFormFieldTypes();

        self::assertArrayHasKey('filepond', $types);
        self::assertSame($types['file'], $types['filepond']);
    }
}
