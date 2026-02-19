<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerCredentialMail extends Mailable
{
    use Queueable, SerializesModels;

    public $customer;
    public $password;

    public function __construct($customer, $password)
    {
        $this->customer = $customer;
        $this->password = $password;
    }

    public function build()
    {
        return $this->subject('Your Store Login Details')
                    ->view('emails.credentials');
    }
}
