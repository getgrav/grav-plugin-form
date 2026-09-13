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

# How to use the Form Plugin

The Learn site has two pages describing how to use the Form Plugin:
- [Forms](https://learn.getgrav.org/17/forms)
- [Add a contact form](https://learn.getgrav.org/17/forms/forms/example-form)

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
