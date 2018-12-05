<?php
namespace Grav\Plugin\Form;

use Grav\Common\Config\Config;
use Grav\Common\Data\Data;
use Grav\Common\Data\Blueprint;
use Grav\Common\Data\ValidationException;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Form\FormFlash;
use Grav\Common\Grav;
use Grav\Common\Inflector;
use Grav\Common\Iterator;
use Grav\Common\Language\Language;
use Grav\Common\Page\Page;
use Grav\Common\Session;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Framework\Form\FormFlashFile;
use RocketTheme\Toolbox\Event\Event;

class Form extends Iterator
{
    const BYTES_TO_MB = 1048576;

    /**
     * @var string
     */
    public $message;

    /**
     * @var int
     */
    public $response_code;

    /**
     * @var string
     */
    public $status = 'success';

    /**
     * @var array
     */
    protected $header_data = [];

    /**
     * @var array
     */
    protected $rules = [];

    /**
     * Data values of the form (values to be stored)
     *
     * @var Data $data
     */
    protected $data;

    /**
     * Form header items
     *
     * @var Data $items
     */
    protected $items = [];

    /**
     * @var FormFlash
     */
    protected $flash;

    /**
     * All the form data values, including non-data
     *
     * @var Data $values
     */
    protected $values;

    /**
     * The form page object
     *
     * @var Page $page
     */
    protected $page;


    /**
     * Create form for the given page.
     *
     * @param Page $page
     * @param string|int|null $name
     * @param null $form
     */
    public function __construct(Page $page, $name = null, $form = null)
    {
        parent::__construct();

        $this->page = $page->route();

        $header = $page->header();
        $this->rules = $header->rules ?? [];
        $this->header_data = $header->data ?? [];

        if ($form) {
            // If form is given, use it.
            $this->items = $form;
        } elseif ($name && isset($header->forms[$name])) {
            // If form with that name was found, use that.
             $this->items = $header->forms[$name];
        } elseif (isset($header->form)) {
            // For backwards compatibility.
            $this->items = $header->form;
        } elseif (!empty($header->forms)) {
            // Pick up the first form.
            $form = reset($header->forms);
            $name = key($header->forms);
            $this->items = $form;
        }

        // Add form specific rules.
        if (!empty($this->items['rules']) && \is_array($this->items['rules'])) {
            $this->rules += $this->items['rules'];
        }

        // Set form name if not set.
        if ($name && !\is_int($name)) {
            $this->items['name'] = $name;
        } elseif (empty($this->items['name'])) {
            $this->items['name'] = $page->slug();
        }

        // Set form id if not set.
        if (empty($this->items['id'])) {
            $inflector = new Inflector();
            $this->items['id'] = $inflector->hyphenize($this->items['name']);
        }
        if (empty($this->items['uniqueid'])) {
            $this->items['uniqueid'] = Utils::generateRandomString(20);
        }

        if (empty($this->items['nonce']['name'])) {
            $this->items['nonce']['name'] = 'form-nonce';
        }

        if (empty($this->items['nonce']['action'])) {
            $this->items['nonce']['action'] = 'form';
        }

        // Reset and initialize the form
        $this->reset();
    }

    /**
     * Custom serializer for this complex object
     *
     * @return string
     */
    public function serialize()
    {
        $data = [
            'items' => $this->items,
            'message' => $this->message,
            'status' => $this->status,
            'header_data' => $this->header_data,
            'rules' => $this->rules,
            'data' => $this->data->toArray(),
            'values' => $this->values->toArray(),
            'page' => $this->page
        ];
        return serialize($data);
    }

    /**
     * Custom unserializer for this complex object
     *
     * @param string $data
     */
    public function unserialize($data)
    {
        $data = unserialize($data);

        $this->items = $data['items'];
        $this->message = $data['message'];
        $this->status = $data['status'];
        $this->header_data = $data['header_data'];
        $this->rules = $data['rules'];

        $name = $this->items['name'];
        $items = $this->items;
        $rules = $this->rules;

        $blueprint  = function () use ($name, $items, $rules) {
            $blueprint = new Blueprint($name, ['form' => $items, 'rules' => $rules]);
            return $blueprint->load()->init();
        };

        $this->data = new Data($data['data'], $blueprint);
        $this->values = new Data($data['values']);
        $this->page = $data['page'];
    }

