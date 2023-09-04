<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RefundController extends AdminBaseController
{
    protected String $table = 'refunds';
    protected $searchFields = [
        'fio',
        'letters_first',
        'letters_last',
        'date_transaction',
        'email',
        'reason',
        'created_at',
        'status'
    ];
    protected $allowStatuses = [
        'active',
        'completed',
    ];

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required',
        ]);

        if ($validator->fails() || !in_array($request->status, $this->allowStatuses)) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $refund = Refund::find($id);
        $refund->status = $request->status;
        $refund->save();

        return $this->getData($request);
    }

    protected function getTotal(): array
    {
        $total = Refund::count();
        $countActive = Refund::where('status', '=', 'active')->count();
        $countClose = $total - $countActive;

        return [
            'total' => $total,
            'open' => $countActive,
            'close' => $countClose,
        ];
    }
}
