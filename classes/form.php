<?php
namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Data\Blueprint;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Iterator;
use Grav\Common\Page\Page;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;

class Form extends Iterator
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
     * @var Grav $grav
     */
    protected $grav;
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
    public function __construct(Page $page)
    {
        $this->grav = Grav::instance();
        $this->page = $page;

        $header            = $page->header();
        $this->rules       = isset($header->rules) ? $header->rules : [];
        $this->header_data = isset($header->data) ? $header->data : [];
        $this->items       = $header->form;

        // Set form name if not set.
        if (empty($this->items['name'])) {
            $this->items['name'] = $page->slug();
        }

        $this->reset();

        // Fire event
        $this->grav->fireEvent('onFormInitialized', new Event(['form' => $this]));
    }

    public function setFields($fields)
    {
        $this->fields = $fields;
    }

    /**
     * Reset data.
     */
    public function reset()
    {
        $name = $this->items['name'];

        // Fix naming for fields (presently only for toplevel fields)
        foreach ($this->items['fields'] as $key => $field) {
            // default to text if not set
            if (!isset($field['type'])) {
                $field['type'] = 'text';
            }

            $types = Grav::instance()['plugins']->formFieldTypes;

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

        $blueprint    = new Blueprint($name, ['form' => $this->items, 'rules' => $this->rules]);

        if (method_exists($blueprint, 'load')) {
            // init the form to process directives
            $blueprint->load()->init();

            // fields set to processed blueprint fields
            $this->fields = $blueprint->fields();            
        }
        
        $this->data   = new Data($this->header_data, $blueprint);
        $this->values = new Data();
    }

    /**
     * Return page object for the form.
     *
     * @return Page
     */
    public function page()
    {
        return $this->page;
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
        if (isset($_POST)) {
            $this->values = new Data(isset($_POST) ? (array)$_POST : []);
            $data         = $this->values->get('data');
            $files        = (array)$_FILES;

            if (method_exists('Grav\Common\Utils', 'getNonce')) {
                if (!$this->values->get('form-nonce') || !Utils::verifyNonce($this->values->get('form-nonce'), 'form')) {
                    $event = new Event(['form'    => $this,
                                        'message' => $this->grav['language']->translate('PLUGIN_FORM.NONCE_NOT_VALIDATED')
                    ]);
                    $this->grav->fireEvent('onFormValidationError', $event);

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

            // Add post data to form dataset
            if (!$data) {
                $data = $this->values->toArray();
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

            $this->grav->fireEvent('onFormValidationProcessed', new Event(['form' => $this]));
        } catch (\RuntimeException $e) {
            $event = new Event(['form' => $this, 'message' => $e->getMessage()]);
            $this->grav->fireEvent('onFormValidationError', $event);
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
                        $this->grav->fireEvent('onFormProcessed', $event);
                    } else {
                        break;
                    }
                } else {
                    $this->grav->fireEvent('onFormProcessed', $event);
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
        $config     = $this->grav['config'];
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
                    $twig = $this->grav['twig'];
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
                        $page  = $this->grav['page']->find($route);

                        if (!$page) {
                            throw new \RuntimeException('Unable to upload file to destination. Page route not found.');
                        }

                        $destination = $page->relativePagePath();
                    } else {
                        if ($destination == '@self') {
                            $page        = $this->grav['page'];
                            $destination = $page->relativePagePath();
                        } else {
                            Folder::mkdir($destination);
                        }
                    }

                    if (file_exists("$destination/$name")) {
                        $name = date('YmdHis') . '-' . $name;
                    }

                    if (move_uploaded_file($tmp_name, "$destination/$name")) {
                        $path     = $page ? $this->grav['uri']->convertUrl($page, $page->route() . '/' . $name) : $destination . '/' . $name;
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