    /**
     * Allow overriding of fields.
     *
     * @param array $fields
     */
    public function setFields(array $fields = [])
    {
        // Make sure blueprints are updated, otherwise validation may fail.
        $blueprint = $this->data->blueprints();
        $blueprint->set('form/fields', $fields);
        $blueprint->undef('form/field');

        $this->fields = $fields;
    }

    /**
     * Get the name of this form.
     *
     * @return String
     */
    public function name()
    {
        return $this->items['name'];
    }

    /**
     * Get the nonce value for a form
     *
     * @return string
     */
    public function getNonce()
    {
        return Utils::getNonce($this->getNonceAction());
    }

    /**
     * @return string
     */
    public function getNonceName()
    {
        return $this->items['nonce']['name'];
    }

    /**
     * @return string
     */
    public function getNonceAction()
    {
        return $this->items['nonce']['action'];
    }

    /**
     * Reset data.
     */
    public function reset()
    {
        $name = $this->items['name'];
        $grav = Grav::instance();

        // Fix naming for fields (supports nested fields now!)
        if (isset($this->items['fields'])) {
            $this->items['fields'] = $this->processFields($this->items['fields']);
        }

        $items = $this->items;
        $rules = $this->rules;

        $blueprint = function() use ($name, $items, $rules) {
            $blueprint = new Blueprint($name, ['form' => $items, 'rules' => $rules]);
            return $blueprint->load()->init();
        };

        $this->data = new Data($this->header_data, $blueprint);
        $this->values = new Data();
        $this->fields = $this->fields(true);

        // Fire event
        $grav->fireEvent('onFormInitialized', new Event(['form' => $this]));
    }

    protected function processFields($fields)
    {
        $types = Grav::instance()['plugins']->formFieldTypes;

        $return = array();
        foreach ($fields as $key => $value) {
            // default to text if not set
            if (!isset($value['type'])) {
                $value['type'] = 'text';
            }

            // manually merging the field types
            if ($types !== null && array_key_exists($value['type'], $types)) {
                $value += $types[$value['type']];
            }

            // Fix numeric indexes
            if (is_numeric($key) && isset($value['name'])) {
                $key = $value['name'];
            }
            if (isset($value['fields']) && \is_array($value['fields'])) {
                $value['fields'] = $this->processFields($value['fields']);
            }
            $return[$key] = $value;
        }
        return $return;
    }

    public function fields($reset = false)
    {

        if ($reset || null === $this->fields) {
            $blueprint = $this->data->blueprints();

            if (method_exists($blueprint, 'load')) {
                // init the form to process directives
                $blueprint->load()->init();

                // fields set to processed blueprint fields
                $this->fields = $blueprint->fields();
            }
        }

        return $this->fields;
    }

    /**
     * Return page object for the form.
     *
     * @return Page
     */
    public function page()
    {
        return Grav::instance()['pages']->dispatch($this->page);
    }

    /**
     * Get value of given variable (or all values).
     * First look in the $data array, fallback to the $values array
     *
     * @param string $name
     *
     * @return mixed
     */
    public function value($name = null, $fallback = false)
    {
        if (!$name) {
            return $this->data;
        }

        if ($this->data->get($name)) {
            return $this->data->get($name);
        }

        if ($fallback) {
            return $this->values->get($name);
        }

        return null;
    }

    /**
     * Set value of given variable in the values array
     *
     * @param string $name
     */
    public function setValue($name = null, $value = '')
    {
        if (!$name) {
            return;
        }

        $this->values->set($name, $value);
    }

    /**
     * Get a value from the form
     *
     * @param $name
     * @return mixed
     */
    public function getValue($name)
    {
        return $this->values->get($name);
    }

