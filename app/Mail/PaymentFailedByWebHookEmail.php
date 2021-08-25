<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentFailedByWebHookEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    private $subscriptionDetails;
    private $paymentStatus;
    public function __construct($subscriptionDetails,$paymentStatus)
    {
        $this->subscriptionDetails = $subscriptionDetails;
        $this->paymentStatus = $paymentStatus;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = '';
        if ($this->paymentStatus == 0){
            $subject = 'Real-time Shipping Quotes Subscription Payment Failed';
        } elseif($this->paymentStatus == 1){
            $subject = 'Real-time Shipping Quotes Subscription Payment Successful';
        } elseif($this->paymentStatus == 2){
            $subject = 'Real-time Shipping Quotes Subscription Expired';
        }elseif($this->paymentStatus == 3){
            $subject = 'Real-time Shipping Quotes Trial Activated';
        }
        return $this->subject($subject)
            ->replyTo(['support@eniture.com'])
            ->view('emails.paymentfailed',['data' => $this->subscriptionDetails, 'paymentStatus'=>$this->paymentStatus]);
    }
}
