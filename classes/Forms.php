<?php
namespace Grav\Plugin\Form;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Framework\Form\Interfaces\FormFactoryInterface;
use Grav\Framework\Form\Interfaces\FormInterface;

class Forms
{
    /** @var array|FormFactoryInterface[] */
    private $types;
    /** @var FormInterface|null */
    private $form;

    public function __construct()
    {
        $this->registerType('form', new FormFactory());
    }

    public function registerType(string $type, FormFactoryInterface $factory): void
    {
        $this->types[$type] = $factory;
    }

    public function unregisterType($type): void
    {
        unset($this->types[$type]);
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function getTypes(): array
    {
        return array_keys($this->types);
    }

    public function createPageForm(PageInterface $page, string $name = null, array $form = null): ?FormInterface
    {
        if (null === $form) {
            [$name, $form] = $this->getPageParameters($page, $name);
        }

        if (null === $form) {
            return null;
        }

        $type = $form['type'] ?? 'form';
        $factory = $this->types[$type] ?? null;

        if ($factory) {
            if (method_exists($factory, 'createFormForPage')) {
                return $factory->createFormForPage($page, $name, $form);
            }

            if ($page instanceof Page) {
                return $factory->createPageForm($page, $name, $form);
            }
        }

        return null;
    }

    public function getActiveForm(): ?FormInterface
    {
        return $this->form;
    }

    public function setActiveForm(FormInterface $form): void
    {
        $this->form = $form;
    }

    protected function getPageParameters(PageInterface $page, ?string $name): array
    {
        $forms = $page->forms();

        if ($name) {
            // If form with given name was found, use that.
            $form = $forms[$name] ?? null;
        } else {
            // Otherwise pick up the first form.
            $form = reset($forms) ?: null;
            $name = (string)key($forms);
        }

        return [$name, $form];
    }
}