    /**
     * @return Data
     */
    public function getValues()
    {
        return $this->values;
    }

    /**
     * Get all data
     *
     * @return Data
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Set value of given variable in the data array
     *
     * @param string $name
     * @param string $value
     *
     * @return bool
     */
    public function setData($name = null, $value = '')
    {
        if (!$name) {
            return false;
        }

        $this->data->set($name, $value);

        return true;
    }

    public function setAllData($array)
    {
        $this->data = new Data($array);
    }

    /**
     * Handles ajax upload for files.
     * Stores in a flash object the temporary file and deals with potential file errors.
     *
     * @return mixed True if the action was performed.
     */
    public function uploadFiles()
    {
        $grav = Grav::instance();

        /** @var Language $language */
        $language = $grav['language'];
        /** @var Config $config */
        $config = $grav['config'];
        /** @var Uri $uri */
        $uri = $grav['uri'];

        $url = $uri->url;
        $post = $uri->post();

        $name = $post['name'] ?? null;
        $task = $post['task'] ?? null;
        $this->items['name'] = $formName = $post['__form-name__'] ?? $this->items['name'];
        $this->items['uniqueid'] = $uniqueId = $post['__unique_form_id__'] ?? $formName;

        $settings = $this->data->blueprints()->schema()->getProperty($name);
        $settings = (object) array_merge(
            ['destination' => $config->get('plugins.form.files.destination', 'self@'),
             'avoid_overwriting' => $config->get('plugins.form.files.avoid_overwriting', false),
             'random_name' => $config->get('plugins.form.files.random_name', false),
             'accept' => $config->get('plugins.form.files.accept', ['image/*']),
             'limit' => $config->get('plugins.form.files.limit', 10),
             'filesize' => static::getMaxFilesize(),
            ],
            (array) $settings,
            ['name' => $name]
        );
        // Allow plugins to adapt settings for a given post name
        // Useful if schema retrieval is not an option, e.g. dynamically created forms
        $grav->fireEvent('onFormUploadSettings', new Event(['settings' => &$settings, 'post' => $post]));
        
        $upload = json_decode(json_encode($this->normalizeFiles($_FILES['data'], $settings->name)), true);
        $filename = $post['filename'] ?? $upload['file']['name'];
        $field = $upload['field'];

        // Handle errors and breaks without proceeding further
        if ($upload['file']['error'] !== UPLOAD_ERR_OK) {
            // json_response
            return [
                'status' => 'error',
                'message' => sprintf($language->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_UPLOAD', null, true), $filename, $this->upload_errors[$upload['file']['error']])
            ];
        }

        // Handle bad filenames.
        if (!Utils::checkFilename($filename)) {
            return [
                'status'  => 'error',
                'message' => sprintf($language->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_UPLOAD', null),
                    $filename, 'Bad filename')
            ];
        }

        if (!isset($settings->destination)) {
            return [
                'status'  => 'error',
                'message' => $language->translate('PLUGIN_FORM.DESTINATION_NOT_SPECIFIED', null)
            ];
        }

        // Remove the error object to avoid storing it
        unset($upload['file']['error']);


        // Handle Accepted file types
        // Accept can only be mime types (image/png | image/*) or file extensions (.pdf|.jpg)
        $accepted = false;
        $errors = [];

        // Do not trust mimetype sent by the browser
        $mime = Utils::getMimeByFilename($filename);

        foreach ((array)$settings->accept as $type) {
            // Force acceptance of any file when star notation
            if ($type === '*') {
                $accepted = true;
                break;
            }

            $isMime = strstr($type, '/');
            $find   = str_replace(['.', '*'], ['\.', '.*'], $type);

            if ($isMime) {
                $match = preg_match('#' . $find . '$#', $mime);
                if (!$match) {
                    $errors[] = sprintf($language->translate('PLUGIN_FORM.INVALID_MIME_TYPE', null, true), $mime, $filename);
                } else {
                    $accepted = true;
                    break;
                }
            } else {
                $match = preg_match('#' . $find . '$#', $filename);
                if (!$match) {
                    $errors[] = sprintf($language->translate('PLUGIN_FORM.INVALID_FILE_EXTENSION', null, true), $filename);
                } else {
                    $accepted = true;
                    break;
                }
            }
        }

        if (!$accepted) {
            // json_response
            return [
                'status' => 'error',
                'message' => implode('<br/>', $errors)
            ];
        }


        // Handle file size limits
        $settings->filesize *= self::BYTES_TO_MB; // 1024 * 1024 [MB in Bytes]
        if ($settings->filesize > 0 && $upload['file']['size'] > $settings->filesize) {
            // json_response
            return [
                'status'  => 'error',
                'message' => $language->translate('PLUGIN_FORM.EXCEEDED_GRAV_FILESIZE_LIMIT')
            ];
        }

        // Generate random name if required
        if ($settings->random_name) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = Utils::generateRandomString(15) . '.' . $extension;
        }

        // Look up for destination
        $destination = $this->getPagePathFromToken(Folder::getRelativePath(rtrim($settings->destination, '/')));

        // Handle conflicting name if needed
        if ($settings->avoid_overwriting) {
            if (file_exists($destination . '/' . $filename)) {
                $filename = date('YmdHis') . '-' . $filename;
            }
        }

        // Prepare object for later save
        $path = $destination . '/' . $filename;
        $upload['file']['name'] = $filename;
        $upload['file']['path'] = $path;

        // We need to store the file into flash object or it will not be available upon save later on.
        $flash = $this->getFlash();
        $flash->setUrl($url)->setUser($grav['user']);

        if ($task === 'cropupload') {
            $crop = $post['crop'];
            if (\is_string($crop)) {
                $crop = json_decode($crop, true);
            }
            $success = $flash->cropFile($field, $filename, $upload, $crop);
        } else {
            $success = $flash->uploadFile($field, $filename, $upload);
        }

        if (!$success) {
            // json_response
            return [
                'status' => 'error',
                'message' => sprintf($language->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '', $flash->getTmpDir())
            ];
        }

        $flash->save();

        // json_response
        $json_response = [
            'status' => 'success',
            'session' => \json_encode([
                'sessionField' => base64_encode($url),
                'path' => $path,
                'field' => $settings->name,
                'uniqueid' => $uniqueId
            ])
        ];

        // Return JSON
        header('Content-Type: application/json');
        echo json_encode($json_response);
        exit;
    }

