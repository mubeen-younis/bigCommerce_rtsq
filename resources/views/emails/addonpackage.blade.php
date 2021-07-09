<div>

    You have successfully subscribed {{$subscriptionDetail->current_package_name.' ($'.$subscriptionDetail->current_package_cost.')'}} {{($subscriptionDetail->package_id != 1 && $subscriptionDetail->package_id != 7) ? 'monthly' : 'trial'}} package for the {{$addon}} addon and it will be expired on {{$subscriptionDetail->expiry_time}}. Your subscription will{{$subscriptionDetail->package_to_be_charge_status != 'disable' ? '':' not'}} be auto renew at the end of the period.
    <br/>

    <p>Sincerely,<br />
        Customer Support<br />
        T: 404-369-0680 x2<br />
        E: support@eniture.com</p>
    <img src="{{asset('assets/imgs/mailsignature.jpg')}}" width="150px"/>
</div>
