<?php
namespace App\Services\Billings;

use App\Exceptions\Validation;
use App\Models\PaymentSubscription;
use App\Models\Subscription;
use App\Models\Payment;
use Exception;

class CloudPayments extends BillingBase
{
    /**
     * System language
     * @param string $cultureName
     */
    public $cultureName = 'en-US';

    /**
     * Public Id from CloudPayments account
     * @param string $publicId
     */
    public $publicId = 'pk_8c97540fe7b0efdc4a5b567bea9a1'; //test

    /**
     * API Secret from CloudPayments account
     * @param string $apiSecret
     */
    public $apiSecret = '5117fa6cf8d2cb0be4f51233fc74175b'; //test

    /**
     * CloudPayments API URL
     * @param string $apiUrl
     */
    public $apiUrl = 'https://api.cloudpayments.ru';

    private $arPeriodStartPay = [
        '7_period' => '+24 hours',
        '5_period' => '+1 minutes',
        '3_period' => '+1 minutes',
        '2_period' => '+1 minutes',
        '1_period' => '+1 minutes',
    ];

    protected $description = 'Payment';

    protected array $availableCurrency = [
        'RUB'
    ];

    const BASE_CURRENCY = 'RUB';
    private string $currency = 'RUB';

    /**
     * @param string $currency
     * @throws Exception
     */
    public function setCurrency(string $currency)
    {
        if (!in_array($currency, $this->availableCurrency)) {
            throw new Exception('Передана не поддерживаемая валюта "' . $currency . '"');
        }
        $this->currency = $currency;
    }

    /**
     * @throws Exception
     */
    public function getCurrencyAmount($amount)
    {
        //TODO переводим цену к валюте по текущему курсу
        return $amount;
    }

    /**
     * @param array $arData
     * @return array|null
     * @throws Exception
     */
    public function getWidgetData(array $arData): ?array
    {
        if ($arData['invoice_id'] && isset($arData['email'])) {
            $data = [
                'publicId' => $this->publicId,
                'description' => $this->description,
                'amount' => floatval($arData['amount']),
                'currency' => $arData['currency'],
                'invoiceId' => $arData['invoice_id'],
                'success' => url("{$arData['uuid']}/success"),
                'fail' => ("{$arData['uuid']}/fail"),
                'email' => $arData['email'],
                'accountId' => $arData['email'],
            ];

            $oSubscription = Subscription::getFirst();
            $recurrent = [];
            $this->setCurrency($arData['currency']);
            $this->prepareSubscriptionData($recurrent, $oSubscription);
            $data['recurrent'] = $recurrent;

            return $data;
        }
        return null;
    }

    /**
     * @param array $data
     * @return false|mixed|null
     * @throws Validation
     * @throws Exception
     */
    public function processSubscription(array $data): mixed
    {
        $action = $data['action'];
        $sSubscriptionId = null;
        $oPaymentSubscription = null;
        $subId = null;

        if (!$action) {
            throw new Exception('Не указано действие');
        }

        if ($action === 'next') {
            if (!isset($data['subscriptionId'])) {
                throw new Exception('Не указан id подписки');
            }

            $sSubscriptionId = $data['subscriptionId'];
            $oPaymentSubscription = PaymentSubscription::getBySubscriptionId($sSubscriptionId);
            $oPayment = $oPaymentSubscription->payment;

            if (!$subId = $this->subscriptionNext($oPayment->invoice_id)) {
                $this->subscriptionsCancel($sSubscriptionId);

                $oPaymentSubscription->fill([
                    'status' => self::STATUS_CANCEL,
                ])->save();

                return false;
            }
        }
        else if ($action === 'create') {
            if (!isset($data['invoiceId'])) {
                throw new Exception('Не указан invoiceId');
            }
            $invoiceId = $data['invoiceId'];

            if ($sSubscriptionId = $this->createSubscription($invoiceId)) {
                $oPaymentSubscription = Payment::getByInvoiceId($invoiceId)->subscription;
                $oPaymentSubscription->subscriptionId = $sSubscriptionId;
                $oPaymentSubscription->save();
            }
        }

        if ($sSubscriptionId) {
            if (!$oPaymentSubscription) {
                $oPaymentSubscription = PaymentSubscription::getBySubscriptionId($sSubscriptionId);
            }

            $subscriptionResult = $this->regularStatus($sSubscriptionId);
            $subscription = $subscriptionResult['Model'];
            $data = [
                'subscriptionId' => $sSubscriptionId,
                'status' => $subscription['Status'],
                'next_payment' => strtotime($subscription['StartDateIso']),
                'last_payed' => $subscription['LastTransactionDateIso'] ? strtotime($subscription['LastTransactionDateIso']) : null,
                'mode' => $subscription['Interval'],
                'response' => serialize($subscription)
            ];

            if ($subId) {
                $data['subscription_id'] = $subId;
            }
            $oPaymentSubscription->fill($data)->save();
        }

        return $sSubscriptionId;
    }

