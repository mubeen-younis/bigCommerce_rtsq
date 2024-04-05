<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;
use Illuminate\Queue\SerializesModels;
use App\Constants\Constant;

class ExportCsvNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public $hash;
    public function __construct($hash)
    {
        $this->hash = $hash;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $hash = $this->hash;
        $url = URL::to('api/downloadcsv/'.$hash);
        return $this->subject('The CSV export of your product shipping parameters is being processed.')
            ->replyTo([Constant::ENITURE_SUPPORT_EMAIL])
            ->view('emails.exportcsvnotification', compact('url'));
    }
}
