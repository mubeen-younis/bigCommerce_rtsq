<div>
    {{--When payment failed--}}
    @if($paymentStatus == 0)
    You are subscribed to the Monthly {{$data['planName']}} Plan of the Eniture product '{{$data['productName']}}'. But your subscription's recurring payment is failed. That's why you received a 'Failed Payment Alert' from the Eniture. If you want to continue using this subscription then you should update your credit card information in your BigCommerce store on {{$data['productName']}}'s Plans page.
    @endif
    {{--When Subscription expired--}}
    @if($paymentStatus == 2)
        Your monthly {{$data['planName']}} Plan subscription has been cancelled for the Eniture product '{{$data['productName']}}'. That's why you received a 'Subscription cancellation Alert' from the Eniture. If you want to continue using this subscription then you should subscribe a Plan.
    @endif
    {{--When payment is not failed (Success case)--}}
    @if($paymentStatus == 1)
        You are subscribed to the Monthly {{$data['planName']}} Plan of the Eniture product '{{$data['productName']}}' and using this Eniture App on your BigCommerce store.
    @endif{{--When payment is not failed (Trial case)--}}
    @if($paymentStatus == 3)
        You are subscribed to the {{$data['planName']}} for the Eniture product '{{$data['productName']}}'. Your trial will be ended at {{$data['endsAt']}}.
    @endif
    <br/>

    <p>Sincerely,<br />
        Customer Support<br />
        T: 404-369-0680 x2<br />
        E: support@eniture.com</p>
    <img src="{{asset('assets/imgs/mailsignature.jpg')}}" width="150px"/>
</div>
