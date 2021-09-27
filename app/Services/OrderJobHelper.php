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
            if ($isOrderCreate) {
                $failoverCheckout = FailoverCheckout::where('shop_id', $shop->id)->get();
            }

            foreach ($data->note_attributes as $noteAttribute) {
                if ($isOrderCreate) {
                    if ($noteAttribute->name === 'checkout_failure' && $noteAttribute->value === 'true') {

                        if (isset($failoverCheckout)) {
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
                }
            }
        }
    }
}
