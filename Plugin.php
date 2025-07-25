<?php namespace Lovata\BaseCode;

use Event;
use Lovata\BaseCode\Classes\Console\ClearAuthOneC;
use System\Classes\PluginBase;

//Console commands
use Lovata\BaseCode\Classes\Console\ResetAdminPassword;

// Events
// Order position
use Lovata\BaseCode\Classes\Event\OrderPosition\OrderPositionModelHandler;
use Lovata\BaseCode\Classes\Event\OrderPosition\ExtendOrderPositionFieldsHandler;

// Order
use Lovata\BaseCode\Classes\Event\Order\ExtendOrderFieldsHandler;
use Lovata\BaseCode\Classes\Event\Order\OrderModelHandler;

/**
 * Class Plugin
 *
 * @package Lovata\BaseCode
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class Plugin extends PluginBase
{
    /**
     * Register plugin components
     *
     * @return array
     */
    public function registerComponents()
    {
        return [
            'Lovata\BaseCode\Components\SiteSettings' => 'SiteSettings',
        ];
    }

    /**
     * Register settings
     * @return array
     */
    public function registerSettings()
    {
        return [
            'config' => [
                'label' => 'lovata.basecode::lang.menu.settings',
                'description' => '',
                'icon' => 'icon-cogs',
                'class' => 'Lovata\BaseCode\Models\Settings',
                'permissions' => ['lovata-site-settings'],
                'order' => 100,
            ],
        ];
    }

    /**
     * Plugin boot method
     */
    public function boot()
    {
        $this->addEventListener();
    }

    /**
     * Add listener
     */
    protected function addEventListener()
    {
        //Order events
        Event::subscribe(ExtendOrderFieldsHandler::class);
        Event::subscribe(OrderModelHandler::class);
        //OrderPosition events
        Event::subscribe(OrderPositionModelHandler::class);
        Event::subscribe(ExtendOrderPositionFieldsHandler::class);
    }

    public function register()
    {
        $this->registerConsoleCommand('basecode:reset_admin_password', ResetAdminPassword::class);
        $this->registerConsoleCommand('basecode:1c.clear_auth', ClearAuthOneC::class);
    }

    /**
     * @return array
     */
    public function registerMailTemplates()
    {
        return [];
    }
}
