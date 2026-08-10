@extends('preinvoice.create', [
    'order' => $order,
    'shippingMethods' => $shippingMethods,
    'canFinanceApprove' => $canFinanceApprove ?? false,
    'canEditItems' => $canEditItems ?? true,
    'isEdit' => true,
])