    /**
     * Removes a file from the flash object session, before it gets saved
     *
     * @return bool True if the action was performed.
     */
    public function filesSessionRemove()
    {
        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri  = $grav['uri'];
        $post = $uri->post();
        $field = $post['name'] ?? null;
        $filename = $post['filename'] ?? null;

        if (!isset($field, $filename)) {
            return false;
        }

        $this->items['name'] = $post['__form-name__'] ?? $this->items['name'];
        $this->items['uniqueid'] = $post['__unique_form_id__'] ?? $this->items['name'];

        // Remove image from flash object
        $flash = $this->getFlash();
        $flash->removeFile($filename, $field);
        $flash->save();

        // json_response
        $json_response = ['status' => 'success'];

        // Return JSON
        header('Content-Type: application/json');
        echo json_encode($json_response);
        exit;
    }

    /**
     * Handle form processing on POST action.
     */
    public function post()
    {
        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri = $grav['uri'];

        // Get POST data and decode JSON fields into arrays
        $post = $uri->post();
        $post['data'] = $this->decodeData($post['data'] ?? []);

        $this->items['name'] = $post['__form-name__'] ?? $this->items['name'];
        $this->items['uniqueid'] = $post['__unique_form_id__'] ?? $this->items['name'];

        if ($post) {
            $this->values = new Data((array)$post);
            $data = $this->values->get('data');

            // Add post data to form dataset
            if (!$data) {
                $data = $this->values->toArray();
            }

            if (!$this->values->get('form-nonce') || !Utils::verifyNonce($this->values->get('form-nonce'), 'form')) {
                $this->status = 'error';
                $event = new Event(['form' => $this,
                    'message' => $grav['language']->translate('PLUGIN_FORM.NONCE_NOT_VALIDATED')
                ]);
                $grav->fireEvent('onFormValidationError', $event);

                return;
            }

            $i = 0;
            foreach ($this->items['fields'] as $key => $field) {
                $name = $field['name'] ?? $key;
                if (!isset($field['name'])) {
                    if (isset($data[$i])) { //Handle input@ false fields
                        $data[$name] = $data[$i];
                        unset($data[$i]);
                    }
                }
                if ($field['type'] === 'checkbox' || $field['type'] === 'switch') {
                    $data[$name] = isset($data[$name]) ? true : false;
                }
                $i++;
            }

            $this->data->merge($data);
        }

        // Validate and filter data
        try {
            $grav->fireEvent('onFormPrepareValidation', new Event(['form' => $this]));

            $this->data->validate();
            $this->data->filter();

            $grav->fireEvent('onFormValidationProcessed', new Event(['form' => $this]));
        } catch (ValidationException $e) {
            $this->status = 'error';
            $event = new Event(['form' => $this, 'message' => $e->getMessage(), 'messages' => $e->getMessages()]);
            $grav->fireEvent('onFormValidationError', $event);
            if ($event->isPropagationStopped()) {
                return;
            }
        } catch (\RuntimeException $e) {
            $this->status = 'error';
            $event = new Event(['form' => $this, 'message' => $e->getMessage(), 'messages' => []]);
            $grav->fireEvent('onFormValidationError', $event);
            if ($event->isPropagationStopped()) {
                return;
            }
        }

        $this->legacyUploads();

        $redirect = $redirect_code = null;
        $process = $this->items['process'] ?? [];
        if (\is_array($process)) {
            foreach ($process as $action => $data) {
                if (is_numeric($action)) {
                    $action = \key($data);
                    $data = $data[$action];
                }

                $event = new Event(['form' => $this, 'action' => $action, 'params' => $data]);
                $grav->fireEvent('onFormProcessed', $event);

                if ($event['redirect']) {
                    $redirect = $event['redirect'];
                    $redirect_code = $event['redirect_code'];
                }
                if ($event->isPropagationStopped()) {
                    break;
                }
            }
        }

        $this->copyFiles();

        if ($redirect) {
            $grav->redirect($redirect, $redirect_code);
        }
    }

