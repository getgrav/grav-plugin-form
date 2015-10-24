<?php
namespace Grav\Plugin;

use Grav\Common\Iterator;
use Grav\Common\GravTrait;
use Grav\Common\Page\Page;
use Grav\Common\Data\Data;
use Grav\Common\Data\Blueprint;
use RocketTheme\Toolbox\Event\Event;

class Form extends Iterator
{
    use GravTrait;

    /**
     * @var string
     */
    public $message;

    /**
     * @var array
     */
    protected $data = array();

    /**
     * @var array
     */
    protected $rules = array();

    /**
     * @var array
     */
    protected $items = array();

    /**
     * @var array
     */
    protected $values = array();

    /**
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
        $this->page = $page;

        $header = $page->header();
        $this->rules = isset($header->rules) ? $header->rules : array();
        $this->data = isset($header->data) ? $header->data : array();
        $this->items = $header->form;

        // Set form name if not set.
        if (empty($this->items['name'])) {
            $this->items['name'] = $page->slug();
        }

        $this->reset();
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
     *
     * @param string $name
     * @return mixed
     */
    public function value($name = null)
    {
        if (!$name) {
            return $this->values;
        }
        return $this->values->get($name);
    }

    /**
     * Get value of given variable (or all values).
     *
     * @param string $name
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
     * Reset values.
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

        $blueprint = new Blueprint($name, ['form' => $this->items]);
        $this->values = new Data($this->data, $blueprint);
    }

    /**
     * Handle form processing on POST action.
     */
    public function post()
    {
        if (isset($_POST)) {
            $values = (array) $_POST;

            foreach($this->items['fields'] as $field) {
                if ($field['type'] == 'checkbox') {
                    $name = $field['name'];
                    $values[$name] = isset($values[$name]) ? true : false;
                }
            }

            // Add post values to form dataset
            $this->values->merge($values);
        }

        // Validate and filter data
        try {
            $this->values->validate();
            $this->values->filter();

            self::getGrav()->fireEvent('onFormValidation', new Event(['form' => $this, 'data' => $this->values]));
        } catch (\RuntimeException $e) {
            $event = new Event(['form' => $this, 'message' => $e->getMessage()]);
            self::getGrav()->fireEvent('onFormValidationFailed', $event);
            if ($event->isPropagationStopped()) {
                return;
            }
        }

        $process = isset($this->items['process']) ? $this->items['process'] : array();
        if (is_array($process)) {
            foreach ($process as $action => $data) {
                if (is_numeric($action)) {
                    $action = \key($data);
                    $data = $data[$action];
                }
                self::getGrav()->fireEvent('onFormProcessed', new Event(['form' => $this, 'action' => $action, 'params' => $data]));
            }
        } else {
            // Default action.
        }
    }
}
