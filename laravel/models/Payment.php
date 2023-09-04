<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory;

    const STATUS_NEW = 'new';
    const STATUS_EXPIRED = 'expired';
    const STATUS_SUBSCRIPTION = 'subscription';
    const STATUS_UNSUBSCRIBE = 'unsubscribe';
    const STATUS_SUCCESS = 'success';

    protected $fillable = array(
        'amount', 'currency', 'invoice_id', 'description', 'billing_id', 'status', 'expiration_date',
        'uuid', 'partnerId',
    );

    protected $dates = ['expiration_date'];

    public function info(): HasOne
    {
        return $this->hasOne('App\Models\PaymentInfo');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany('App\Models\PaymentSubscription');
    }

    public function billing(): HasOne
    {
        return $this->hasOne('App\Models\Billing', 'id', 'billing_id');
    }

    public function getSubscriptionAttribute()
    {
        return $this->subscriptions()->first();
    }

    public function getTransactionAttribute()
    {
        if ($this->info) {
            return $this->info->transactionId;
        }

        return null;
    }

    public function getEmailAttribute()
    {
        if ($this->info) {
            return $this->info->email;
        }

        return null;
    }

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            $model->expiration_date = now()->addMinutes(30);
        });
    }

    protected static function booted()
    {
        static::created(function ($oPayment) {
            $oPayment->subscriptions()->create([
                'payment_id' => $oPayment->id,
                'subscription_id' => Subscription::getFirst()->id
            ]);

            $oPayment->info()->create([
                'payment_id' => $oPayment->id
            ]);
        });
    }

    public function resetTimer()
    {
        $this->expiration_date = now()->addMinutes(30);

        if ($this->status === Payment::STATUS_EXPIRED) {
            $this->status = Payment::STATUS_NEW;
        }

        $this->save();
    }

    public function setStatus($status)
    {
        $this->status = $status;
        $this->save();
    }

    public static function getByInvoiceId($invoiceId)
    {
        if (empty($invoiceId)) {
            throw new \Exception('Не передан invoiceId');
        }

        $oPayment = Payment::where('invoice_id', '=', $invoiceId)->first();

        if (!$oPayment) {
            throw new \Exception('Платеж не найден');
        }

        return $oPayment;
    }

    public static function getByUuid($uuid)
    {
        if (empty($uuid)) {
            throw new \Exception('Не передан uuid');
        }

        $oPayment = Payment::where('uuid', '=', $uuid)->first();

        if (!$oPayment) {
            throw new \Exception('Платеж не найден');
        }

        return $oPayment;
    }

    /**
     * @param int $id
     * @return mixed
     * @throws \Exception
     */
    public function getById(int $id): mixed
    {
        if (empty($id)) {
            throw new \Exception('Не передан id');
        }

        $oPayment = Payment::where('id', '=', $id)
            ->with('info:id,payment_id,transactionId,email')
            ->first();

        if (!$oPayment) {
            throw new \Exception('Платеж не найден');
        }

        return $oPayment;
    }

    public static function isExists($invoiceId) {
        return Payment::where('invoice_id', '=', $invoiceId)->exists();
    }
}
