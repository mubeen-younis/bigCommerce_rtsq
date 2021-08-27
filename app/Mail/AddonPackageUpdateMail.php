<?php

namespace App\Mail;

use App\Constants\Constant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AddonPackageUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    private $addonType;
    private $subscriptionDetail;
    public function __construct($addonType,$subscriptionDetail)
    {
        $this->addonType = $addonType;
        $this->subscriptionDetail = $subscriptionDetail;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $addonName = ($this->addonType == 'SBS') ? 'Standard Box Sizes' : 'Residential Address Detection';
        return $this->subject($addonName.' Addon Subscription')
            ->replyTo([Constant::ENITURE_SUPPORT_EMAIL])
            ->view('emails.addonpackage',['addon' => $addonName, 'subscriptionDetail'=>$this->subscriptionDetail]);
    }
}