    protected function legacyUploads()
    {
        // Get flash object in order to save the files.
        $flash = $this->getFlash();
        $queue = $verify = $flash->getLegacyFiles();

        if (!$queue) {
            return;
        }

        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri = $grav['uri'];

        // Get POST data and decode JSON fields into arrays
        $post = $uri->post();
        $post['data'] = $this->decodeData($post['data'] ?? []);

        // Allow plugins to implement additional / alternative logic
        $grav->fireEvent('onFormStoreUploads', new Event(['form' => $this, 'queue' => &$queue, 'post' => $post]));

        $modified = $queue !== $verify;

        if (!$modified) {
            // Fill file fields just like before.
            foreach ($queue as $key => $files) {
                foreach ($files as $destination => $file) {
                    unset($files[$destination]['tmp_name']);
                }

                $this->data->merge([$key => $files]);
            }
        } else {
            user_error('Event onFormStoreUploads is deprecated.', E_USER_DEPRECATED);

            if (\is_array($queue)) {
                foreach ($queue as $key => $files) {
                    foreach ($files as $destination => $file) {
                        if (!rename($file['tmp_name'], $destination)) {
                            $grav = Grav::instance();
                            throw new \RuntimeException(sprintf($grav['language']->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '"' . $file['tmp_name'] . '"', $destination));
                        }

                        if (file_exists($file['tmp_name'] . '.yaml')) {
                            unlink($file['tmp_name'] . '.yaml');
                        }

                        unset($files[$destination]['tmp_name']);
                    }

                    $this->data->merge([$key => $files]);
                }
            }

            $flash->delete();
        }
    }

