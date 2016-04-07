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
     * @var Grav $grav
     */
    protected $grav;

    /**
     * @var string
     */
    public $message;

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

        $header = $page->header();
        $this->rules = isset($header->rules) ? $header->rules : [];
        $this->header_data = isset($header->data) ? $header->data : [];
        $this->items = $header->form;

        // Set form name if not set.
        if (empty($this->items['name'])) {
            $this->items['name'] = $page->slug();
        }

        $this->reset();

        // Fire event
        $this->grav->fireEvent('onFormInitialized', new Event(['form' => $this]));
    }

    /**
     * Reset data.
     */
    public function reset()
    {
        $name = $this->items['name'];

        // Fix naming for fields (presently only for toplevel fields)
        foreach ($this->items['fields'] as $key => $field) {
            if (is_numeric($key) && isset($field['name'])) {
                unset($this->items['fields'][$key]);

                $key = $field['name'];
                $this->items['fields'][$key] = $field;
            }
        }

        $blueprint = new Blueprint($name, ['form' => $this->items, 'rules' => $this->rules]);
        $this->data = new Data($this->header_data, $blueprint);
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
            $data = $this->values->get('data');
            $files = (array)$_FILES;

            if (method_exists('Grav\Common\Utils', 'getNonce')) {
                if (!$this->values->get('form-nonce') || !Utils::verifyNonce($this->values->get('form-nonce'), 'form')) {
                    $event = new Event(['form'    => $this,
                                        'message' => $this->grav['language']->translate('PLUGIN_FORM.NONCE_NOT_VALIDATED')
                    ]);
                    $this->grav->fireEvent('onFormValidationError', $event);

                    return;
                }
            }

            foreach ($this->items['fields'] as $field) {
                $name = $field['name'];
                if ($field['type'] == 'checkbox') {
                    $data[$name] = isset($data[$name]) ? true : false;
                }
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

            foreach ($files as $key => $file) {
                $cleanFiles = $this->cleanFilesData($key, $file);
                if ($cleanFiles) {
                    $this->data->set($key, $cleanFiles);
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
                    $data = $data[$action];
                }

                $previousEvent = $event;
                $event = new Event(['form' => $this, 'action' => $action, 'params' => $data]);

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

    private function cleanFilesData($key, $file)
    {
        $config = $this->grav['config'];
        $default = $config->get('plugins.form.files');
        $settings = isset($this->items['fields'][$key]) ? $this->items['fields'][$key] : [];

        /** @var Page $page */
        $page = null;
        $blueprint = array_replace($default, $settings);

        $cleanFiles[$key] = [];
        if (!isset($blueprint)) {
            return false;
        }

        $cleanFiles = [$key => []];
        foreach ((array)$file['error'] as $index => $error) {
            if ($error == UPLOAD_ERR_OK) {
                $tmp_name = $file['tmp_name'][$index];
                $name = $file['name'][$index];
                $type = $file['type'][$index];
                $destination = Folder::getRelativePath(rtrim($blueprint['destination'], '/'));

                if (!$this->match_in_array($type, $blueprint['accept'])) {
                    throw new \RuntimeException('File "' . $name . '" is not an accepted MIME type.');
                }

                if (Utils::startsWith($destination, '@page:')) {
                    $parts = explode(':', $destination);
                    $route = $parts[1];
                    $page = $this->grav['page']->find($route);

                    if (!$page) {
                        throw new \RuntimeException('Unable to upload file to destination. Page route not found.');
                    }

                    $destination = $page->relativePagePath();
                } else {
                    if ($destination == '@self') {
                        $page = $this->grav['page'];
                        $destination = $page->relativePagePath();
                    } else {
                        Folder::mkdir($destination);
                    }
                }

                if (move_uploaded_file($tmp_name, "$destination/$name")) {
                    $path = $page ? $this->grav['uri']->convertUrl($page,
                        $page->route() . '/' . $name) : $destination . '/' . $name;
                    $cleanFiles[$key][$path] = [
                        'name'  => $file['name'][$index],
                        'type'  => $file['type'][$index],
                        'size'  => $file['size'][$index],
                        'file'  => $destination . '/' . $name,
                        'route' => $page ? $path : null
                    ];
                } else {
                    throw new \RuntimeException("Unable to upload file(s) to $destination/$name");
                }
            }
        }

        return $cleanFiles[$key];
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
