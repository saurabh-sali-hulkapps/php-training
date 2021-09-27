<?php

namespace App\Http\Controllers;

use App\Models\LicensedJurisdiction;
use App\Models\ProductInfo;
use App\Models\Setting\FailoverCheckout;
use App\Models\User;
use App\Services\AvalaraExciseHelper;
use App\Traits\Helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvalaraController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|string
     */
    public function create(Request $request)
    {
        $requestData = $request['data'];
        $requestDataAdjust = $requestData;
        $transactionLines = $requestData['TransactionLines'];
        $shop = $request->get('shop');

        $getList = $this->commonList($shop, $transactionLines);

        if (!empty($requestDataAdjust['TransactionLines'])) {
            $this->commonRequestDataAdjustNotEmpty($request, $shop, $requestData, $transactionLines, $getList);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function failoverMetafieldCreate(Request $request) {
        $shop = $request->get('shop');
        $shop = User::where('id', $shop->id)->first();
        $failoverCheckout = FailoverCheckout::where('shop_id', $shop->id)->first();
        $messages = json_decode($failoverCheckout->failover_message);

        $metafieldObj = [];
        foreach ($messages as $key => $message) {
            switch ($key) {
                case 1:
                    $metafieldObj['place_order'] = $message;
                    break;
                case 2:
                    $metafieldObj['does_not_place_order'] = $message;
                    break;
                case 3:
                    $metafieldObj['unauthorized'] = $message;
                    break;
            }
        }
        $metafieldObj['selected_option'] = $failoverCheckout->action == 1 ? 'place_order' : 'does_not_place_order';

        $parameters['namespace'] = "ava_failover_setting";
        $parameters['key'] = "ava_failover_setting";
        $parameters['value'] = json_encode($metafieldObj);
        $parameters['value_type'] = 'json_string';
        $url = '/admin/metafields.json';
        $metafield['metafield'] = $parameters;
        $shop->api()->rest('POST', $url, $metafield);

        return response(json_encode($metafieldObj));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|string
     */
    public function createTest(Request $request)
    {
        $input = $request->all();
        $requestData = $request['data'];
        $requestDataAdjust = $requestData;
        $transactionLines = $requestData['TransactionLines'];

        $shop = User::where('name', $input['shopDomain'])->first();

        $getList = $this->commonList($shop, $transactionLines);

        if (!empty($requestDataAdjust['TransactionLines'])) {
            $this->commonRequestDataAdjustNotEmpty($request, $shop, $requestData, $transactionLines, $getList);
        } else {
            return response(["excise_tax" => 0], 200);
        }
    }

    /**
     * @param $item
     * @param $productForExcise
     * @param $productIdentifierForExcise
     * @return bool
     */
    public function filterRequest($item, $productForExcise, $productIdentifierForExcise)
    {
        if ($productForExcise->option == 2) {
            $isExist = ProductInfo::where('alternate_product_code', $item['ProductCode'])->exists();
            if (!$isExist) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $shop
     * @param $transactionLines
     * @return array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function commonList($shop, $transactionLines)
    {
        list($companyId, $apiUsername, $apiUserPassword) = Helpers::avalaraCredentials($shop->id);

        list(
            $titleTransferCode, $transactionType, $transportationModeCode, $seller, $buyer, $unitOfMeasure, $currency, $origin,
            $orderCustomString1, $orderCustomString2, $orderCustomString3, $orderCustomNumeric1, $orderCustomNumeric2, $orderCustomNumeric3,
            $itemCustomString1, $itemCustomString2, $itemCustomString3, $itemCustomNumeric1, $itemCustomNumeric2, $itemCustomNumeric3
            ) = Helpers::staticSettings($shop->id);


        $productForExcise = Helpers::productForExcise($shop->id);
        $productIdentifierForExcise = Helpers::productIdentifierForExcise($shop->id);

        $requestDataAdjust['TransactionLines'] = [];
        foreach ($transactionLines as $item) {
            $isLicensedJurisdiction = LicensedJurisdiction::where('jurisdiction', $item['DestinationJurisdiction'])->exists();

            //If product's destinationJurisdiction can be found in our database, It will terminate script.
            if (!$isLicensedJurisdiction) {
                $failover = Helpers::failoverCheckout($shop->id, 1);
                return response(["error" => $failover['failoverMessage']], $failover['statusCode']);
                break;
            }

            if ($this->filterRequest($item, $productForExcise, $productIdentifierForExcise)) {
                unset($item['tags'], $item['itemSKU']);
                $item['UnitOfMeasure'] = $unitOfMeasure;
                $item['Currency'] = $currency;
                $item['Origin'] = $origin;
                $requestDataAdjust['TransactionLines'][] = $item;
            }
        }

        return [
            'titleTransferCode' => $titleTransferCode,
            'transactionType' => $transactionType,
            'transportationModeCode' => $transportationModeCode,
            'seller' => $seller,
            'buyer' => $buyer,
            'companyId' => $companyId,
            'apiUsername' => $apiUsername,
            'apiUserPassword' => $apiUserPassword,
        ];
    }

    /**
     * @param $request
     * @param $shop
     * @param $requestData
     * @param $transactionLines
     * @param $getList
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|string
     */
    public function commonRequestDataAdjustNotEmpty($request, $shop, $requestData, $transactionLines, $getList)
    {
        $requestDataAdjust['TitleTransferCode'] = $getList['titleTransferCode'];
        $requestDataAdjust['TransactionType'] = $getList['transactionType'];
        $requestDataAdjust['TransportationModeCode'] = $getList['transportationModeCode'];
        $requestDataAdjust['Seller'] = $getList['seller'];
        $requestDataAdjust['Buyer'] = $getList['buyer'];

        $newService = new AvalaraExciseHelper();
        $newService->setCredentials($getList['apiUsername'], $getList['apiUserPassword'], $getList['companyId']);
        $response = $newService->calculateExcise($requestDataAdjust);

        DB::table('avalara_transaction_log')->insert([
            "ip" => $request->ip(),
            "shop_id" => $shop->id,
            "request_data" => json_encode($requestData),
            "total_requested_products" => count($transactionLines),
            "response" => $response->status() != 200 ? json_encode($response->body()) : $response->body(),
            "filtered_request_data" => json_encode($requestDataAdjust),
            "status" =>$response->status(),
            "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
            "updated_at" => Carbon::now()->format('Y-m-d H:i:s')
        ]);

        if ($response->status() != 200) {
            $failover = Helpers::failoverCheckout($shop->id);
            return response(["error" => $failover['failoverMessage'], "disable_checkout" => $failover['disableCheckout']], $failover['statusCode']);
        } else {
            return $response->body();
        }
    }
}
