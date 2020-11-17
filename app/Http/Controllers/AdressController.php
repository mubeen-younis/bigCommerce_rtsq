<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdressController extends Controller
{
    public function googleApiCurl($zip_code)
    {
          
        $ch = curl_init();

        curl_setopt($ch,CURLOPT_URL,"https://eniture-dev3.com/ws/addon/google-location.php");

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS,"eniureLicenceKey=SAIAFYEJ-UMAIR-DEV38FSF-YUOBODLA&ServerName=wpqa2.eniture-qa.com&acessLevel=address&address=$zip_code");

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         
        // Submit the POST request
        $result = curl_exec($ch);
         
        // Close cURL session handle
        curl_close($ch);

        return $result;
    
    }
}
