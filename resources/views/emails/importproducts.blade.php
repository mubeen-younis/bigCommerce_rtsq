<div>
    The CSV file containing your product shipping parameter data has been processed.
    <br/>
    @if(!empty($data))
    The products that are not updated : 
    <ul>
        @foreach($data as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>
    @else
    @endif
    <br/>

    <p>Sincerely,<br />
        Customer Support<br />
        T: 404-369-0680 x2<br />
        E: support@eniture.com</p>
    <img src="{{asset('public/assets/imgs/mailsignature.jpg')}}" width="150px"/>
</div>
