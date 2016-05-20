<?php
namespace Grav\Plugin;

class FormSerializable
{
    public $fields;
    public $data;
    public $message;
    public $message_color;

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
}