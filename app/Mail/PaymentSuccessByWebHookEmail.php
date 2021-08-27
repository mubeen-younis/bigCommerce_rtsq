<?php

namespace App\Mail;

use App\Constants\Constant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentSuccessByWebHookEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    private $subscriptionDetails;
    public function __construct($subscriptionDetails)
    {
        $this->subscriptionDetails = $subscriptionDetails;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Payment Succeeded')
            ->replyTo([Constant::ENITURE_SUPPORT_EMAIL])
            ->view('emails.paymentfailed',['data' => $this->subscriptionDetails,'paymentfailed' => false]);
    }
}
