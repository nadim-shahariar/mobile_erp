<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;

class TransferMemoController extends Controller
{
    public function __invoke(StockTransfer $transfer)
    {
        $this->authorize('view', $transfer);
        $transfer->load('fromUnit', 'toUnit', 'requester', 'receiver', 'originalTransfer', 'lines.serializedUnit.variant.product.brand', 'lines.variant.product.brand');

        return view('transfers.memo', ['transfer' => $transfer, 'company' => config('erp.company')]);
    }
}
