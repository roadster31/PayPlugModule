<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayPlugModule;

use PayPlugModule\EventListener\FormExtend\OrderFormListener;
use PayPlugModule\Model\PayPlugConfigValue;
use PayPlugModule\Service\PaymentService;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Install\Database;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Tools\URL;

class PayPlugModule extends AbstractPaymentModule
{
    /** @var string */
    const DOMAIN_NAME = 'payplugmodule';


    public function postActivation(ConnectionInterface $con = null)
    {
        if (!$this->getConfigValue('is_initialized', false)) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__ . "/Config/thelia.sql"]);
        }
    }

    public function isValidPayment()
    {
        /** @var PaymentService $paymentService */
        $paymentService = $this->container->get('payplugmodule_payment_service');
        return $paymentService->isPayPlugAvailable();
    }

    /**
     * @inheritDoc
     */
    public function pay(Order $order)
    {
        try {
            /** @var PaymentService $paymentService */
            $paymentService = $this->container->get('payplugmodule_payment_service');

            $slice = 1;

            $isMultiPayment = $this->getRequest()->getSession()->get(OrderFormListener::PAY_PLUG_MULTI_PAYMENT_FIELD_NAME, 0);
            if ($isMultiPayment) {
                $orderTotalAmount = $order->getTotalAmount();

                $minAmount = PayPlugModule::getConfigValue(PayPlugConfigValue::MULTI_PAYMENT_MINIMUM);
                $maxAmount = PayPlugModule::getConfigValue(PayPlugConfigValue::MULTI_PAYMENT_MAXIMUM);

                if ($minAmount <= $orderTotalAmount && $maxAmount >= $orderTotalAmount) {
                    $slice = PayPlugModule::getConfigValue(PayPlugConfigValue::MULTI_PAYMENT_TIMES);
                }
            }

            $payment = $paymentService->sendOrderPayment(
                $order,
                PayPlugModule::getConfigValue(PayPlugConfigValue::DIFFERED_PAYMENT_ENABLED, false),
                PayPlugModule::getConfigValue(PayPlugConfigValue::ONE_CLICK_PAYMENT_ENABLED, false),
                $slice
            );

            $forceRedirect = false;
            if (true === $payment['isPaid']) {
                $forceRedirect = true;
                $payment['url'] = URL::getInstance()->absoluteUrl('/order/placed/'.$order->getId());
            }

            if ($this->getRequest()->isXmlHttpRequest()) {
                return new JsonResponse(
                    [
                        'paymentUrl' => $payment['url'],
                        'forceRedirect' => $forceRedirect
                    ]
                );
            }
        } catch (\Exception $exception) {
            if ($this->getRequest()->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $exception->getMessage()], 400);
            }
            throw $exception;
        }

        return new RedirectResponse($payment['url']);
    }
}
