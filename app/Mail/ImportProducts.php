<?php

namespace App\Mail;

use App\Constants\Constant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ImportProducts extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public $errorProducts;
    public function __construct($errorProducts)
    {
        $this->errorProducts = $errorProducts;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $data = $this->errorProducts;
        return $this->subject('Batch import was successful')
            ->replyTo([Constant::ENITURE_SUPPORT_EMAIL])
            ->view('emails.importproducts', compact('data'));
    }
}
