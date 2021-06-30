<div>
    {{--When payment failed--}}
    @if($paymentfailed)
    You are subscribed to the Monthly {{$data['planName']}} Plan of the Eniture product '{{$data['productName']}}'. But your subscription's recurring payment is failed. That's why you received a 'Failed Payment Alert' from the Eniture. If you want to continue using this subscription then you should update your credit card information in your BigCommerce store on {{$data['productName']}}'s Plans page.
    @endif
    {{--When payment is not failed (Success case)--}}
    @if(!$paymentfailed)
        You are subscribed to the Monthly {{$data['planName']}} Plan of the Eniture product '{{$data['productName']}}' and using this Eniture App on your BigCommerce store.
    @endif
    <br/>

    <p>Sincerely,<br />
        Customer Support<br />
        T: 404-369-0680 x2<br />
        E: support@eniture.com</p>
    <img src="{{asset('assets/imgs/mailsignature.jpg')}}" width="150px"/>
</div>
