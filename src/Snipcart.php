<?php
/**
 * Snipcart plugin for Craft CMS 3.x
 *
 * @link      https://workingconcept.com
 * @copyright Copyright (c) 2018 Working Concept Inc.
 */

namespace workingconcept\snipcart;

use workingconcept\snipcart\providers\ShipStation;
use workingconcept\snipcart\services\Api;
use workingconcept\snipcart\services\Carts;
use workingconcept\snipcart\services\Customers;
use workingconcept\snipcart\services\Data;
use workingconcept\snipcart\services\DigitalGoods;
use workingconcept\snipcart\services\Discounts;
use workingconcept\snipcart\services\Orders;
use workingconcept\snipcart\services\Products;
use workingconcept\snipcart\services\Shipments;
use workingconcept\snipcart\services\Subscriptions;
use workingconcept\snipcart\variables\SnipcartVariable;
use workingconcept\snipcart\widgets\Orders as OrdersWidget;
use workingconcept\snipcart\models\Settings;
use workingconcept\snipcart\fields\ProductDetails;
use workingconcept\snipcart\assetbundles\PluginSettingsAsset;
use workingconcept\snipcart\events\RegisterShippingProvidersEvent;
use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use craft\console\Application as ConsoleApplication;
use craft\services\Dashboard;
use yii\base\Event;

/**
 * Class Snipcart
 *
 * @author    Working Concept
 * @package   Snipcart
 * @since     1.0.0
 *
 * @property  Api           $api
 * @property  Carts         $carts
 * @property  Customers     $customers
 * @property  Data          $data
 * @property  Discounts     $discounts
 * @property  Orders        $orders
 * @property  Products      $products
 * @property  Shipments     $shipments
 * @property  Subscriptions $subscriptions
 */
class Snipcart extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var Snipcart
     */
    public static $plugin;

    // Constants
    // =========================================================================

    /**
     * @event ShippingProviderEvent
     */
    const EVENT_REGISTER_SHIPPING_PROVIDERS = 'registerShippingProviders';


    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '1.0.6';


    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'api'           => Api::class,
            'carts'         => Carts::class,
            'customers'     => Customers::class,
            'data'          => Data::class,
            'digitalGoods'  => DigitalGoods::class,
            'discounts'     => Discounts::class,
            'orders'        => Orders::class,
            'products'      => Products::class,
            'shipments'     => Shipments::class,
            'subscriptions' => Subscriptions::class,
        ]);

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event)
            {
                $variable = $event->sender;
                $variable->set('snipcart', SnipcartVariable::class);
            }
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event)
            {
                $event->types[] = OrdersWidget::class;
            }
        );

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event)
            {
                $event->types[] = ProductDetails::class;
            }
        );

        if (Craft::$app->getRequest()->isCpRequest)
        {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                function(RegisterUrlRulesEvent $event)
                {
                    $event->rules = array_merge($event->rules, $this->_cpRoutes());
                }
            );
        }

        if (Craft::$app instanceof ConsoleApplication)
        {
            $this->controllerNamespace = 'workingconcept\snipcart\console\controllers';
        }

        $this->_registerShippingProviders();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse()
    {
        Craft::$app->getView()->registerAssetBundle(
            PluginSettingsAsset::class
        );

        return \Craft::$app->controller->renderTemplate(
            'snipcart/_settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'snipcart/_settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Define control panel routes.
     * @return array
     */
    private function _cpRoutes(): array
    {
        return [
            'snipcart' => 'snipcart/overview/index',
            'snipcart/orders' => 'snipcart/orders/index',
            'snipcart/order/<orderId>' => 'snipcart/orders/order-detail',
            'snipcart/customers' => 'snipcart/customers/index',
            'snipcart/customer/<customerId>' => 'snipcart/customers/customer-detail',
            'snipcart/discounts' => 'snipcart/discounts/index',
            'snipcart/discounts/new' => 'snipcart/discounts/new',
            'snipcart/discount/<discountId>' => 'snipcart/discounts/discount-detail',
            'snipcart/abandoned' => 'snipcart/carts/index',
            'snipcart/abandoned/<cartId>' => 'snipcart/carts/detail',
            'snipcart/subscriptions' => 'snipcart/subscriptions/index',
            'snipcart/subscription/<subscriptionId>' => 'snipcart/subscriptions/detail',
        ];
    }

    /**
     * Instantiate Shipping providers and make each available in an indexed array.
     */
    private function _registerShippingProviders()
    {
        $shippingProviders = [
            ShipStation::class
        ];

        if ($this->hasEventHandlers(self::EVENT_REGISTER_SHIPPING_PROVIDERS))
        {
            $event = new RegisterShippingProvidersEvent([
                'shippingProviders' => $shippingProviders,
            ]);

            $this->trigger(self::EVENT_REGISTER_SHIPPING_PROVIDERS, $event);

            $shippingProviders = $event->shippingProviders;
        }

        $pluginSettings = $this->getSettings();

        foreach ($shippingProviders as $shippingProviderClass)
        {
            $instance = new $shippingProviderClass();

            $pluginSettings->providers[$instance->refHandle()] = $instance;
        }
    }

}