    /**
     * @param string $invoiceId
     * @return bool
     * @throws Exception
     */
    public function subscriptionNext(string $invoiceId): bool
    {
        $oPayment = Payment::getByInvoiceId($invoiceId);
        $paymentSubscription = $oPayment->subscription;
        $oSubscription = $paymentSubscription->subscription;

        while ($oSubscription = $oSubscription->next()) {
            $paymentSubscription->subscription_id = $oSubscription->id;
            $paymentSubscription->save();

            if ($oPayment->info->token) {
                $data = [
                    'Amount' => $oSubscription->amount,
                    'Currency' => $oSubscription->currency,
                    'AccountId' => $oPayment->info->accountId,
                    'Token' => $oPayment->info->token,
                    'Description' => 'Regular payment',
                    'JsonData' => json_encode(['payment_id' => $oPayment->id, 'invoice_id' => $oPayment->invoice_id]),
                ];

                $response = $this->payCharge($data);

                $paymentSubscription->last_payed = time();
                $paymentSubscription->save();

                if ($response['Success']) {
                    $paymentSubscription->last_payed = strtotime($response['Model']['CreatedDateIso']);
                    $paymentSubscription->save();

                    return $this->subscriptionUpdate($invoiceId, true);
                }
            }
            else {
                return $this->subscriptionUpdate($invoiceId);
            }
            sleep(5);
        }

        return false;
    }

    /**
     * @param string $invoiceId
     * @param bool $isLastTime
     * @return bool
     * @throws Validation
     */
    public function subscriptionUpdate(string $invoiceId, bool $isLastTime = false): bool
    {
        $oPayment = Payment::getByInvoiceId($invoiceId);
        $paymentSubscription = $oPayment->subscription;
        $oSubscription = $paymentSubscription->subscription;

        if (!$oSubscription) {
            return false;
        }

        $subscriptionData = [
            'Id' => $paymentSubscription->subscriptionId,
            'Description' => $oSubscription->code
        ];
        $this->prepareSubscriptionData($subscriptionData, $oSubscription, $isLastTime);
        $response = $this->regularUpdate($subscriptionData);

        return $response['Success'] ? $oSubscription->id : false;
    }

    /**
     * @param array $data
     * @param Subscription $oSubscription
     * @param bool $isLastTime
     * @throws Exception
     */
    private function prepareSubscriptionData(array &$data, Subscription $oSubscription, bool $isLastTime = false)
    {
        $modifyTime = $this->arPeriodStartPay[$oSubscription->code] ?? '+24 hours';

        if ($isLastTime) {
            $mTime = 'days';

            if ($oSubscription->interval === 'Month') {
                $mTime = 'months';
            }
            else if ($oSubscription->interval === 'Week') {
                $mTime = 'weeks';
            }

            $modifyTime = '+ '.$oSubscription->period.' '.$mTime;
        }

        $datetime = new \DateTime();
        $datetime->modify($modifyTime);
        $datetime->setTimezone(new \DateTimeZone("UTC"));

        $data['Amount'] = $this->getCurrencyAmount($oSubscription->amount);
        $data['Currency'] = $this->currency;
        $data['Period'] = $oSubscription->period;
        $data['Interval'] = $oSubscription->interval;
        $data['StartDate'] = $datetime->format(\DateTime::ATOM);
    }

