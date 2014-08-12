<?php
namespace Grav\Plugin;

use \Grav\Common\Plugin;
use \Grav\Common\Registry;
use \Grav\Common\Page\Page;
use \Grav\Common\Page\Pages;
use \Grav\Common\Grav;
use \Grav\Common\Uri;
use \Grav\Common\Filesystem\File;
use \Grav\Common\Twig;
use \Grav\Plugin\Form;

class FormPlugin extends Plugin
{
    /**
     * @var bool
     */
    protected $active = false;

    /**
     * @var Form
     */
    protected $form;

    /**
     * @var Grav
     */
    protected $grav;

    /**
     * Initialize form if the page has one. Also catches form processing if user posts the form.
     */
    public function onAfterGetPage()
    {
        $this->grav = Registry::get('Grav');

        /** @var Page $page */
        $page = $this->grav->page;
        if (!$page) {
            return;
        }

        $header = $page->header();
        if (isset($header->form) && is_array($header->form)) {
            $this->active = true;

            // Create form.
            require_once __DIR__ . '/classes/form.php';
            $this->form = new Form($page);

            // Handle posting if needed.
            if (!empty($_POST)) {
                $this->form->post();
            }

            $registry = Registry::instance();
            $registry->store('Form', $this->form);
        }
    }

        /**
     * Add current directory to twig lookup paths.
     */
    public function onAfterTwigTemplatesPaths()
    {
        Registry::get('Twig')->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Make form accessible from twig.
     */
    public function onAfterTwigSiteVars()
    {
        if (!$this->active) {
            return;
        }

        /** @var Twig $twig */
        $twig = Registry::get('Twig');
        /** @var Form $form */
        $form = Registry::get('Form');

        $twig->twig_vars['form'] = $form;
    }

    /**
     * Handle form processing instructions.
     *
     * @param Form $form
     * @param string $task
     * @param string $params
     */
    public function onProcessForm(Form $form, $task, $params)
    {
        if (!$this->active) {
            return;
        }

        switch ($task) {
            case 'message':
                $this->form->message = (string) $params;
                break;
            case 'redirect':
                $this->grav->redirect((string) $params);
                break;
            case 'reset':
                if (in_array($params, array(true, 1, '1', 'yes', 'on', 'true'), true)) {
                    $this->form->reset();
                }
                break;
            case 'display':
                $route = (string) $params;
                if (!$route || $route[0] != '/') {
                    /** @var Uri $uri */
                    $uri = Registry::get('Uri');
                    $route = $uri->route() . ($route ? '/' . $route : '');
                }
                /** @var Pages $pages */
                $pages = Registry::get('Pages');
                $this->grav->page = $pages->dispatch($route, true);
                break;
            case 'save':
                $prefix = !empty($params['fileprefix']) ? $params['fileprefix'] : '';
                $format = !empty($params['dateformat']) ? $params['dateformat'] : 'Ymd-His-u';
                $ext = !empty($params['extension']) ? '.' . trim($params['extension'], '.') : '.txt';
                $filename = $prefix . $this->udate($format) . $ext;

                /** @var Twig $twig */
                $twig = Registry::get('Twig');
                $vars = array(
                    'form' => $this->form
                );

                $file = File\General::instance(DATA_DIR . $this->form->name . '/' . $filename);
                $body = $twig->processString(
                    !empty($params['body']) ? $params['body'] : '{% include "forms/data.txt.twig" %}',
                    $vars
                );
                $file->save($body);
        }
    }

    /**
     * Create unix timestamp for storing the data into the filesystem.
     *
     * @param string $format
     * @param int $utimestamp
     * @return string
     */
    private function udate($format = 'u', $utimestamp = null)
    {
        if (is_null($utimestamp)) {
            $utimestamp = microtime(true);
        }

        $timestamp = floor($utimestamp);
        $milliseconds = round(($utimestamp - $timestamp) * 1000000);

        return date(preg_replace('`(?<!\\\\)u`', \sprintf('%06d', $milliseconds), $format), $timestamp);
    }
}
