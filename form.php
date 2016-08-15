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

    protected $forms = [];

    protected $forms_flat = [];

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized'   => ['onPluginsInitialized', 0],
            'onPageInitialized'      => ['onPageInitialized', 0],
            'onPageContentProcessed' => ['onPageContentProcessed', 0],
            'onPageContentFinished'  => ['onPageContentFinished', 0],
            'onTwigTemplatePaths'    => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables'    => ['onTwigSiteVariables', 0],
            'onFormFieldTypes'       => ['onFormFieldTypes', 0]
        ];
    }

    public function onPluginsInitialized()
    {
        require_once(__DIR__ . '/classes/form.php');
        require_once(__DIR__ . '/classes/form_serializable.php');
        require_once(__DIR__ . '/classes/forms.php');
    }

    /**
     * Process forms after Grav's processing, but before caching
     *
     * @param Event $e
     */
    public function onPageContentProcessed(Event $e)
    {
        /** @var Page $page */
        $page = $e['page'];

        $header = $page->header();
        if ((isset($header->forms) && is_array($header->forms)) ||
            (isset($header->form) && is_array($header->form))) {

            // Create forms from page
            $forms = new Forms($page);
            $meta = $forms->getForms();

            // If this page contains forms
            if (count($meta) > 0) {
                $page->addContentMeta('formMeta', $meta);
            }
        }
    }

    public function onPageContentFinished(Event $e)
    {
        /** @var Page $page */
        $page = $e['page'];

        if (!array_key_exists($page->route(), $this->forms)) {
            $forms = $page->getContentMeta('formMeta');

            if ($forms) {
                $this->forms[$page->route()] = $forms;
            }
        }


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

        if ($this->forms) {
            $this->active = true;

            // flatten arrays to make stuff easier
            $this->forms_flat = Utils::arrayFlatten($this->forms);

            $this->enable([
                'onFormProcessed'       => ['onFormProcessed', 0],
                'onFormValidationError' => ['onFormValidationError', 0]
            ]);

            // Handle posting if needed.
            if (!empty($_POST)) {

                $form_name = filter_input(INPUT_POST, '__form-name__');

                if (array_key_exists($form_name, $this->forms_flat)) {
                    $form = $this->forms_flat[$form_name];
                    $form->post();
                }
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

        // set all the forms in the twig vars
        $this->grav['twig']->twig_vars['forms'] = $this->forms_flat;

        // get first item for Twig 'form' variable
        reset($this->forms_flat);
        $key = key($this->forms_flat);
        $form = $key ? $this->forms_flat[$key] : null;
        $this->grav['twig']->twig_vars['form'] = $form;

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
                if (isset($params['recaptcha_secret'])) {
                    $recaptchaSecret = $params['recaptcha_secret'];
                } else if (isset($params['recatpcha_secret'])) {
                    // Included for backwards compatibility with typo (issue #51)
                    $recaptchaSecret = $params['recatpcha_secret'];
                } else {
                    $recaptchaSecret = $this->config->get('plugins.form.recaptcha.secret_key');
                }

                // Validate the captcha
                $query = http_build_query([
                    'secret'   => $recaptchaSecret,
                    'response' => $form->value('g-recaptcha-response', true)
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
                $blueprint = $form->value()->blueprints();
                $blueprint->set('form/fields/ip', ['name'=>'ip', 'label'=> $label]);
                $form->setFields($blueprint->fields());
                $form->setData('ip', Uri::ip());
                break;
            case 'message':
                $translated_string = $this->grav['language']->translate($params);
                $vars = array(
                    'form' => $form
                );

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $processed_string = $twig->processString($translated_string, $vars);

                $form->message = $processed_string;
                break;
            case 'redirect':
                $sform = new FormSerializable();
                $sform->message = $form->message;
                $sform->message_color = $form->message_color;
                $sform->fields = $form->fields;
                $sform->data = $form->value();
                $this->grav['session']->setFlashObject('form', $sform);
                $this->grav->redirect((string)$params);
                break;
            case 'reset':
                if (Utils::isPositive($params)) {
                    $form->reset();
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
                    'form' => $form
                ];

                $locator = $this->grav['locator'];
                $path = $locator->findResource('user://data', true);
                $dir = $path . DS . $form->name();
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
