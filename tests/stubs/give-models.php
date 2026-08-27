<?php
/**
 * Stand-ins for the GiveWP models the plugin type-hints against, so tested
 * functions can be called with a donation-shaped object.
 */

namespace Give\Donations\Models;

class Donation
{
    public $id;
    public $formTitle;
    public $amount;
    public $status;
    public $email;
    public $firstName;
    public $lastName;
    public $gatewayTransactionId;

    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $name => $value) {
            $this->{$name} = $value;
        }
    }
}
