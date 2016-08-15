<?php
namespace Grav\Plugin;

use Grav\Common\Iterator;
use Grav\Common\Page\Page;

class Forms
{
    protected $forms = [];

    public function __construct(Page $page)
    {
        $page_forms = [];

        $header = $page->header();

        // get the forms from the page headers
        if (isset($header->forms)) {
            $page_forms = $header->forms;
        } elseif (isset($header->form)) {
            $page_forms[] = $header->form;
        }

        foreach ($page_forms as $name => $page_form) {
            $form = new Form($page, $name, $page_form);
            $this->forms[$form['name']] = $form;
        }
    }

    public function getForms()
    {
        return $this->forms;
    }

}