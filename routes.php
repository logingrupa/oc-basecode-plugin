<?php
use Illuminate\Http\Request;
Route::group(['prefix' => 'v1'], function () {
    Route::any('1c/1c_exchange', function (Request $request) {
        $arSafeHeaders = array_diff_key($request->headers->all(), array_flip(['authorization', 'php-auth-user', 'php-auth-pw']));
        Log::info('1C Exchange Request: ' .
            'URL: ' . $request->fullUrl() . ' | ' .
            'Method: ' . $request->method() . ' | ' .
            'Headers: ' . json_encode($arSafeHeaders) . ' | ' .
            'Body: ' . json_encode($request->all())
        );
    

        // Log the incoming request data
        Log::info('Request received with data: ' . json_encode(Input::all()));

        $sMode = Input::get('mode');
        $sType = Input::get('type');

        // Log mode and type
        Log::info("Processing request with Mode: $sMode, Type: $sType");

        // CheckAuth for Catalog (Authentication step for catalog exchange)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=catalog&mode=checkauth
        if ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\Auth::MODE_CHECK_AUTH && $sType === \Lovata\BaseCode\Classes\Helper\OneC\Auth::TYPE_CATALOG) {
            Log::info("Initializing Auth for Catalog");
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\Auth();

        // CheckAuth for Sale (Authentication step for sales exchange)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=sale&mode=checkauth
        } elseif ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\Auth::MODE_CHECK_AUTH && $sType === \Lovata\BaseCode\Classes\Helper\OneC\Auth::TYPE_SALE) {
            Log::info("Initializing Auth for Sale");
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\Auth();

        // Init for Sale (Initialization step for sales exchange)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=sale&mode=init
        } elseif ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\Init::MODE_CHECK_INT && $sType === \Lovata\BaseCode\Classes\Helper\OneC\Init::TYPE_SALE) {
            Log::info("Initializing Init for Sale");
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\Init();

        // File Upload for Sale (File upload step for sales exchange)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=sale&mode=file&filename=v8_4910_77.xml
        } elseif ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\FileUpload::MODE_FILE && $sType === \Lovata\BaseCode\Classes\Helper\OneC\FileUpload::TYPE_SALE) {
            Log::info("Processing File Upload for Sale");
            (new \Lovata\BaseCode\Classes\Helper\OneC\FileUpload())->processing();
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\ImportOrders();

        // Query Orders for Sale (Retrieve orders from the system)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=sale&mode=query
        } elseif ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\QueryOrders::MODE_QUERY && $sType === \Lovata\BaseCode\Classes\Helper\OneC\QueryOrders::TYPE_SALE) {
            Log::info("Processing Query for Sale");
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\QueryOrders();

        // Success for Sale (Finalization step after successful data exchange)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=sale&mode=success
        } elseif ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\SuccessOrders::MODE_SUCCESS && $sType === \Lovata\BaseCode\Classes\Helper\OneC\SuccessOrders::TYPE_SALE) {
            Log::info("Processing Success for Sale");
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\SuccessOrders();

        // Import Orders for Sale (Importing orders data)
        // URL: https://nailscosmetics.lv/v1/1c/1c_exchange?type=sale&mode=import&filename=yourfile.xml
        } elseif ($sMode === \Lovata\BaseCode\Classes\Helper\OneC\ImportOrders::MODE_IMPORT && $sType === \Lovata\BaseCode\Classes\Helper\OneC\ImportOrders::TYPE_SALE) {
            Log::info("Processing Import for Sale");
            $obObject = new \Lovata\BaseCode\Classes\Helper\OneC\ImportOrders();

        // Handle invalid mode/type combinations
        } else {
            Log::warning("No valid object found for Mode: $sMode, Type: $sType");
            $obObject = null;
        }

        // If no object was successfully initialized, log the error and return an empty response
        if (empty($obObject)) {
            Log::error("Failed to initialize an object for Mode: $sMode, Type: $sType");
            return '';
        }

        // Log and process the request using the initialized object
        Log::info("Calling processing method on object for Mode: $sMode, Type: $sType");
        return $obObject->processing();
    });
});
