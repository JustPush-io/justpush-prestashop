<?php
/**
 * Copyright © JustPush. MIT License.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use JustPush\PrestaShop\Config;
use JustPush\PrestaShop\LoginThrottle;
use JustPush\PrestaShop\PayloadBuilder;
use JustPush\PrestaShop\Sender;

/**
 * Sends a JSON POST to JustPush Studio when something happens in the shop. A PrestaShop recipe
 * in Studio turns each POST into a push notification. The payloads are documented in README.md.
 *
 * Hooks never throw and never send during the request: they queue, and Sender sends at
 * shutdown.
 */
class JustPush extends Module
{
    const HOOKS = [
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionOrderSlipAdd',
        'actionObjectCustomerAddAfter',
        'actionObjectCustomerMessageAddAfter',
        'actionUpdateQuantity',
        // PrestaShop 8. PrestaShop 9 logins go through Symfony, see config/admin/services.yml.
        'actionAdminLoginControllerLoginBefore',
        'actionAdminLoginControllerLoginAfter',
    ];

    /** @var bool Set when a PrestaShop 8 login succeeds, to tell failures apart at shutdown */
    private $loginSucceeded = false;

    public function __construct()
    {
        $this->name = 'justpush';
        $this->tab = 'emailing';
        $this->version = '1.0.0';
        $this->author = 'JustPush';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('JustPush notifications');
        $this->description = $this->l('Push notifications on your phone for new orders, payments, refunds, customers, messages, low stock and admin logins, through JustPush Studio.');
        $this->confirmUninstall = $this->l('Stop sending events to JustPush?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook(self::HOOKS)
            && Config::set(Config::ENABLED, 1)
            && Config::set(Config::STOCK_THRESHOLD, 0)
            && $this->rememberAdminDir();
    }

    public function uninstall()
    {
        foreach (Config::allKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * New orders from the storefront, the back office and the webservice. A cart split over
     * several carriers creates several orders, and this runs once for each.
     */
    public function hookActionValidateOrder(array $params)
    {
        $this->guard(function () use ($params) {
            if (!isset($params['order']) || !$params['order'] instanceof Order) {
                return;
            }

            $order = $params['order'];
            $state = isset($params['orderStatus']) ? $params['orderStatus'] : null;

            $this->dispatch('order.placed', (int) $order->id_shop, function () use ($order, $state) {
                return PayloadBuilder::order('order.placed', $order, $state);
            });
        });
    }

    /**
     * An order moves from an unpaid status to a paid one (Payment accepted, Remote payment
     * accepted, or any custom status marked "Consider the associated order as paid").
     */
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        $this->guard(function () use ($params) {
            $new = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
            $old = isset($params['oldOrderStatus']) ? $params['oldOrderStatus'] : null;
            if (!$new instanceof OrderState || !$new->paid) {
                return;
            }
            if ($old instanceof OrderState && Validate::isLoadedObject($old) && $old->paid) {
                return;
            }

            $order = new Order((int) $params['id_order']);
            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $this->dispatch('order.paid', (int) $order->id_shop, function () use ($order, $new) {
                return PayloadBuilder::order('order.paid', $order, $new);
            });
        });
    }

    /**
     * A credit slip is created: a partial refund, a standard refund or a return product.
     */
    public function hookActionOrderSlipAdd(array $params)
    {
        $this->guard(function () use ($params) {
            if (!isset($params['order']) || !$params['order'] instanceof Order) {
                return;
            }

            $order = $params['order'];
            $this->dispatch('order.refunded', (int) $order->id_shop, function () use ($order) {
                return PayloadBuilder::orderRefunded($order);
            });
        });
    }

    /**
     * A new customer account, from the storefront, the back office or the webservice. Guest
     * checkouts also create a customer record; those are skipped.
     */
    public function hookActionObjectCustomerAddAfter(array $params)
    {
        $this->guard(function () use ($params) {
            if (!isset($params['object']) || !$params['object'] instanceof Customer) {
                return;
            }

            $customer = $params['object'];
            if ($customer->is_guest) {
                return;
            }

            $this->dispatch('customer.registered', (int) $customer->id_shop, function () use ($customer) {
                return PayloadBuilder::customerRegistered($customer);
            });
        });
    }

    /**
     * A customer writes through the contact form, their order history or the checkout's order
     * comment. Replies by employees are skipped.
     */
    public function hookActionObjectCustomerMessageAddAfter(array $params)
    {
        $this->guard(function () use ($params) {
            if (!isset($params['object']) || !$params['object'] instanceof CustomerMessage) {
                return;
            }

            $message = $params['object'];
            if ((int) $message->id_employee !== 0 || $message->private) {
                return;
            }

            $thread = new CustomerThread((int) $message->id_customer_thread);
            $idShop = Validate::isLoadedObject($thread) ? (int) $thread->id_shop : null;

            $this->dispatch('message.received', $idShop, function () use ($message) {
                return PayloadBuilder::messageReceived($message);
            });
        });
    }

    /**
     * A product or combination drops to its low-stock threshold or below. Fires once when the
     * quantity crosses the threshold, not on every change while it stays low.
     */
    public function hookActionUpdateQuantity(array $params)
    {
        $this->guard(function () use ($params) {
            if (!isset($params['delta_quantity']) || !is_numeric($params['delta_quantity'])) {
                return; // Without the change we can't tell whether it just crossed the threshold.
            }

            $delta = (int) $params['delta_quantity'];
            if ($delta >= 0) {
                return;
            }

            $idProduct = (int) $params['id_product'];
            $idProductAttribute = isset($params['id_product_attribute']) ? (int) $params['id_product_attribute'] : 0;
            $idShop = !empty($params['id_shop']) ? (int) $params['id_shop'] : (int) Context::getContext()->shop->id;
            $quantity = (int) $params['quantity'];

            // A product with combinations also gets an update of its total; report the combination.
            if ($idProductAttribute === 0 && $this->hasCombinations($idProduct)) {
                return;
            }

            $threshold = $this->lowStockThreshold($idProduct, $idProductAttribute, $idShop);
            if ($quantity > $threshold || $quantity - $delta <= $threshold) {
                return;
            }

            $this->dispatch('stock.low', $idShop, function () use ($idProduct, $idProductAttribute, $quantity, $threshold, $idShop) {
                return PayloadBuilder::stockLow($idProduct, $idProductAttribute, $quantity, $threshold, $idShop);
            });
        });
    }

    /**
     * PrestaShop 8: a login attempt starts. Whether it failed is known at shutdown.
     */
    public function hookActionAdminLoginControllerLoginBefore(array $params)
    {
        $this->guard(function () use ($params) {
            $email = isset($params['email']) ? (string) $params['email'] : '';
            if ($email === '') {
                return;
            }

            register_shutdown_function(function () use ($email) {
                if (!$this->loginSucceeded) {
                    $this->notifyAdminLoginFailed($email);
                    Sender::flush();
                }
            });
        });
    }

    /**
     * PrestaShop 8: a login succeeded.
     */
    public function hookActionAdminLoginControllerLoginAfter(array $params)
    {
        $this->loginSucceeded = true;

        if (isset($params['employee']) && $params['employee'] instanceof Employee) {
            $this->notifyAdminLogin($params['employee']);
        }
    }

    /**
     * Sends `admin.login`. Called by the PrestaShop 8 hook and the PrestaShop 9 login subscriber.
     */
    public function notifyAdminLogin(Employee $employee)
    {
        $this->guard(function () use ($employee) {
            $this->rememberAdminDir();
            $this->dispatch('admin.login', null, function () use ($employee) {
                return PayloadBuilder::adminLogin($employee);
            });
        });
    }

    /**
     * Sends `admin.login_failed`, throttled per email address.
     *
     * @param string $email
     */
    public function notifyAdminLoginFailed($email)
    {
        $this->guard(function () use ($email) {
            $this->rememberAdminDir();
            if (!Config::isEnabled() || Config::webhookUrl('admin.login_failed') === null) {
                return;
            }

            $attempts = LoginThrottle::registerFailure($email);
            if ($attempts === null) {
                return;
            }

            $this->dispatch('admin.login_failed', null, function () use ($email, $attempts) {
                return PayloadBuilder::adminLoginFailed($email, $attempts);
            });
        });
    }

    /**
     * Modules > JustPush > Configure.
     */
    public function getContent()
    {
        $this->rememberAdminDir();
        $output = '';

        if (Tools::isSubmit('submitJustPush')) {
            $errors = [];
            foreach (array_unique(Config::EVENT_KEYS) as $key) {
                $url = trim((string) Tools::getValue($key));
                if ($url !== '' && !$this->isWebhookUrl($url)) {
                    $errors[] = sprintf($this->l('"%s" is not a valid http(s) URL.'), $url);
                    continue;
                }
                Config::set($key, $url);
            }
            Config::set(Config::ENABLED, (int) Tools::getValue(Config::ENABLED));
            Config::set(Config::STOCK_THRESHOLD, max(0, (int) Tools::getValue(Config::STOCK_THRESHOLD)));

            $output .= $errors ? $this->displayError($errors) : $this->displayConfirmation($this->l('Settings saved.'));
        }

        if (Tools::isSubmit('submitJustPushTest')) {
            $output .= $this->sendTest();
        }

        return $output . $this->renderForm();
    }

    /**
     * POSTs `test.ping` to every filled-in URL of the current shop and reports the results.
     *
     * @return string
     */
    private function sendTest()
    {
        $idShop = Shop::getContext() === Shop::CONTEXT_SHOP ? (int) Context::getContext()->shop->id : null;
        $urls = Config::allWebhookUrls($idShop);
        if (!$urls) {
            return $this->displayWarning($this->l('Fill in at least one webhook URL and save first.'));
        }

        $ok = [];
        $failed = [];
        foreach (array_unique($urls) as $url) {
            $status = Sender::send($url, PayloadBuilder::testPing($idShop));
            if ($status >= 200 && $status < 300) {
                $ok[] = $url;
            } else {
                $failed[] = $status
                    ? sprintf($this->l('%1$s answered HTTP %2$d.'), $url, $status)
                    : sprintf($this->l('%s could not be reached.'), $url);
            }
        }

        $output = '';
        if ($ok) {
            $output .= $this->displayConfirmation(sprintf($this->l('Test sent to %d URL(s). Check your phone.'), count($ok)));
        }
        if ($failed) {
            $output .= $this->displayError($failed);
        }

        return $output;
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $url = function ($key, $label, $desc) {
            return ['type' => 'text', 'name' => $key, 'label' => $label, 'desc' => $desc, 'placeholder' => 'https://…'];
        };

        $multistore = Shop::isFeatureActive();
        $form = [
            'form' => [
                'legend' => ['title' => $this->l('JustPush'), 'icon' => 'icon-bell'],
                'description' => $this->l('Install a PrestaShop recipe in JustPush Studio, copy its webhook URL and paste it into the matching field. Leave a field empty to turn that event off.'),
                'input' => [
                    [
                        'type' => 'switch',
                        'name' => Config::ENABLED,
                        'label' => $this->l('Enabled'),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    $url('JUSTPUSH_URL_ORDER_PLACED', $this->l('Order placed'), $this->l('Every new order, paid or not.')),
                    $url('JUSTPUSH_URL_ORDER_PAID', $this->l('Order paid'), $this->l('When an order gets a paid status, e.g. Payment accepted. Useful for bank wire.')),
                    $url('JUSTPUSH_URL_ORDER_REFUNDED', $this->l('Order refunded'), $this->l('When a credit slip is created (partial or standard refund, return).')),
                    $url('JUSTPUSH_URL_CUSTOMER_REGISTERED', $this->l('Customer registered'), $this->l('New customer accounts. Guest checkouts are skipped.')),
                    $url('JUSTPUSH_URL_MESSAGE_RECEIVED', $this->l('Customer message'), $this->l('Messages from the contact form, order history and checkout comments.')),
                    $url('JUSTPUSH_URL_STOCK_LOW', $this->l('Low stock'), $this->l('When a product or combination drops to its low-stock threshold.')),
                    [
                        'type' => 'text',
                        'name' => Config::STOCK_THRESHOLD,
                        'label' => $this->l('Default low-stock threshold'),
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Used for products without their own "Low stock level". 0 means: when it sells out.'),
                    ],
                    $url('JUSTPUSH_URL_ADMIN_LOGIN', $this->l('Admin login'), $multistore
                        ? $this->l('Successful and failed back-office logins. Applies to all shops.')
                        : $this->l('Successful and failed back-office logins.')),
                ],
                'submit' => ['title' => $this->l('Save'), 'name' => 'submitJustPush'],
                'buttons' => [
                    [
                        'title' => $this->l('Send test'),
                        'name' => 'submitJustPushTest',
                        'type' => 'submit',
                        'icon' => 'process-icon-envelope',
                        'class' => 'pull-right',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        foreach (Config::allKeys() as $key) {
            $helper->fields_value[$key] = Config::get($key);
        }

        return $helper->generateForm([$form]);
    }

    /**
     * Queues an event for its shop when it's enabled there. The body is only built when needed.
     *
     * @param string $event
     * @param int|null $idShop
     * @param callable $build Returns the body, or null to skip
     */
    private function dispatch($event, $idShop, callable $build)
    {
        if (!Config::isEnabled($idShop)) {
            return;
        }

        $url = Config::webhookUrl($event, $idShop);
        if ($url === null) {
            return;
        }

        $body = $build();
        if (is_array($body)) {
            Sender::queue($url, $body);
        }
    }

    /**
     * Runs a hook body without ever letting an error reach PrestaShop.
     */
    private function guard(callable $callback)
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Sender::log(sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }

    /**
     * Remembers the back-office folder name, which is needed for admin links but only known
     * inside the back office.
     *
     * @return bool
     */
    private function rememberAdminDir()
    {
        if (defined('_PS_ADMIN_DIR_')) {
            $dir = basename(_PS_ADMIN_DIR_);
            if ($dir !== '' && $dir !== Config::get(Config::ADMIN_DIR)) {
                Config::set(Config::ADMIN_DIR, $dir);
            }
        }

        return true;
    }

    /**
     * @param int $idProduct
     *
     * @return bool
     */
    private function hasCombinations($idProduct)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int) $idProduct
        );
    }

    /**
     * The product's or combination's own "Low stock level", or the module default.
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $idShop
     *
     * @return int
     */
    private function lowStockThreshold($idProduct, $idProductAttribute, $idShop)
    {
        $value = Db::getInstance()->getValue($idProductAttribute
            ? 'SELECT `low_stock_threshold` FROM `' . _DB_PREFIX_ . 'product_attribute_shop`
                WHERE `id_product_attribute` = ' . (int) $idProductAttribute . ' AND `id_shop` = ' . (int) $idShop
            : 'SELECT `low_stock_threshold` FROM `' . _DB_PREFIX_ . 'product_shop`
                WHERE `id_product` = ' . (int) $idProduct . ' AND `id_shop` = ' . (int) $idShop);

        return $value !== null && $value !== false && $value !== '' ? (int) $value : Config::stockThreshold($idShop);
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    private function isWebhookUrl($url)
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
