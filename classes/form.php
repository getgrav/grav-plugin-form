<?php
namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Data\Blueprint;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Inflector;
use Grav\Common\Iterator;
use Grav\Common\Page\Page;
use Grav\Common\Twig\Twig;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;

class Form extends Iterator implements \Serializable
{
    /**
     * @var string
     */
    public $message;

    /**
     * @var string
     */
    public $message_color;
    
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
     */
    public function __construct(Page $page, $name = null, $form = null)
    {
        parent::__construct();

        $this->page = $page->route();

        $header            = $page->header();
        $this->rules       = isset($header->rules) ? $header->rules : [];
        $this->header_data = isset($header->data) ? $header->data : [];

        if ($form) {
            $this->items = $form;
        } else {
            $this->items = $header->form; // for backwards compatibility
        }

        // Add form specific rules.
        if (!empty($this->items['rules']) && is_array($this->items['rules'])) {
            $this->rules += $this->items['rules'];
        }

        // Set form name if not set.
        if ($name && !is_int($name)) {
            $this->items['name'] = $name;
        } elseif (empty($this->items['name'])) {
            $this->items['name'] = $page->slug();
        }

        // Set form id if not set.
        if (empty($this->items['id'])) {
            $inflector = new Inflector();
            $this->items['id'] = $inflector->hyphenize($this->items['name']);
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
            'message_color' => $this->message_color,
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
        $this->message_color = $data['message_color'];
        $this->header_data = $data['header_data'];
        $this->rules = $data['rules'];

        $name = $this->items['name'];
        $items = $this->items;
        $rules = $this->rules;

        $blueprint  = function() use ($name, $items, $rules) {
            return new Blueprint($name, ['form' => $items, 'rules' => $rules]);
        };

        $this->data = new Data($data['data'], $blueprint);
        $this->values = new Data($data['values']);
        $this->page = $data['page'];
    }

    /**
     * Allow overriding of fields
     *
     * @param $fields
     */
    public function setFields($fields)
    {
        $this->fields = $fields;
    }

    /**
     * Get the name of this form
     *
     * @return String
     */
    public function name()
    {
        return $this->items['name'];
    }

    /**
     * Reset data.
     */
    public function reset()
    {
        $name = $this->items['name'];
        $grav = Grav::instance();

        // Fix naming for fields (presently only for toplevel fields)
        foreach ($this->items['fields'] as $key => $field) {
            // default to text if not set
            if (!isset($field['type'])) {
                $field['type'] = 'text';
            }

            $types = $grav['plugins']->formFieldTypes;

            // manually merging the field types
            if ($types !== null && key_exists($field['type'], $types)) {
                $field += $types[$field['type']];
            }

            // BC for old style of array style field definitions
            if (is_numeric($key) && isset($field['name'])) {
                unset($this->items['fields'][$key]);
                $key = $field['name'];
            }

            // Add name based on key if not already set
            if (!isset($field['name'])) {
                $field['name'] = $key;
            }

            // set any modifications back on the fields array
            $this->items['fields'][$key] = $field;

        }

        $items = $this->items;
        $rules = $this->rules;
        $blueprint  = function() use ($name, $items, $rules) {
            return new Blueprint($name, ['form' => $items, 'rules' => $rules]);
        };

        if (method_exists($blueprint, 'load')) {
            // init the form to process directives
            $blueprint->load()->init();

            // fields set to processed blueprint fields
            $this->fields = $blueprint->fields();
        }

        $this->data   = new Data($this->header_data, $blueprint);
        $this->values = new Data();

        // Fire event
        $grav->fireEvent('onFormInitialized', new Event(['form' => $this]));

    }

    public function fields() {

        if (is_null($this->fields)) {
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
     *
     * @return mixed
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
     * Set value of given variable in the data array
     *
     * @param string $name
     *
     * @return mixed
     */
    public function setData($name = null, $value = '')
    {
        if (!$name) {
            return;
        }

        $this->data->set($name, $value);
    }

    /**
     * Handle form processing on POST action.
     */
    public function post()
    {
        $files = [];
        $grav = Grav::instance();

        if (isset($_POST)) {
            $this->values = new Data(isset($_POST) ? (array)$_POST : []);
            $data         = $this->values->get('data');
            $files        = (array)$_FILES;

            // Add post data to form dataset
            if (!$data) {
                $data = $this->values->toArray();
            }

            if (method_exists('Grav\Common\Utils', 'getNonce')) {
                if (!$this->values->get('form-nonce') || !Utils::verifyNonce($this->values->get('form-nonce'), 'form')) {
                    $event = new Event(['form'    => $this,
                                        'message' => $grav['language']->translate('PLUGIN_FORM.NONCE_NOT_VALIDATED')
                    ]);
                    $grav->fireEvent('onFormValidationError', $event);

                    return;
                }
            }

            $i = 0;
            foreach ($this->items['fields'] as $key => $field) {
                $name = isset($field['name']) ? $field['name'] : $key;
                if (!isset($field['name'])) {
                    if (isset($data[$i])) { //Handle input@ false fields
                        $data[$name] = $data[$i];
                        unset($data[$i]);
                    }
                }
                if ($field['type'] == 'checkbox') {
                    $data[$name] = isset($data[$name]) ? true : false;
                }
                $i++;
            }

            $this->data->merge($data);
            $this->data->merge($files);
        }

        // Validate and filter data
        try {
            $this->data->validate();
            $this->data->filter();

            if (isset($files['data'])) {
                $cleanFiles = $this->cleanFilesData($files['data']);

                foreach ($cleanFiles as $key => $data) {
                    $this->data->set($key, $data);
                }
            }

            $grav->fireEvent('onFormValidationProcessed', new Event(['form' => $this]));
        } catch (\RuntimeException $e) {
            $event = new Event(['form' => $this, 'message' => $e->getMessage(), 'messages' => $e->getMessages()]);
            $grav->fireEvent('onFormValidationError', $event);
            if ($event->isPropagationStopped()) {
                return;
            }
        }

        $process = isset($this->items['process']) ? $this->items['process'] : [];
        if (is_array($process)) {
            $event = null;
            foreach ($process as $action => $data) {
                if (is_numeric($action)) {
                    $action = \key($data);
                    $data   = $data[$action];
                }

                $previousEvent = $event;
                $event         = new Event(['form' => $this, 'action' => $action, 'params' => $data]);

                if ($previousEvent) {
                    if (!$previousEvent->isPropagationStopped()) {
                        $grav->fireEvent('onFormProcessed', $event);
                    } else {
                        break;
                    }
                } else {
                    $grav->fireEvent('onFormProcessed', $event);
                }
            }
        } else {
            // Default action.
        }
    }

    private function cleanFilesData($file)
    {
        /** @var Page $page */
        $page       = null;
        $cleanFiles = [];
        $grav       = Grav::instance();
        $config     = $grav['config'];
        $default    = $config->get('plugins.form.files');

        foreach ((array)$file['error'] as $index => $errors) {
            $errors = !is_array($errors) ? [$errors] : $errors;

            foreach ($errors as $multiple_index => $error) {
                if ($error == UPLOAD_ERR_OK) {
                    if (is_array($file['name'][$index])) {
                        $tmp_name = $file['tmp_name'][$index][$multiple_index];
                        $name     = $file['name'][$index][$multiple_index];
                        $type     = $file['type'][$index][$multiple_index];
                        $size     = $file['size'][$index][$multiple_index];
                    } else {
                        $tmp_name = $file['tmp_name'][$index];
                        $name     = $file['name'][$index];
                        $type     = $file['type'][$index];
                        $size     = $file['size'][$index];
                    }
                    $settings    = isset($this->items['fields'][$index]) ? $this->items['fields'][$index] : [];
                    $blueprint   = array_replace($default, $settings);

                    /** @var Twig $twig */
                    $twig = $grav['twig'];
                    $blueprint['destination'] = $twig->processString($blueprint['destination']);
                    
                    $destination = Folder::getRelativePath(rtrim($blueprint['destination'], '/'));
                    $page        = null;

                    if (!isset($blueprint)) {
                        return false;
                    }

                    if (!$this->match_in_array($type, $blueprint['accept'])) {
                        throw new \RuntimeException('File "' . $name . '" is not an accepted MIME type.');
                    }

                    if (Utils::startsWith($destination, '@page:')) {
                        $parts = explode(':', $destination);
                        $route = $parts[1];
                        $page  = $grav['page']->find($route);

                        if (!$page) {
                            throw new \RuntimeException('Unable to upload file to destination. Page route not found.');
                        }

                        $destination = $page->relativePagePath();
                    } else {
                        if ($destination == '@self') {
                            $page        = $grav['page'];
                            $destination = $page->relativePagePath();
                        } else {
                            Folder::mkdir($destination);
                        }
                    }

                    if (file_exists("$destination/$name")) {
                        $name = date('YmdHis') . '-' . $name;
                    }

                    if (move_uploaded_file($tmp_name, "$destination/$name")) {
                        $path     = $page ? $grav['uri']->convertUrl($page, $page->route() . '/' . $name) : $destination . '/' . $name;
                        $fileData = [
                            'name'  => $name,
                            'path'  => $path,
                            'type'  => $type,
                            'size'  => $size,
                            'file'  => $destination . '/' . $name,
                            'route' => $page ? $path : null
                        ];

                        $cleanFiles[$index][$path] = $fileData;
                    } else {
                        throw new \RuntimeException("Unable to upload file(s) to $destination/$name");
                    }
                }
            }
        }

        return $cleanFiles;
    }

    /**
     * Utility function
     *
     * @param $needle
     * @param $haystack
     * @return bool
     */
    private function match_in_array($needle, $haystack)
    {
        foreach ((array)$haystack as $item) {
            if (true == preg_match("#^" . strtr(preg_quote($item, '#'), ['\*' => '.*', '\?' => '.']) . "$#i",
                    $needle)
            ) {
                return true;
            }
        }

        return false;
    }
}
