warning: LF will be replaced by CRLF in app/CustomClasses/GenerateRequestData.php.
The file will have its original line endings in your working directory
[1mdiff --git a/app/CustomClasses/GenerateRequestData.php b/app/CustomClasses/GenerateRequestData.php[m
[1mindex 94c4637..4e8e60b 100644[m
[1m--- a/app/CustomClasses/GenerateRequestData.php[m
[1m+++ b/app/CustomClasses/GenerateRequestData.php[m
[36m@@ -400,7 +400,6 @@[m [mclass GenerateRequestData[m
                     $itemsArr = $sbsResponse['items'] ?? $itemsArr;[m
                 }[m
 [m
[31m-[m
                 if (isset($carriers['wweSmall'])) {[m
                     $carriers['wweSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;[m
                 }[m
[36m@@ -1291,7 +1290,6 @@[m [mclass GenerateRequestData[m
             $binResponse = $Bin3D->getBinResponse($storeId, $boxBins, $items, $itemsAlone, $hits, $cartInfo, $isMultishipment);[m
             if (count($binResponse)) {[m
 [m
[31m-[m
                 foreach ($itemsAlone as $key => $itemAlone) {[m
                     foreach ($itemAlone as $alone) {[m
                         if (count($items) && isset($items[$key])) {[m
[36m@@ -1364,6 +1362,7 @@[m [mclass GenerateRequestData[m
                 $newitemsArr = $this->itemsArr;[m
             }[m
         } else {[m
[32m+[m
             $newOrigins = $this->origins;[m
             $newitemsArr = $this->itemsArr;[m
         }[m
