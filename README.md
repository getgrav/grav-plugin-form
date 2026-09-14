# Grav Form Plugin

The **form plugin** for [Grav](https://github.com/getgrav/grav) adds the ability to create and use forms.  This is currently used extensively by the **admin** and **login** plugins.

# Installation

The form plugin is easy to install with GPM.

```
$ bin/gpm install form
```

# Configuration

Simply copy the `user/plugins/form/form.yaml` into `user/config/plugins/form.yaml` and make your modifications.

```
enabled: true
```  

# reCAPTCHA v3 score threshold

For reCAPTCHA v3, set **v3 score threshold** in the Form plugin's Admin settings (under reCAPTCHA), or configure it in `user/config/plugins/form.yaml`:

```yaml
recaptcha:
  score_threshold: 0.5
```

This example preserves the default threshold of `0.5`. Add the setting alongside your existing reCAPTCHA configuration; keep your version, site key, and secret key settings. The threshold applies to reCAPTCHA v3 validation across forms using this plugin configuration. It is not a per-form process parameter.

# How to use the Form Plugin

The Learn site has two pages describing how to use the Form Plugin:
- [Forms](https://learn.getgrav.org/17/forms)
- [Add a contact form](https://learn.getgrav.org/17/forms/forms/example-form)

# Default saved text

The `save` action uses `forms/data.save.txt.twig` when creating a text file without
an explicit `body`. This template preserves literal submitted text, including
ampersands, quotes and markup. It is intended for plain-text file output. Do not use it
in HTML emails, or for saved files that are later rendered as HTML or Markdown.

The existing `forms/data.txt.twig` template remains escaped because it is also
used in HTML email bodies. An explicit save `body` keeps using the template or
format you specify.

If your form already sets an explicit body on its `save` action, such as
`body: "{% include 'forms/data.txt.twig' %}"`, it will keep the old escaped output.
That line was only restating the previous default, so you can either delete it and
get the literal template, or point it at `forms/data.save.txt.twig`. Leave the body
on any `email` action alone: email is rendered as HTML and needs the escaped
template. Themes or plugins that customized the old template for saved
files can override `forms/data.save.txt.twig` for the new default; their existing
`forms/data.txt.twig` overrides continue to apply to email includes.

# Custom processing actions

Plugins can handle submitted values through their own process action. Add the
action under `process` in your existing form definition:

```yaml
process:
  myplugin-handle-order: true
```

In your plugin class, add an `onFormProcessed` subscription to the array returned
by `getSubscribedEvents()`:

```php
'onFormProcessed' => ['onFormProcessed', 0],
```

Import `RocketTheme\Toolbox\Event\Event` and handle your action:

```php
public function onFormProcessed(Event $event): void
{
    if ($event['action'] !== 'myplugin-handle-order') {
        return;
    }

    $form = $event['form'];
    $data = $form->getData();

    // Your order-processing code can use $data here.
}
```

`onFormProcessed` runs once per configured action, so check `action` to avoid
running your logic for other entries. The event also provides `params`, containing
the action's configuration (`true` in this example, or a mapping of options).
Setting an action to `false` disables it. With no process entries, this event is
not dispatched. Your custom action can be the only entry; a built-in action such
as `message`, `email`, or `save` is not required.

# Using email

Note: when using email functionality in your forms, make sure you have configured the Email plugin correctly. In particular, make sure you configured the "Email from" and "Email to" email addresses in the Email plugin with your email address.

# NOTES:

As of version **Form 6.0.0** forms are no longer initialized before caching, but when the form is requested. This has been done to make dynamic forms to work better with caching. There may be some backward compatibility issues for logic that modifies pages with forms as the modification doesn't happen without accessing the form first.

As of version **Form 5.0.0** Grav 1.7+ is required.

As of version **Form 4.0.6**, form labels are now being output with the `|raw` filter.  If you wish to show HTML in your form label, ie `Root Folder <root>`, then you need to escape that in your form definition:

```yaml
label: Root Folder &lt;root&gt;
```
