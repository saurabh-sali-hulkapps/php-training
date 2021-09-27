<?php


namespace App\Services;


use App\Models\ExciseByProduct;
use App\Models\Setting\FailoverCheckout;
use App\Models\User;
use App\Traits\Helpers;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OrderJobHelper
{
    public function orderJobHandle($shopDomain, $data, $isOrderCreate = false)
    {
        $newService = new AvalaraExciseHelper();

        $shop = User::where(['name' => $shopDomain->toNative()])->first();

        $productForExcise = Helpers::productForExcise($shop->id);
        $productIdentifierForExcise = Helpers::productIdentifierForExcise($shop->id);

        if (!empty($data->note_attributes)) {
            foreach ($data->note_attributes as $noteAttribute) {

                if ($isOrderCreate) {
                    if ($noteAttribute->name === 'checkout_failure' && $noteAttribute->value === 'true') {
                        $failoverCheckout = FailoverCheckout::where('shop_id', $shop->id)->get();

                        if ($failoverCheckout) {
                            foreach ($failoverCheckout as $item) {
                                $dbTags = $item->tags ? json_decode($item->tags) : null;

                                if ($dbTags) {
                                    $tags = array_map('trim', explode(',', $data->tags));
                                    foreach ($dbTags as $dbTag) {
                                        $tags[] = $dbTag->value;
                                    }

                                    $orderObj = [
                                        'id' => $data->id,
                                        'tags' => implode(',', $tags)
                                    ];
                                    $shop->api()->rest('PUT', '/admin/orders/' . $data->id . '.json', ['order' => $orderObj]);
                                }
                            }
                        }
                    }
                }

                if ($noteAttribute->name === 'transaction_id') {

                    $transactionLines = $variantIds = $productIds = $past_fulfilled_items = [];
                    $itemCounter = 0;

                    if (!empty($data->line_items)) {
                        foreach ($data->line_items as $line_item) {
                            if (!empty($line_item->sku)) {
                                $productTags = $shop->api()->rest('GET', '/admin/products/'.$line_item->product_id.'.json');
                                if(isset($productTags['body']['product']) && !empty($productTags['body']['product'])) {
                                    $productTags = $productTags['body']['product']['tags'];
                                }
                                $item['ProductCode'] = $item['itemSKU'] = Str::substr($line_item->sku, 0, 24);
                                $item['tags'] = $productTags;
                                if (!filterRequest($item, $productForExcise, $productIdentifierForExcise)) {
                                    continue;
                                }

                                $variantIds[] = $line_item->variant_id;
                                $productIds[] = $line_item->product_id;
                                $transactionLines[] = $newService->setTransactionLines($shop, $itemCounter, $line_item, $data, $isOrderCreate);
                            }
                        }
                    }

                    $requestDataAdjust = $newService->setRequestDataAdjust($shop, $transactionLines, $data);

                    if (!empty($transactionLines)) {

                        $newService = new AvalaraExciseHelper();
                        $response = $newService->commonCalculateExcise($requestDataAdjust, $shop, $productIds, $transactionLines);

                        $exciseTax = 0;
                        $transactionError = null;
                        if ($response->status() == 200) {
                            $responseTemp = json_decode($response->body());
                            $exciseTax = $responseTemp->TotalTaxAmount;

                            foreach ($responseTemp->TransactionTaxes as $key => $transactionTax) {
                                if (isset($productIds[$key])) {
                                    $exciseByProduct = ExciseByProduct::where('shop_id', $shop->id)
                                        ->where('product_id', $productIds[$key])
                                        ->where('date', Carbon::parse($data->created_at)->format('Y-m-d'))->first();
                                }
                                if ($exciseByProduct) {
                                    $exciseByProduct->excise_tax += $transactionTax->TaxAmount;
                                    $exciseByProduct->save();
                                } else {
                                    ExciseByProduct::create([
                                        'shop_id' => $shop->id,
                                        'product_id' => $productIds[$key],
                                        'excise_tax' => $transactionTax->TaxAmount,
                                        'date' => Carbon::parse($data->created_at)->format('Y-m-d')
                                    ]);
                                }
                            }
                        } else {
                            $transactionError = json_encode($response->body());
                        }

                        $newService->setTransactionObj($shop, $data, $transactionLines, $exciseTax, $transactionError);
                    }

                    /*$orderMetaRes = $shop->api()->rest('GET', '/admin/orders/'.$data->id.'/metafields.json');
                    if (!empty($orderMetaRes->body->metafields)) {
                        $metaData = $orderMetaRes->body->metafields;
                        $isExistsFulfilledMeta = $isExistsRefundedMeta = false;
                        $fulfilledMetaItems = $fulfilledMetaItemsId = $refundedMetaItems = $refundedMetaItemsId = '';

                        foreach ($metaData as $meta) {
                            if ($meta->key == "fulfilled_items") {
                                $isExistsFulfilledMeta = true;
                                $fulfilledMetaItems = $meta->value;
                                $fulfilledMetaItemsId = $meta->id;
                            }

                            if ($meta->key == "refuned_items") {
                                $isExistsRefundedMeta = true;
                                $refundedMetaItems = $meta->value;
                                $refundedMetaItemsId = $meta->id;
                            }
                        }

                        if ($isExistsFulfilledMeta) {
                            if (!empty($data->refunds)) {
                                foreach ($data->refunds as $key => $refund) {
                                    if (!empty($refund->refund_line_items)) {
                                        foreach ($refund->refund_line_items as $item) {
                                            if (!empty($item->line_item->sku)) {
                                                $variantIds[] = $item->line_item->variant_id;
                                                $transactionLines[] = [
                                                    "TransactionLineMeasures" => null,
                                                    "OriginSpecialJurisdictions" => [],
                                                    "DestinationSpecialJurisdictions" => [],
                                                    "SaleSpecialJurisdictions" => [],
                                                    "InvoiceLine" => ++$itemCounter,
                                                    //"MovementStartDate" => $currentDateTime,
                                                    //"MovementEndDate" => $currentDateTime,
                                                    "ProductCode" => $item->line_item->sku ? Str::substr($item->line_item->sku, 0, 24) : '',
                                                    //"BlendToProductCode" => null,
                                                    "UnitPrice" => $item->line_item->price,
                                                    "NetUnits" => $item->line_item->quantity,
                                                    "GrossUnits" => $item->line_item->quantity,
                                                    "BilledUnits" => -$item->line_item->quantity,
                                                    //"LineAmount" => null,
                                                    //"BillOfLadingNumber" => "",
                                                    "BillOfLadingDate" => $invoiceDate,
                                                    //"OriginCountryCode" => null,
                                                    //"OriginJurisdiction" => null,
                                                    //"OriginCounty" => null,
                                                    //"OriginCity" => null,
                                                    //"OriginPostalCode" => null,
                                                    //"OriginType" => null,
                                                    "Origin" => $origin,
                                                    //"OriginOutCityLimitInd" => null,
                                                    //"OriginSpecialJurisdictionInd" => null,
                                                    //"OriginExciseWarehouse" => null,
                                                    "OriginAddress1" => isset($data->shipping_address) ? $data->shipping_address->address1 : '',
                                                    "OriginAddress2" => null,//isset($data->shipping_address) ? $data->shipping_address->address2 : '',
                                                    //"OriginAirportCode" => null,
                                                    "DestinationCountryCode" => isset($data->shipping_address) ? $data->shipping_address->country_code : '',
                                                    "DestinationJurisdiction" => isset($data->shipping_address) ? $data->shipping_address->province_code : '',
                                                    "DestinationCounty" => "",
                                                    "DestinationCity" => isset($data->shipping_address) ? $data->shipping_address->city : '',
                                                    "DestinationPostalCode" => isset($data->shipping_address) ? $data->shipping_address->zip : '',
                                                    //"DestinationType" => null,
                                                    //"Destination" => "",
                                                    //"DestinationOutCityLimitInd" => null,
                                                    //"DestinationSpecialJurisdictionInd" => null,
                                                    //"DestinationExciseWarehouse" => null,
                                                    "DestinationAddress1" => isset($data->shipping_address) ? $data->shipping_address->address1 : '',
                                                    "DestinationAddress2" => isset($data->shipping_address) ? $data->shipping_address->address2 : '',
                                                    //"DestinationAirportCode" => null,
                                                    //"SaleCountryCode" => "",
                                                    //"SaleJurisdiction" => "",
                                                    //"SaleCounty" => "",
                                                    //"SaleCity" => "",
                                                    //"SalePostalCode" => "",
                                                    //"SaleType" => "",
                                                    //"SaleLocation" => null,
                                                    //"SaleOutCityLimitInd" => null,
                                                    //"SaleSpecialJurisdictionInd" => null,
                                                    //"SaleExciseWarehouse" => "",
                                                    //"SaleAddress1" => "",
                                                    //"SaleAddress2" => "",
                                                    //"SaleAirportCode" => "",
                                                    //"CounterCountryCode" => "",
                                                    //"CounterJurisdiction" => "",
                                                    //"CounterCounty" => "",
                                                    //"CounterCity" => "",
                                                    //"CounterPostalCode" => "",
                                                    //"CounterType" => "",
                                                    //"CounterParty" => null,
                                                    //"CounterOutCityLimitInd" => null,
                                                    //"CounterSpecialJurisdictionInd" => null,
                                                    //"CounterExciseWarehouse" => "",
                                                    //"CounterFiscalRepInd" => null,
                                                    //"CounterAddress1" => "",
                                                    //"CounterAddress2" => "",
                                                    //"CounterAirportCode" => "",
                                                    //"UserData" => "",
                                                    //"AlternativeFuelContent" => null,
                                                    //"BlendToAltFuelContent" => null,
                                                    //"BlendToInd" => null,
                                                    "Currency" => $currency,
                                                    "UnitOfMeasure" => $unitOfMeasure,
                                                    //"FreightUnitPrice" => null,
                                                    //"FreightType" => "",
                                                    //"FreightLineAmount" => null,
                                                    "CustomString1" => $itemCustomString1 ? getCustomString($itemCustomString1->value, $data) : null,
                                                    "CustomString2" => $itemCustomString2 ? getCustomString($itemCustomString2->value, $data) : null,
                                                    "CustomString3" => $itemCustomString3 ? getCustomString($itemCustomString3->value, $data) : null,
                                                    "CustomNumeric1" => $itemCustomNumeric1 ? getCustomNumeric($itemCustomNumeric1->value, $data) : null,
                                                    "CustomNumeric2" => $itemCustomNumeric2 ? getCustomNumeric($itemCustomNumeric2->value, $data) : null,
                                                    "CustomNumeric3" => $itemCustomNumeric3 ? getCustomNumeric($itemCustomNumeric3->value, $data) : null,
                                                    //"NthTimeSale" => null,
                                                    "AlternateUnitPrice" => getVariant($this->shopDomain, $item->line_item->variant_id),
                                                    //"AlternateLineAmount" => null,
                                                    //"TaxIncluded" => null
                                                ];
                                            }
                                        }
                                    }
                                }

                                $requestDataAdjust = [
                                    'adjustmentReason' => 0,
                                    'adjustmentDescription' => '',
                                    'newTransaction' => [
                                        'TransactionLines' => $transactionLines,
                                        'TransactionExchangeRates' => [],
                                        //'EffectiveDate' => $currentDateTime,
                                        'InvoiceDate' => $invoiceDate,
                                        'InvoiceNumber' => $data->order_number . '-1',
                                        //'FuelUseCode' => '',
                                        'TitleTransferCode' => $titleTransferCode,
                                        'TransactionType' => $transactionType,
                                        'TransportationModeCode' => $transportationModeCode,
                                        'Seller' => $seller,
                                        'Buyer' => $buyer,
                                        //'PreviousSeller' => null,
                                        //'NextBuyer' => null,
                                        //'Middleman' => null,
                                        //'CustomsStatus' => '',
                                        //'FormAPresentedInd' => null,
                                        //'SimplifiedProcedureInd' => null,
                                        //'Incoterms' => '',
                                        //'PerspectiveBusinessType' => '',
                                        //'ChainLeg' => null,
                                        //'OrderType' => '',
                                        //'TotalDyedUnits' => null,
                                        //'TotalReportingTaxes' => 0,
                                        //'ReportingCurrency' => '',
                                        //'UserData' => '',
                                        //'UserTranId' => 'string',
                                        //'SourceSystem' => '',
                                        'CustomString1' => $orderCustomString1 ? getCustomString($orderCustomString1->value, $data) : null,
                                        'CustomString2' => $orderCustomString2 ? getCustomString($orderCustomString2->value, $data) : null,
                                        'CustomString3' => $orderCustomString3 ? getCustomString($orderCustomString3->value, $data) : null,
                                        'CustomNumeric1' => $orderCustomNumeric1 ? getCustomNumeric($orderCustomNumeric1->value, $data) : null,
                                        'CustomNumeric2' => $orderCustomNumeric2 ? getCustomNumeric($orderCustomNumeric2->value, $data) : null,
                                        'CustomNumeric3' => $orderCustomNumeric3 ? getCustomNumeric($orderCustomNumeric3->value, $data) : null,
                                        //'DebugInd' => null,
                                        //'CalculationMethod' => null,
                                    ],
                                ];
                                $avalaraUrl = $isProductionRequest ? 'excise.avalara.net' : 'excisesbx.avalara.com';
                                $response = $client->post('https://' . $avalaraUrl . '/api/v1/AvaTaxExcise/transactions/' . $transactionCode . '/adjust', [
                                    'auth' => [
                                        $apiUsername, $apiUserPassword
                                    ],
                                    'headers' => $headers,
                                    'json' => $requestDataAdjust
                                ]);

                                if ($isExistsRefundedMeta) {
                                    $refuned_items = explode(",", $refundedMetaItems);
                                    $refuned_items = array_unique(array_merge($refuned_items, $variantIds));
                                    $refuned_items = implode(",", $refuned_items);

                                    $parameters['key'] = 'refuned_items';
                                    $parameters['value'] = $refuned_items;
                                    $parameters['resource_id'] = $refundedMetaItemsId;
                                    metafieldsUpdate($shop, $parameters);

                                    $fulfilledMetaItems = explode(",", $fulfilledMetaItems);
                                    $parameters['key'] = 'fulfilled_items';
                                    $parameters['value'] = "0";//implode(",",array_values(array_diff($fulfilledMetaItems, $variantIds)));
                                    $parameters['resource_id'] = $fulfilledMetaItemsId;
                                    metafieldsUpdate($shop, $parameters);
                                } else {
                                    $parameters['what'] = 'orders';
                                    $parameters['resource_id'] = $data->id;
                                    $parameters['key'] = 'refuned_items';
                                    $parameters['value'] = implode(",", $variantIds);
                                    metafieldsCreate($shop, $parameters);

                                    $fulfilledMetaItems = explode(",", $fulfilledMetaItems);
                                    $parameters['key'] = 'fulfilled_items';
                                    $parameters['value'] = "0";//implode(",", array_values(array_diff($fulfilledMetaItems, $variantIds)));
                                    $parameters['resource_id'] = $fulfilledMetaItemsId;
                                    metafieldsUpdate($shop, $parameters);
                                }

                                $resData = json_decode($response->getBody());
                                DB::table('avalara_transaction_log')->insert([
                                    "order_id" => $data->id,
                                    "order_number" => $data->order_number,
                                    "request_data" => json_encode($requestDataAdjust),
                                    "response_data" => json_encode($resData),
                                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }*/
                }
            }
        }
    }
}
