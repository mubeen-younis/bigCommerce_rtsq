<?php

namespace App\Models;

use App\CustomClasses\Functions;
use App\Models\Subscription\Subscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SubscriptionStripePayments extends Model
{
    use HasFactory;

    protected $table = "subscription_stripe_payments";

    public static function addOrUpdateSubscriptionPayment($stripeObjectData, $paymentData)
    {
        try {
            $invoiceID = $paymentData->data->object->id ?? null;
            $subscriptionID = $paymentData->data->object->subscription ?? null;
            $receiptNumber = $paymentData->data->object->number ?? null;
            if (blank($invoiceID) || blank($subscriptionID)) {
                return null;
            }

            $subscriptionPayment = self::addOrUpdateRecord($invoiceID);

            $invoiceUrl = $paymentData->data->object->invoice_pdf ?? "";

            $receiptUrl = self::getReceiptUrl($invoiceUrl);

            $subscribedBy = Subscription::where('subscription_id', $subscriptionID)->first();
            if (blank($subscribedBy)) {
                return null;
            }

            $subscriptionPayment->invoice_id = $invoiceID;
            $subscriptionPayment->receipt_number = $receiptNumber;

            $subscriptionPayment->amount = self::getAmountInDols($stripeObjectData->amount);

            $subscriptionPayment->is_addon = 0;
            $subscriptionPayment->plan_id = $subscribedBy->plan_id;
            $subscriptionPayment->store_id = $subscribedBy->store_id;
            $subscriptionPayment->invoice_download_url = $invoiceUrl;
            $subscriptionPayment->receipt_url = $receiptUrl;
            $subscriptionPayment->save();

        } catch (\Exception|\Throwable $exception) {
            Log::info('Exception on updating payments ' . json_encode(Functions::returnFormExceptionArray($exception)));
        }

    }


    /**
     * @param $storeID
     * @param int $limit
     * @return array
     */
    public static function getSubscriptionStripePayments($storeID, int $limit = 50): array
    {
        return self::leftJoin('plans', function ($join) {
            $join->on('subscription_stripe_payments.plan_id', '=', 'plans.id')
                ->where('subscription_stripe_payments.is_addon', '=', 0);
        })
            ->leftJoin('packages', function ($join) {
                $join->on('subscription_stripe_payments.plan_id', '=', 'packages.id')
                    ->where('subscription_stripe_payments.is_addon', '=', 1);
            })
            ->leftJoin('addons', function ($join) {
                $join->on('packages.addon_type', '=', 'addons.short_code');
            })
            ->select('subscription_stripe_payments.*', 'plans.name as product_name', 'addons.name as addon_name')
            ->where('subscription_stripe_payments.store_id', $storeID)
            ->orderBy('subscription_stripe_payments.id', 'desc')
            ->limit($limit)
            ->get()
            ->toArray() ?? [];
    }


    /**
     * @param $package
     * @param $stripeObjectData
     * @param $stripeCustomerId
     * @return void|null
     */
    public static function addOrUpdateAddonsPayment($package, $stripeChargeData, $stripeCustomerId)
    {
        try {
            $invoiceID = $stripeChargeData->id ?? null;
            $subscribedBy = Subscription::where('stripe_id', $stripeCustomerId)->first();


            if (blank($invoiceID) || blank($subscribedBy)) {
                return null;
            }

            $subscriptionPayment = self::addOrUpdateRecord($invoiceID);

            $receiptUrl = $stripeChargeData->receipt_url ?? "";

            $subscriptionPayment->invoice_id = $invoiceID;
            $subscriptionPayment->receipt_number = str_replace("ch_", "", $invoiceID);
            $subscriptionPayment->amount = self::getAmountInDols($stripeChargeData->amount);
            $subscriptionPayment->is_addon = 1;
            $subscriptionPayment->plan_id = $package->id;
            $subscriptionPayment->store_id = $subscribedBy->store_id;
            $subscriptionPayment->invoice_download_url = $receiptUrl;
            $subscriptionPayment->receipt_url = $receiptUrl;
            $subscriptionPayment->save();
        } catch (\Exception|\Throwable $exception) {
            Log::info('Exception on adding addons ' . json_encode(Functions::returnFormExceptionArray($exception)));
        }

    }

    /**
     * @param $invoiceID
     * @return SubscriptionStripePayments
     */
    public static function addOrUpdateRecord($invoiceID): SubscriptionStripePayments
    {
        $subscriptionPayment = SubscriptionStripePayments::where('invoice_id', $invoiceID)->first();

        if (blank($subscriptionPayment)) {
            $subscriptionPayment = new SubscriptionStripePayments();
        }

        return $subscriptionPayment;
    }


    /**
     * @param $invoiceUrl
     * @return string
     */
    public static function getReceiptUrl($invoiceUrl): string
    {
        $search = ["https://pay.stripe.com/invoice", "/pdf?s=ap", "/pdf"];
        $replace = ["https://invoicedata.stripe.com/invoice_receipt_file_url", "", ""];
        return str_replace($search, $replace, $invoiceUrl);
    }

    /**
     * @param $amount
     * @return float|int
     */
    public static function getAmountInDols($amount): float|int
    {
        return (float)$amount / 100;
    }

    /**
     * @param $id
     * @return array
     */
    public static function getInvoiceDetail($id)
    {
        return optional(self::where('id', $id)->first())->toArray() ?? [];
    }

}