    /**
     * @param string $invoiceId
     * @return false|mixed
     * @throws Validation
     */
    public function createSubscription(string $invoiceId): mixed
    {
        $oSubscription = Subscription::getFirst();

        if (!$oSubscription && !$invoiceId) {
            return false;
        }

        $arPayment = $this->paymentsFind($invoiceId);

        if (!$arPayment['Success']) {
            return false;
        }

        $arPayment = $arPayment['Model'];

        $data = [
            'Token' => $arPayment['Token'],
            'AccountId' => $arPayment['AccountId'],
            'Description' => $oSubscription->code,
            'Email' => $arPayment['Email'],
            'RequireConfirmation' => 'false',
        ];
        $this->prepareSubscriptionData($data, $oSubscription);

        $result = $this->regularCreate($data);

        if ($result['Success']) {
            return $result['Model']['Id'];
        }

        return false;
    }

    /**
     * create request to the CloudPayments API
     * @param array $array
     * @return array
     */
    private function request(array $array): array
    {
        $array['data']['CultureName'] = $this->cultureName;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$this->apiUrl}{$array['url']}");
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, "{$this->publicId}:{$this->apiSecret}");
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($array['data']));
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded'
        ));
        $out = curl_exec($curl);
        curl_close($curl);

        return json_decode($out, true);
    }

    /**
     * Get payment info by payment invoice id
     * @param string $invoiceId
     * @return array
     * @throws Validation
     */
    public function paymentsFind(string $invoiceId): array
    {
        if (empty($invoiceId)) {
            throw new Validation(['InvoiceId']);
        }

        return $this->request([
            'url' => '/payments/find',
            'data' => ['InvoiceId' => $invoiceId],
        ]);
    }

    /**
     * Create subscription
     * @param array $array
     * @return array
     * @throws Validation
     */
    public function regularCreate(array $array): array
    {
        $data = $this->validateData(
            $array,
            [
                'Token',
                'AccountId',
                'Description',
                'Email',
                'Amount',
                'Currency',
                'RequireConfirmation',
                'StartDate',
                'Interval',
                'Period'
            ]
        );

        return $this->request([
            'url' => '/subscriptions/create',
            'data' => $data,
        ]);
    }

    /**
     * Update subscription
     * @param array $array
     * @return array
     * @throws Validation
     */
    public function regularUpdate(array $array): array
    {
        $data = $this->validateData($array, ['Id']);

        return $this->request([
            'url' => '/subscriptions/update',
            'data' => $data,
        ]);
    }

    /**
     * Cancel subscription
     * @param string $id
     * @return array
     * @throws Validation
     */
    public function subscriptionsCancel(string $id): array
    {
        if (empty($id)) {
            throw new Validation(['Id']);
        }

        return $this->request([
            'url' => '/subscriptions/cancel',
            'data' => ['Id' => $id],
        ]);
    }

    /**
     * Get subscription info
     * @param string $id
     * @return array
     * @throws Validation
     */
    public function regularStatus(string $id): array
    {
        if (empty($id)) {
            throw new Validation(['Id']);
        }

        return $this->request([
            'url' => '/subscriptions/get',
            'data' => ['Id' => $id],
        ]);
    }

    /**
     * @throws Validation
     */
    public function payCharge(array $array): array
    {
        $data = $this->validateData($array, ['Amount', 'Currency', 'AccountId', 'Token']);

        return $this->request([
            'url' => '/payments/tokens/charge',
            'data' => $data,
        ]);
    }

    /**
     * Check if array contains required values
     * @param array $array
     * @param array $rules
     * @return array
     * @throws Validation
     */
    private function validateData(array $array, array $rules): array
    {
        $arrayDiff = array_diff($rules, array_keys($array));

        if (count($arrayDiff) > 0) {
            throw new Validation($arrayDiff);
        }

        return $array;
    }

    /**
     * @throws Exception
     */
    public function validate(array $data)
    {
        $arParams = [
            'amount',
            'currency',
            'description',
        ];

        foreach ($arParams as $sParam) {
            if (empty($data[$sParam])) {
                throw new Exception('Не передан обязательный параметр "' . $sParam . '"');
            }
        }

        if (!in_array($data['currency'], $this->availableCurrency)) {
            throw new Exception('Передана не поддерживаемая валюта "' . $data['currency'] . '"');
        }
    }
}