    /**
     * Store form uploads to the final location.
     */
    public function copyFiles()
    {
        // Get flash object in order to save the files.
        $flash = $this->getFlash();
        $fields = $flash->getFilesByFields();

        foreach ($fields as $key => $uploads) {
            /** @var FormFlashFile $upload */
            foreach ($uploads as $upload) {
                if (null === $upload || $upload->isMoved()) {
                    continue;
                }

                $destination = $upload->getDestination();
                try {
                    $upload->moveTo($destination);
                } catch (\RuntimeException $e) {
                    $grav = Grav::instance();
                    throw new \RuntimeException(sprintf($grav['language']->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '"' . $upload->getClientFilename() . '"', $destination));
                }
            }
        }

        $flash->delete();
    }

    /**
     * Get flash object
     *
     * @return FormFlash
     */
    public function getFlash()
    {
        if (null === $this->flash) {
            /** @var Session $session */
            $session = Grav::instance()['session'];
            $this->flash = new FormFlash($session->getId(), $this->items['uniqueid'] ?? $this->items['name'], $this->items['name']);
        }

        return $this->flash;
    }

    public function getPagePathFromToken($path)
    {
        return Utils::getPagePathFromToken($path, $this->page());
    }

    public function responseCode($code = null)
    {
        if ($code) {
            $this->response_code = $code;
        }
        return $this->response_code;
    }

    /**
     * Decode data
     *
     * @param array $data
     * @return array
     */
    protected function decodeData($data)
    {
        if (!\is_array($data)) {
            return [];
        }

        // Decode JSON encoded fields and merge them to data.
        if (isset($data['_json'])) {
            $data = array_replace_recursive($data, $this->jsonDecode($data['_json']));
            unset($data['_json']);
        }

        $data = $this->cleanDataKeys($data);

        return $data;
    }

    /**
     * Recursively JSON decode data.
     *
     * @param  array $data
     *
     * @return array
     */
    protected function jsonDecode(array $data)
    {
        foreach ($data as &$value) {
            if (\is_array($value)) {
                $value = $this->jsonDecode($value);
            } else {
                $value = json_decode($value, true);
            }
        }

        return $data;
    }

    /**
     * Decode [] in the data keys
     *
     * @param array $source
     * @return array
     */
    protected function cleanDataKeys($source = [])
    {
        $out = [];

        if (\is_array($source)) {
            foreach ($source as $key => $value) {
                $key = str_replace(['%5B', '%5D'], ['[', ']'], $key);
                if (\is_array($value)) {
                    $out[$key] = $this->cleanDataKeys($value);
                } else {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Internal method to normalize the $_FILES array
     *
     * @param array  $data $_FILES starting point data
     * @param string $key
     * @return object a new Object with a normalized list of files
     */
    protected function normalizeFiles($data, $key = '')
    {
        $files = new \stdClass();
        $files->field = $key;
        $files->file = new \stdClass();

        foreach ($data as $fieldName => $fieldValue) {
            // Since Files Upload are always happening via Ajax
            // we are not interested in handling `multiple="true"`
            // because they are always handled one at a time.
            // For this reason we normalize the value to string,
            // in case it is arriving as an array.
            $value = (array) Utils::getDotNotation($fieldValue, $key);
            $files->file->{$fieldName} = array_shift($value);
        }

        return $files;
    }

    /**
     * Get the configured max file size in bytes
     *
     * @param bool $mbytes return size in MB
     * @return int
     */
    public static function getMaxFilesize($mbytes = false)
    {
        $config = Grav::instance()['config'];

        $filesize_mb = (int)($config->get('plugins.form.files.filesize', 0) * static::BYTES_TO_MB);
        $system_filesize = $config->get('system.media.upload_limit', 0);
        if ($filesize_mb > $system_filesize || $filesize_mb === 0) {
            $filesize_mb = $system_filesize;
        }

        if ($mbytes) {
            return $filesize_mb;
        }

        return $filesize_mb  / static::BYTES_TO_MB;
    }
}
