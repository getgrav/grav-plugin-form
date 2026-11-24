<?php

use Grav\Plugin\Form\Form;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UploadDestinationTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/form-dest-' . bin2hex(random_bytes(4));
        foreach (['user/themes/quark/templates/partials', 'user/themes/quark/images', 'user/plugins/blog/templates',
                     'user/plugins/blog/admin/templates', 'user/data', 'user/pages/01.home', 'system/templates', 'outside'] as $dir) {
            mkdir(self::$root . '/' . $dir, 0777, true);
        }
        symlink(self::$root . '/user/themes/quark/templates', self::$root . '/user/data/linked');
    }

    #[DataProvider('allowed')]
    public function testAllowedFolders(string $folder): void
    {
        self::assertTrue($this->check($folder));
    }

    public static function allowed(): array
    {
        return [
            'page folder' => ['user/pages/01.home'],
            'data folder' => ['user/data/uploads'],
            'folder not created yet' => ['user/data/not/yet/created'],
            'self@ style page subfolder' => ['user/pages/01.home/uploads'],
            'data folder with a config-like name' => ['user/data/config'],
            'page folder named like an account' => ['user/pages/accounts'],
            'folder starting like a refused one' => ['user/configuration'],
            'images folder' => ['user/images/uploads'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusedFolders(string $folder): void
    {
        self::assertFalse($this->check($folder));
    }

    public static function refused(): array
    {
        return [
            'theme templates' => ['user/themes/quark/templates'],
            'theme templates subfolder' => ['user/themes/quark/templates/partials'],
            'templates of a theme not active' => ['user/themes/other/templates/new'],
            'plugin templates' => ['user/plugins/blog/templates'],
            'nested plugin templates' => ['user/plugins/blog/admin/templates'],
            'registered Twig namespace' => ['user/themes/quark/images'],
            'core templates' => ['system/templates'],
            'outside user' => ['outside'],
            'user root' => ['user'],
            'dot-dot out of user' => ['user/data/../../outside'],
            'dot-dot into templates' => ['user/data/../themes/quark/templates'],
            'symlink into templates' => ['user/data/linked'],
            'absolute path elsewhere' => ['/etc'],
            'config folder' => ['user/config'],
            'config subfolder' => ['user/config/system'],
            'config folder named like a file' => ['user/config/system/debugger/enabled/x.yaml'],
            'environment folder' => ['user/env'],
            'environment subfolder' => ['user/env/example.com/config'],
            'accounts folder' => ['user/accounts'],
            'accounts subfolder' => ['user/accounts/avatars'],
            'config folder in capitals' => ['user/CONFIG/system'],
            'dot-dot into config' => ['user/data/../config/system'],
            'themes folder' => ['user/themes'],
            'theme folder' => ['user/themes/quark'],
            'theme subfolder' => ['user/themes/quark/uploads'],
            'plugins folder' => ['user/plugins'],
            'plugin folder' => ['user/plugins/blog/files'],
            'blueprints folder' => ['user/blueprints'],
            'blueprints subfolder' => ['user/blueprints/pages'],
        ];
    }

    private function check(string $folder): bool
    {
        $path = $folder[0] === '/' ? $folder : self::$root . '/' . $folder;
        $templates = [self::$root . '/user/themes/quark/templates', self::$root . '/user/themes/quark/images', self::$root . '/system/templates'];

        return Form::uploadFolderAllowed($path, self::$root . '/user', $templates);
    }
}
