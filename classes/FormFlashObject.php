<?php
namespace Grav\Plugin\Form;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Session;
use Grav\Common\User\User;

class FormFlashObject implements \JsonSerializable
{
    /** @var string */
    protected $form;
    /** @var string */
    protected $uniqueId;
    /** @var string */
    protected $url;
    /** @var array */
    protected $user;
    /** @var array */
    protected $uploads;
    /** @var bool */
    protected $exists;

    /**
     * FormFlashObject constructor.
     * @param string $name
     * @param string $uniqueId
     */
    public function __construct($form, $uniqueId = null)
    {
        $this->form = $form;
        $this->uniqueId = $uniqueId;

        $file = $this->getTmpIndex();
        $this->exists = $file->exists();

        $data = $this->exists ? (array)$file->content() : [];
        $this->url = $data['url'] ?? null;
        $this->user = $data['user'] ?? null;
        $this->uploads = $data['uploads'] ?? [];
    }

    /**
     * @return string
     */
    public function getFormName()
    {
        return $this->form;
    }

    /**
     * @return string
     */
    public function getUniqieId()
    {
        return $this->uniqueId ?? $this->getFormName();
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->exists;
    }

    /**
     * @return $this
     */
    public function save()
    {
        $file = $this->getTmpIndex();
        $file->save($this->jsonSerialize());
        $this->exists = true;

        return $this;
    }

    public function delete()
    {
        $this->removeTmpDir();
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url ?? '';
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = (string)$url;

        return $this;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->user['username'] ?? '';
    }

    /**
     * @return string
     */
    public function getUserEmail()
    {
        return $this->user['email'] ?? '';
    }

    /**
     * @param User|null $user
     * @return $this
     */
    public function setUser(User $user = null)
    {
        if ($user && $user->username) {
            $this->user = [
                'username' => $user->username,
                'email' => $user->email
            ];
        } else {
            $this->user = null;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getFiles()
    {
        $fields = [];
        $old = $this->getFilesByField();
        foreach ($old as $field => $files) {
            foreach ($files as $file) {
                $file['tmp_name'] = $this->getTmpDir() . '/' . $file['tmp_name'];
                $fields[$field][$file['path'] ?? $file['name']] = $file;
            }
        }

        return $fields;
    }

    /**
     * @param string|null $field
     * @return bool
     */
    public function hasFiles($field = null)
    {
        return (bool)$this->getFilesByField($field);
    }

    /**
     * @param string $field
     * @param string $filename
     * @param array $upload
     * @return bool
     */
    public function uploadFile($field, $filename, array $upload)
    {
        $tmp_dir = $this->getTmpDir();

        Folder::create($tmp_dir);

        $tmp_file = $upload['file']['tmp_name'];
        $basename = basename($tmp_file);

        if (!move_uploaded_file($tmp_file, $tmp_dir . '/' . $basename)) {
            return false;
        }

        $upload['file']['tmp_name'] = $basename;

        if (!isset($this->uploads[$field])) {
            $this->uploads[$field] = [];
        }

        // Prepare object for later save
        $upload['file']['name'] = $filename;

        if (isset($this->uploads[$field][$filename])) {
            $oldUpload = $this->uploads[$field][$filename];

            // Replace old file, including original
            $this->removeTmpFile($oldUpload['original']['tmp_name'] ?? '');
            $this->removeTmpFile($oldUpload['tmp_name']);
        }

        // Prepare data to be saved later
        $this->uploads[$field][$filename] = (array) $upload['file'];

        return true;
    }

    /**
     * @param string $field
     * @param string $filename
     * @param array $upload
     * @param array $crop
     * @return bool
     */
    public function cropFile($field, $filename, array $upload, array $crop)
    {
        $tmp_dir = $this->getTmpDir();

        Folder::create($tmp_dir);

        $tmp_file = $upload['file']['tmp_name'];
        $basename = basename($tmp_file);

        if (!move_uploaded_file($tmp_file, $tmp_dir . '/' . $basename)) {
            return false;
        }

        $upload['file']['tmp_name'] = $basename;

        if (!isset($this->uploads[$field])) {
            $this->uploads[$field] = [];
        }

        // Prepare object for later save
        $upload['file']['name'] = $filename;

        if (isset($this->uploads[$field][$filename])) {
            $oldUpload = $this->uploads[$field][$filename];
            if (isset($oldUpload['original'])) {
                // Replace old resized file
                $this->removeTmpFile($oldUpload['tmp_name']);
                $upload['file']['original'] = $oldUpload['original'];
            } else {
                $upload['file']['original'] = $oldUpload;
            }
            $upload['file']['crop'] = $crop;
        }

        // Prepare data to be saved later
        $this->uploads[$field][$filename] = (array) $upload['file'];

        return true;
    }

    /**
     * @param string $field
     * @param string $filename
     * @return bool
     */
    public function removeFile($field, $filename)
    {
        if (!$field || !$filename) {
            return false;
        }

        $file = $this->getTmpIndex();
        if (!$file->exists()) {
            return false;
        }

        $endpoint = $this->uploads[$field][$filename] ?? null;

        if (null !== $endpoint) {
            $this->removeTmpFile($endpoint['original']['tmp_name'] ?? '');
            $this->removeTmpFile($endpoint['tmp_name'] ?? '');
        }

        // Walk backward to cleanup any empty field that's left
        unset($this->uploads[$field][$filename]);
        if (empty($this->uploads[$field])) {
            unset($this->uploads[$field]);
        }

        return true;
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'form' => $this->form,
            'unique_id' => $this->uniqueId,
            'url' => $this->url,
            'user' => $this->user,
            'uploads' => $this->uploads
        ];
    }

    /**
     * @return string
     */
    public function getTmpDir()
    {
        $grav = Grav::instance();

        /** @var Session $session */
        $session = $grav['session'];

        $location = [
            'forms',
            $session->getId(),
            $this->uniqueId ?: $this->form
        ];

        return $grav['locator']->findResource('tmp://', true, true) . '/' . implode('/', $location);
    }

    /**
     * @return CompiledYamlFile
     */
    protected function getTmpIndex()
    {
        return CompiledYamlFile::instance($this->getTmpDir() . '/index.yaml');
    }

    /**
     * @param string $name
     */
    protected function removeTmpFile($name)
    {
        $filename = $this->getTmpDir() . '/' . $name;
        if ($name && is_file($filename)) {
            unlink($filename);
        }
    }

    protected function removeTmpDir()
    {
        $tmpDir = $this->getTmpDir();
        if (file_exists($tmpDir)) {
            Folder::delete($tmpDir);
        }
    }

    /**
     * @param string|null $field
     * @return array
     */
    public function getFilesByField($field = null)
    {
        if ($field) {
            return $this->uploads[$field] ?? [];
        }

        return $this->uploads ?? [];
    }
}
