<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Billing;
use Illuminate\Support\Facades\View;
use Symfony\Component\Intl\Currencies;

class PaymentController extends Controller
{
    /**
     * @throws \Exception
     */
    public function invoice(Request $request, $uuid)
    {
        $oPayment = Payment::getByUuid($uuid);

        $request->validate([
            'email' => 'required|email',
        ]);

        $data = $request->all();
        $data['id'] = $oPayment->id;

        $billing = (new Billing())->getById($oPayment->billing_id);

        return call_user_func(
            [new $billing['class']($billing['id']), 'createInvoice'],
            $data
        );
    }

    /**
     * @throws \Exception
     */
    public function get($uuid)
    {
        $oPayment = Payment::where('uuid', '=', $uuid)->firstOrFail();

        if ($oPayment->status !== Payment::STATUS_NEW && $oPayment->status !== Payment::STATUS_EXPIRED) {
            return redirect()->action(
                [PaymentController::class, 'success'], ['id' => $oPayment->id]
            );
        }

        $timer = strtotime($oPayment->expiration_date) - time();
        $billing = (new Billing())->getById($oPayment->billing_id);

        $arResult = call_user_func(
            [new $billing['class']($billing['id']), 'getPayment'],
            $oPayment->id
        );

        $arResult['payment']['symbol'] = Currencies::getSymbol($arResult['payment']['currency']);

        return View::make('welcome', ['timer' => $timer, 'data' => json_encode($arResult)]);
    }

    /**
     * @throws \Exception
     */
    public function create(Request $request)
    {
        $request->validate([
            'amount' => ['required', 'numeric'],
            'description' => ['required', 'string'],
            'currency' => ['required', 'string'],
            'billing' => ['required', 'string'],
            'email' => 'sometimes|email',
        ]);

        $data = $request->all();
        $data['partnerId'] = $data['partnerId'] ?? null;

        try {

            if (empty($data['billing'])) {
                throw new \Exception('Не передан параметр "billing"');
            }

            // Проверяем, существует ли биллинг
            $billing = (new Billing())->getByName($data['billing']);

            $arResult = call_user_func(
                [new $billing['class']($billing['id']), 'getData'],
                $data
            );

        } catch (\Exception $obEx) {
            throw new \Exception($obEx->getMessage());
        }

        return $arResult;
    }

    public function success($uuid): \Illuminate\Contracts\View\View
    {
        $oPayment = Payment::where('uuid', '=', $uuid)
        ->where('status', '!=', 'new')
        ->where('status', '!=', 'expired')
        ->with('info:id,payment_id,transactionId')
        ->firstOrFail();

        $billing = (new Billing())->getById($oPayment->billing_id);

        $arResult = call_user_func(
            [new $billing['class']($billing['id']), 'getPayment'],
            $oPayment->id
        );

        return View::make('success', ['data' => json_encode($arResult)]);
    }

    /**
     * @throws \Exception
     */
    public function fail($uuid): \Illuminate\Contracts\View\View
    {
        $oPayment = Payment::where('uuid', '=', $uuid)
        ->where('status', '=', 'new')
        ->firstOrFail();

        $billing = (new Billing())->getById($oPayment->billing_id);

        $arResult = call_user_func(
            [new $billing['class']($billing['id']), 'getPayment'],
            $oPayment->id
        );

        return View::make('fail', ['data' => json_encode($arResult)]);
    }

    public function reset($uuid): JsonResponse
    {
        $payment = Payment::where('uuid', '=', $uuid)->firstOrFail();
        $payment->resetTimer();
        $redirectUrl = url('/' . $uuid);

        return response()->json([
            'redirectUrl' => $redirectUrl,
        ]);
    }
}
