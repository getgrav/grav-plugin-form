<?php declare(strict_types=1);

namespace Grav\Plugin\Form;

use Grav\Framework\Form\Interfaces\FormInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use function is_string;

/**
 * Class GravExtension
 * @package Grav\Common\Twig\Extension
 */
class TwigExtension extends AbstractExtension
{
    public function getFilters()
    {
       return [
            new TwigFilter('value_and_label', [$this, 'valueAndLabel']),
            new TwigFilter('form_attributes', [$this, 'formAttributes']),
       ];
    }

    /**
     * Return a list of all functions.
     *
     * @return array
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('prepare_form_fields', [$this, 'prepareFormFields'], ['needs_context' => true]),
            new TwigFunction('prepare_form_field', [$this, 'prepareFormField'], ['needs_context' => true]),
            new TwigFunction('include_form_field', [$this, 'includeFormField']),
        ];
    }

    public function valueAndLabel($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $key => $label) {
            $list[] = ['value' => $key, 'label' => $label];
        }

        return $list;
    }

    /**
     * Turns an `attributes` (or `datasets`) map from a form definition into a list
     * of `{name, value}` pairs, leaving out any name that is not a plain attribute
     * name. Accepts both the `key: value` style and the `{name: ..., value: ...}`
     * list style. Values are left as they are; templates still escape them.
     *
     * @param mixed $attributes
     * @param string $prefix Prepended to every name, e.g. `data-` for datasets
     * @return array<int, array{name: string, value: mixed}>
     */
    public function formAttributes($attributes, string $prefix = ''): array
    {
        if (!is_iterable($attributes)) {
            return [];
        }

        $list = [];
        foreach ($attributes as $key => $attribute) {
            if (is_array($attribute)) {
                $name = $attribute['name'] ?? null;
                $value = $attribute['value'] ?? '';
            } else {
                $name = $key;
                $value = $attribute;
            }
            if (!is_string($name) && !is_int($name)) {
                continue;
            }

            $name = $prefix . $name;
            if (!static::isSafeAttributeName($name)) {
                continue;
            }

            $list[] = ['name' => $name, 'value' => $value];
        }

        return $list;
    }

    /**
     * An attribute name is letter-led and made of letters, digits, `-`, `_`, `:`
     * and `.` only. Event handlers (`on*`) and names that open a script context
     * whatever their value (`formaction`, `srcdoc`, `xmlns`) are refused.
     *
     * @param string $name
     * @return bool
     */
    public static function isSafeAttributeName(string $name): bool
    {
        // `D` keeps `$` from matching before a trailing newline.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_:.\-]*$/D', $name)) {
            return false;
        }

        $lower = strtolower($name);
        if (strpos($lower, 'on') === 0) {
            return false;
        }

        return !in_array($lower, ['formaction', 'srcdoc', 'xmlns'], true);
    }

    /**
     * Filters form fields for the current parent.
     *
     * @param array $context
     * @param array $fields Form fields
     * @param string|null $parent Parent field name if available
     * @return array
     */
    public function prepareFormFields(array $context, $fields, $parent = null): array
    {
        $list = [];

        if (is_iterable($fields)) {
            foreach ($fields as $name => $field) {
                $field = $this->prepareFormField($context, $field, $name, $parent);
                if ($field) {
                    $list[$field['name']] = $field;
                }
            }
        }

        return $list;
    }

    /**
     * Filters field name by changing dot notation into array notation.
     *
     * @param array $context
     * @param array $field Form field
     * @param string|int|null $name Field name (defaults to field.name)
     * @param string|null $parent Parent field name if available
     * @param array|null $options List of options to override
     * @return array|null
     */
    public function prepareFormField(array $context, $field, $name = null, $parent = null, array $options = []): ?array
    {
        // Make sure that the field is a valid form field type and is not being ignored.
        if (empty($field['type']) || ($field['validate']['ignore'] ?? false)) {
            return null;
        }

        // If field has already been prepared, we do not need to do anything.
        if (!empty($field['prepared'])) {
            return $field;
        }

        // Check if we have just a list of fields (no name given).
        $fieldName = (string)($field['name'] ?? $name);
        if (!is_string($name) || $name === '') {
            // Look at the field.name and if not set, fall back to the key.
            $name = $fieldName;
        }

        // Make sure that the field has a name.
        if ($name === '') {
            return null;
        }

        // Prefix name with the parent name if needed.
        if (str_starts_with($name, '.')) {
            $plainName = (string)substr($name, 1);
            $field['plain_name'] = $plainName;
            $name = $parent ? $parent . $name : $plainName;
        } elseif (isset($options['key'])) {
            $name = str_replace('*', $options['key'], $name);
        }

        unset($options['key']);

        // Set fields as readonly if form is in readonly mode.
        /** @var FormInterface $form */
        $form = $context['form'] ?? null;
        if ($form && method_exists($form, 'isEnabled') && !$form->isEnabled()) {
            $options['disabled'] = true;
        }

        // Loop through options
        foreach ($options as $key => $option) {
            $field[$key] = $option;
        }

        // Always set field name.
        $field['name'] = $name;
        $field['prepared'] = true;

        return $field;
    }

    /**
     * @param string $type
     * @param string|string[]|null $layouts
     * @param string|null $default
     * @return string[]
     */
    public function includeFormField(string $type, $layouts = null, ?string $default = null): array
    {
        $list = [];
        foreach ((array)$layouts as $layout) {
            $list[] = "forms/fields/{$type}/{$layout}-{$type}.html.twig";
        }
        $list[] = "forms/fields/{$type}/{$type}.html.twig";

        if ($default) {
            foreach ((array)$layouts as $layout) {
                $list[] = "forms/fields/{$default}/{$layout}-{$default}.html.twig";
            }
            $list[] = "forms/fields/{$default}/{$default}.html.twig";
        }

        return $list;
    }
}
