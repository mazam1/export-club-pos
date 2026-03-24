<?php return array (
  'App\\Providers\\EventServiceProvider' => 
  array (
    'Illuminate\\Auth\\Events\\Registered' => 
    array (
      0 => 'Illuminate\\Auth\\Listeners\\SendEmailVerificationNotification',
    ),
    'App\\Events\\SaleCreated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateSaleJournal',
    ),
    'App\\Events\\PurchaseCreated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePurchaseJournal',
    ),
    'App\\Events\\ExpenseCreated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateExpenseJournal',
    ),
    'App\\Events\\SaleReturned' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateSaleReturnJournal',
    ),
    'App\\Events\\SaleUpdated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateSaleAdjustment',
    ),
    'App\\Events\\PurchaseUpdated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePurchaseAdjustment',
    ),
    'App\\Events\\ExpenseUpdated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateExpenseAdjustment',
    ),
    'App\\Events\\SaleDeleted' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateSaleReversal',
    ),
    'App\\Events\\PurchaseDeleted' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePurchaseReversal',
    ),
    'App\\Events\\ExpenseDeleted' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GenerateExpenseReversal',
    ),
    'App\\Events\\PaymentCreated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePaymentSaleJournal',
    ),
    'App\\Events\\PaymentPurchaseCreated' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePaymentPurchaseJournal',
    ),
    'App\\Events\\PaymentDeleted' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePaymentSaleReversal',
    ),
    'App\\Events\\PaymentPurchaseDeleted' => 
    array (
      0 => 'App\\Listeners\\AccountingV2\\GeneratePaymentPurchaseReversal',
    ),
    'Laravel\\Passport\\Events\\AccessTokenCreated' => 
    array (
      0 => 'App\\Listeners\\Security\\RecordLoginSession',
    ),
  ),
);