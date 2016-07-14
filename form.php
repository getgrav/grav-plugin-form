<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Common\Uri;
use Symfony\Component\Yaml\Yaml;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class FormPlugin
 * @package Grav\Plugin
 */
class FormPlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000
    ];

    /**
     * @var bool
     */
    protected $active = false;

    /**
     * @var Form
     */
    protected $form;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageInitialized'   => ['onPageInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onFormFieldTypes'    => ['onFormFieldTypes', 0]
        ];
    }

    public function onPluginsInitialized()
    {
        require_once(__DIR__ . '/classes/form.php');
        require_once(__DIR__ . '/classes/form_serializable.php');
    }

    /**
     * Initialize form if the page has one. Also catches form processing if user posts the form.
     */
    public function onPageInitialized()
    {


        /** @var Page $page */
        $page = $this->grav['page'];
        if (!$page) {
            return;
        }

        $header = $page->header();
        if (isset($header->form) && is_array($header->form)) {
            $this->active = true;

            // Create form
            $this->form = new Form($page);

            $this->enable([
                'onFormProcessed'       => ['onFormProcessed', 0],
                'onFormValidationError' => ['onFormValidationError', 0]
            ]);

            // Handle posting if needed.
            if (!empty($_POST)) {
                $this->form->post();
            }
        }
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Make form accessible from twig.
     */
    public function onTwigSiteVariables()
    {
        if (!$this->active) {
            return;
        }

        $this->grav['twig']->twig_vars['form'] = $this->form;
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        $this->process($form);

        switch ($action) {
            case 'captcha':
                // Validate the captcha
                $query = http_build_query([
                    'secret'   => isset($params['recaptcha_secret']) ? $params['recaptcha_secret'] : isset($params['recatpcha_secret']) ? $params['recatpcha_secret'] : $this->config->get('plugins.form.recaptcha.secret_key'),
                    'response' => $this->form->value('g-recaptcha-response', true)
                ]);
                $url = 'https://www.google.com/recaptcha/api/siteverify?' . $query;
                $response = json_decode(file_get_contents($url), true);

                if (!isset($response['success']) || $response['success'] !== true) {
                    $this->grav->fireEvent('onFormValidationError', new Event([
                        'form'    => $form,
                        'message' => $this->grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA')
                    ]));
                    $event->stopPropagation();

                    return;
                }
                break;
            case 'ip':
                $label = isset($params['label']) ? $params['label'] : 'User IP';
                $blueprint = $this->form->value()->blueprints();
                $blueprint->set('form/fields/ip', ['name'=>'ip', 'label'=> $label]);
                $this->form->setFields($blueprint->fields());
                $this->form->setData('ip', Uri::ip());
                break;
            case 'message':
                $translated_string = $this->grav['language']->translate($params);
                $vars = array(
                    'form' => $form
                );

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $processed_string = $twig->processString($translated_string, $vars);

                $this->form->message = $processed_string;
                break;
            case 'redirect':
                $form = new FormSerializable();
                $form->message = $this->form->message;
                $form->message_color = $this->form->message_color;
                $form->fields = $this->form->fields;
                $form->data = $this->form->value();
                $this->grav['session']->setFlashObject('form', $form);
                $this->grav->redirect((string)$params);
                break;
            case 'reset':
                if (Utils::isPositive($params)) {
                    $this->form->reset();
                }
                break;
            case 'display':
                $route = (string)$params;
                if (!$route || $route[0] != '/') {
                    /** @var Uri $uri */
                    $uri = $this->grav['uri'];
                    $route = $uri->route() . ($route ? '/' . $route : '');
                }

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $twig->twig_vars['form'] = $form;

                /** @var Pages $pages */
                $pages = $this->grav['pages'];
                $page = $pages->dispatch($route, true);

                if (!$page) {
                    throw new \RuntimeException('Display page not found. Please check the page exists.', 400);
                }

                unset($this->grav['page']);
                $this->grav['page'] = $page;
                break;
            case 'save':
                $prefix = !empty($params['fileprefix']) ? $params['fileprefix'] : '';
                $format = !empty($params['dateformat']) ? $params['dateformat'] : 'Ymd-His-u';
                $ext = !empty($params['extension']) ? '.' . trim($params['extension'], '.') : '.txt';
                $filename = !empty($params['filename']) ? $params['filename'] : '';
                $operation = !empty($params['operation']) ? $params['operation'] : 'create';

                if (!$filename) {
                    $filename = $prefix . $this->udate($format) . $ext;
                }

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $vars = [
                    'form' => $this->form
                ];

                $locator = $this->grav['locator'];
                $path = $locator->findResource('user://data', true);
                $dir = $path . DS . $this->form->name;
                $fullFileName = $dir. DS . $filename;

                $file = File::instance($fullFileName);

                if ($operation == 'create') {
                    $body = $twig->processString(!empty($params['body']) ? $params['body'] : '{% include "forms/data.txt.twig" %}',
                        $vars);
                    $file->save($body);
                } elseif ($operation == 'add') {
                    if (!empty($params['body'])) {
                        // use body similar to 'create' action and append to file as a log
                        $body = $twig->processString($params['body'], $vars);

                        // create folder if it doesn't exist
                        if (!file_exists($dir)) {
                            mkdir($dir);
                        }

                        // append data to existing file
                        file_put_contents($fullFileName, $body, FILE_APPEND | LOCK_EX);
                    } else {
                        // serialize YAML out to file for easier parsing as data sets
                        $vars = $vars['form']->value()->toArray();

                        foreach ($form->fields as $field) {
                            if (isset($field['process']) && isset($field['process']['ignore']) && $field['process']['ignore']) {
                                unset($vars[$field['name']]);
                            }
                        }

                        if (file_exists($fullFileName)) {
                            $data = Yaml::parse($file->content());
                            if (count($data) > 0) {
                                array_unshift($data, $vars);
                            } else {
                                $data[] = $vars;
                            }
                        } else {
                            $data[] = $vars;
                        }

                        $file->save(Yaml::dump($data));
                    }

                }
                break;
        }
    }

    /**
     * Handle form validation error
     *
     * @param  Event $event An event object
     */
    public function onFormValidationError(Event $event)
    {
        $form = $event['form'];
        if (isset($event['message'])) {
            $form->message_color = 'red';
            $form->message = $event['message'];
        }

        $uri = $this->grav['uri'];
        $route = $uri->route();

        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $twig->twig_vars['form'] = $form;

        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $page = $pages->dispatch($route, true);

        if ($page) {
            unset($this->grav['page']);
            $this->grav['page'] = $page;
        }

        $event->stopPropagation();
    }

    /**
     * Get list of form field types specified in this plugin. Only special types needs to be listed.
     *
     * @return array
     */
    public function getFormFieldTypes()
    {
        return [
            'display' => [
                'input@' => false
            ],
            'spacer'  => [
                'input@' => false
            ],
            'captcha' => [
                'input@' => false
            ]
        ];
    }

    /**
     * Process a form
     *
     * Currently available processing tasks:
     *
     * - fillWithCurrentDateTime
     *
     * @param Form $form
     *
     * @return bool
     */
    protected function process($form)
    {
        foreach ($form->fields as $field) {
            if (isset($field['process'])) {
                if (isset($field['process']['fillWithCurrentDateTime']) && $field['process']['fillWithCurrentDateTime']) {
                    $form->setData($field['name'], gmdate('D, d M Y H:i:s', time()));
                }
            }
        }
    }

    /**
     * Create unix timestamp for storing the data into the filesystem.
     *
     * @param string $format
     * @param int    $utimestamp
     *
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
